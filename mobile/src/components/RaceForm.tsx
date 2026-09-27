import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import type { RaceProposalInput, RaceType } from '@/api/types';
import { DateField, formatIsoDate, todayIso } from '@/components/DateField';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Types de course : libellé, emoji et couleur (liste, détail, formulaire). */
export const RACE_TYPES: { value: RaceType; label: string; emoji: string; color: string }[] = [
  { value: 'triathlon', label: 'Triathlon', emoji: '🏊', color: '#1d4ed8' },
  { value: 'trail', label: 'Trail', emoji: '⛰️', color: '#15803d' },
  { value: 'route', label: 'Course à pied sur route', emoji: '🏃', color: '#c2410c' },
  { value: 'cyclosportive', label: 'Cyclosportive', emoji: '🚴', color: '#b91c1c' },
  { value: 'eau_libre', label: 'Eau libre', emoji: '🌊', color: '#0e7490' },
  { value: 'autre', label: 'Autre', emoji: '🏅', color: '#6b21a8' },
];

export function raceTypeMeta(type: RaceType) {
  return RACE_TYPES.find((t) => t.value === type) ?? RACE_TYPES[RACE_TYPES.length - 1];
}

export const formatRaceDate = formatIsoDate;

/**
 * Formulaire commun création / édition d'une course proposée : nom,
 * date, site Internet, type, case « Je me propose d'être capitaine ».
 */
export function RaceForm({ initial, submitLabel, onSubmit, onCancel }: {
  initial?: RaceProposalInput;
  submitLabel: string;
  onSubmit: (input: RaceProposalInput) => Promise<void>;
  onCancel: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? '');
  const [isoDate, setIsoDate] = useState<string | null>(initial?.raceDate ?? null);
  const [url, setUrl] = useState(initial?.url ?? '');
  const [type, setType] = useState<RaceType | null>(initial?.type ?? null);
  const [captain, setCaptain] = useState(initial?.captain ?? false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSubmit = !busy && name.trim() !== '' && isoDate !== null && type !== null;

  async function submit() {
    setError(null);
    if (name.trim() === '') { setError('Le nom de la course ne peut pas être vide.'); return; }
    if (isoDate === null) { setError('Date invalide (JJ/MM/AAAA).'); return; }
    if (type === null) { setError('Choisissez le type de course.'); return; }
    setBusy(true);
    try {
      await onSubmit({ name: name.trim(), raceDate: isoDate, url: url.trim(), captain, type });
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
        value={name}
        onChangeText={setName}
        placeholder="Ex : Triathlon de Carcassonne"
        placeholderTextColor={COLORS.textSubtle}
        maxLength={150}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Date</Text>
      <DateField initialIso={initial?.raceDate} onChange={setIsoDate} disabled={busy} minIso={todayIso()} />

      <Text style={styles.label}>Site Internet (facultatif)</Text>
      <TextInput
        value={url}
        onChangeText={setUrl}
        placeholder="https://www.lacourse.fr"
        placeholderTextColor={COLORS.textSubtle}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        maxLength={500}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Type de course</Text>
      <View style={styles.typeGrid}>
        {RACE_TYPES.map((t) => {
          const active = type === t.value;
          return (
            <Pressable
              key={t.value}
              onPress={() => setType(t.value)}
              disabled={busy}
              style={({ pressed }) => [
                styles.typeChip,
                active && { backgroundColor: t.color, borderColor: t.color },
                pressed && { opacity: 0.7 },
              ]}
            >
              <Text style={styles.typeEmoji}>{t.emoji}</Text>
              <Text style={[styles.typeLabel, active && { color: '#fff' }]}>{t.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <Pressable
        onPress={() => setCaptain((c) => !c)}
        disabled={busy}
        style={({ pressed }) => [styles.captainBox, captain && styles.captainBoxActive, pressed && { opacity: 0.8 }]}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: captain }}
      >
        <Ionicons
          name={captain ? 'checkbox' : 'square-outline'}
          size={24}
          color={captain ? COLORS.primary : COLORS.textMuted}
        />
        <View style={{ flex: 1 }}>
          <Text style={styles.captainTitle}>Je me propose d'être capitaine</Text>
          <Text style={styles.captainHint}>
            Je prends contact avec l'organisateur pour pouvoir proposer une inscription groupée.
          </Text>
        </View>
      </Pressable>

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable
        onPress={submit}
        disabled={!canSubmit}
        style={[styles.button, !canSubmit && styles.buttonDisabled]}
      >
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
  typeGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  typeChip: {
    flexDirection: 'row', alignItems: 'center', gap: 6,
    paddingHorizontal: 12, paddingVertical: 8,
    borderRadius: 20, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  typeEmoji: { fontSize: 15 },
  typeLabel: { fontSize: 13, fontWeight: '600', color: COLORS.text },
  captainBox: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    marginTop: SPACING.lg, padding: SPACING.md,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  captainBoxActive: { borderColor: COLORS.primary, backgroundColor: COLORS.primarySoft },
  captainTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  captainHint: { fontSize: 12, color: COLORS.textMuted, marginTop: 2, lineHeight: 17 },
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
