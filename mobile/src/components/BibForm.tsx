import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { BibExchangeType, BibOffer, BibOfferInput } from '@/api/types';
import { DateField, todayIso } from '@/components/DateField';
import { COLORS, RADIUS, SPACING } from '@/config';

const MAX_QUANTITY = 50;

/** « Don », « 25 € / dossard », « 25,50 € / dossard · à négocier », « Prix à négocier ». */
export function bibPriceLabel(o: Pick<BibOffer, 'exchangeType' | 'unitPriceCents' | 'negotiable'>): string {
  if (o.exchangeType === 'don') return 'Don gratuit';
  if (o.unitPriceCents === null) return 'Prix à négocier';
  const euros = o.unitPriceCents / 100;
  const price = euros.toLocaleString('fr-FR', {
    minimumFractionDigits: Number.isInteger(euros) ? 0 : 2,
    maximumFractionDigits: 2,
  }) + ' € / dossard';
  return o.negotiable ? price + ' · à négocier' : price;
}

/** Centimes → « 25 » / « 25,50 » pour pré-remplir le champ prix. */
export function centsToInput(cents: number | null): string {
  if (cents === null) return '';
  const euros = cents / 100;
  return Number.isInteger(euros) ? String(euros) : euros.toFixed(2).replace('.', ',');
}

/**
 * Formulaire commun création / édition d'une offre de dossards : course,
 * date, nombre de dossards, don ou revente (prix unitaire et/ou « à
 * négocier »), précisions facultatives.
 */
export function BibForm({ initial, submitLabel, onSubmit, onCancel }: {
  initial?: BibOfferInput;
  submitLabel: string;
  onSubmit: (input: BibOfferInput) => Promise<void>;
  onCancel: () => void;
}) {
  const [raceName, setRaceName] = useState(initial?.raceName ?? '');
  const [raceDate, setRaceDate] = useState<string | null>(initial?.raceDate ?? null);
  const [quantity, setQuantity] = useState(initial?.quantity ?? 1);
  const [exchangeType, setExchangeType] = useState<BibExchangeType>(initial?.exchangeType ?? 'don');
  const [unitPrice, setUnitPrice] = useState(initial?.unitPrice ?? '');
  const [negotiable, setNegotiable] = useState(initial?.negotiable ?? false);
  const [description, setDescription] = useState(initial?.description ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isResale = exchangeType === 'revente';
  const priceValid = unitPrice.trim() === '' || /^\d+([.,]\d{1,2})?$/.test(unitPrice.trim());
  const priceOk = !isResale || (priceValid && (unitPrice.trim() !== '' || negotiable));
  const canSubmit = !busy && raceName.trim() !== '' && raceDate !== null && priceOk;

  async function submit() {
    setError(null);
    if (raceName.trim() === '') { setError('Le nom de la course ne peut pas être vide.'); return; }
    if (raceDate === null) { setError('Date invalide (JJ/MM/AAAA).'); return; }
    if (isResale && !priceValid) { setError('Prix unitaire invalide (ex : 25 ou 25,50).'); return; }
    if (isResale && unitPrice.trim() === '' && !negotiable) {
      setError('Indiquez un prix unitaire ou cochez « à négocier ».');
      return;
    }
    setBusy(true);
    try {
      await onSubmit({
        raceName: raceName.trim(),
        raceDate,
        quantity,
        exchangeType,
        unitPrice: isResale ? unitPrice.trim() : '',
        negotiable: isResale && negotiable,
        description: description.trim(),
      });
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View>
      <Text style={styles.label}>Nom de la course</Text>
      <TextInput
        value={raceName}
        onChangeText={setRaceName}
        placeholder="Ex : Marathon de Toulouse"
        placeholderTextColor={COLORS.textSubtle}
        maxLength={150}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Date de la course</Text>
      <DateField initialIso={initial?.raceDate} onChange={setRaceDate} disabled={busy} minIso={todayIso()} />

      <Text style={styles.label}>Nombre de dossards disponibles</Text>
      <View style={styles.stepper}>
        <Pressable
          onPress={() => setQuantity((q) => Math.max(1, q - 1))}
          disabled={busy || quantity <= 1}
          style={[styles.stepBtn, (busy || quantity <= 1) && { opacity: 0.35 }]}
          accessibilityLabel="Un dossard de moins"
        >
          <Ionicons name="remove" size={20} color={COLORS.primary} />
        </Pressable>
        <Text style={styles.stepValue}>{quantity}</Text>
        <Pressable
          onPress={() => setQuantity((q) => Math.min(MAX_QUANTITY, q + 1))}
          disabled={busy || quantity >= MAX_QUANTITY}
          style={[styles.stepBtn, (busy || quantity >= MAX_QUANTITY) && { opacity: 0.35 }]}
          accessibilityLabel="Un dossard de plus"
        >
          <Ionicons name="add" size={20} color={COLORS.primary} />
        </Pressable>
      </View>

      <Text style={styles.label}>Type d'échange</Text>
      <View style={styles.segment}>
        {([
          { value: 'don', label: '🎁 Don' },
          { value: 'revente', label: '💶 Revente' },
        ] as const).map((opt) => {
          const active = exchangeType === opt.value;
          return (
            <Pressable
              key={opt.value}
              onPress={() => setExchangeType(opt.value)}
              disabled={busy}
              style={[styles.segmentBtn, active && styles.segmentBtnActive]}
            >
              <Text style={[styles.segmentLabel, active && styles.segmentLabelActive]}>{opt.label}</Text>
            </Pressable>
          );
        })}
      </View>

      {isResale && (
        <>
          <Text style={styles.label}>Prix de vente unitaire</Text>
          <View style={styles.priceRow}>
            <TextInput
              value={unitPrice}
              onChangeText={setUnitPrice}
              placeholder={negotiable ? 'Facultatif' : 'Ex : 25'}
              placeholderTextColor={COLORS.textSubtle}
              keyboardType="decimal-pad"
              maxLength={8}
              style={[styles.input, { flex: 1 }]}
              editable={!busy}
            />
            <Text style={styles.priceSuffix}>€ / dossard</Text>
          </View>

          <Pressable
            onPress={() => setNegotiable((n) => !n)}
            disabled={busy}
            style={styles.checkRow}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: negotiable }}
          >
            <Ionicons
              name={negotiable ? 'checkbox' : 'square-outline'}
              size={22}
              color={negotiable ? COLORS.primary : COLORS.textMuted}
            />
            <Text style={styles.checkLabel}>Prix à négocier</Text>
          </Pressable>
        </>
      )}

      <Text style={styles.label}>Précisions (facultatif)</Text>
      <TextInput
        value={description}
        onChangeText={setDescription}
        placeholder="Distance, sas de départ, modalités de transfert du dossard…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={1000}
        style={[styles.input, styles.textarea]}
        editable={!busy}
      />

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable onPress={submit} disabled={!canSubmit} style={[styles.button, !canSubmit && styles.buttonDisabled]}>
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>{submitLabel}</Text>}
      </Pressable>
      <Pressable onPress={onCancel} style={styles.backBtn} disabled={busy}>
        <Text style={styles.backBtnLabel}>Annuler</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.md },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  textarea: { minHeight: 100, textAlignVertical: 'top' },
  stepper: { flexDirection: 'row', alignItems: 'center', gap: SPACING.md },
  stepBtn: {
    width: 42, height: 42, borderRadius: 21,
    borderWidth: 1, borderColor: COLORS.primary,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.surface,
  },
  stepValue: { fontSize: 20, fontWeight: '700', color: COLORS.text, minWidth: 32, textAlign: 'center' },
  segment: {
    flexDirection: 'row', gap: 4, padding: 4,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
  },
  segmentBtn: { flex: 1, paddingVertical: 10, borderRadius: RADIUS.sm, alignItems: 'center' },
  segmentBtnActive: { backgroundColor: COLORS.primary },
  segmentLabel: { fontSize: 14, fontWeight: '700', color: COLORS.textMuted },
  segmentLabelActive: { color: '#fff' },
  priceRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  priceSuffix: { fontSize: 14, color: COLORS.textMuted, fontWeight: '600' },
  checkRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: SPACING.sm, paddingVertical: 4 },
  checkLabel: { fontSize: 14, color: COLORS.text, fontWeight: '600' },
  error: {
    color: COLORS.error,
    backgroundColor: '#fee2e2',
    padding: 12,
    borderRadius: RADIUS.sm,
    marginTop: SPACING.md,
    fontSize: 13, fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.lg,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
