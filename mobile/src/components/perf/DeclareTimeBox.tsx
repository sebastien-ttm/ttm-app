import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { perfTests as perfTestsApi } from '@/api/resources';
import type { PerfTestDeclaration } from '@/api/types';
import { DateField, todayIso } from '@/components/DateField';
import { COLORS, RADIUS, SPACING } from '@/config';
import { fromIsoDate } from '@/utils/week';

/** Épreuve imposée (sous-onglet d'une épreuve) : `pool` = longueur du bassin déjà connue (natation), sinon null. */
export type DeclareTarget = { test: string; label: string; pool: number | null };

/** Épreuves déclarables (mêmes clés que l'enum PerfTest du back-end) — pour le choix quand aucune n'est imposée. */
const EPREUVES = [
  { test: 'run_1500', label: '1500 m course à pied', short: '🏃 1500 m' },
  { test: 'run_2000', label: '2000 m course à pied', short: '🏃 2000 m' },
  { test: 'swim_400', label: '400 m natation', short: '🏊 400 m nage' },
  { test: 'bike_climb_2k', label: 'Montée 2 km vélo', short: '🚴 Montée 2 km' },
] as const;
const POOLS = [25, 50] as const;

/** Début (YYYY-MM-DD) de la saison d'entraînement en cours : le 1er septembre. */
export function currentSeasonStartIso(now: Date = new Date()): string {
  const year = now.getMonth() >= 8 ? now.getFullYear() : now.getFullYear() - 1;
  return `${year}-09-01`;
}

function dateLabel(iso: string): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Déclaration d'un temps pris individuellement : l'adhérent indique l'épreuve, la date et son temps ; les
 * entraîneurs l'acceptent (il rejoint alors les classements) ou le refusent depuis le back-end. Sous le formulaire,
 * mes demandes avec leur état.
 *
 *  - `target` : épreuve imposée (onglet d'une épreuve) ; sans, l'adhérent choisit l'épreuve (et le bassin en natation).
 *  - `currentSeasonOnly` : la prise de temps doit dater de la saison en cours (depuis le 1er septembre).
 */
export function DeclareTimeBox({
  target, declarations, onChanged, currentSeasonOnly = false,
}: {
  target?: DeclareTarget;
  declarations: PerfTestDeclaration[];
  onChanged: () => Promise<void> | void;
  currentSeasonOnly?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [test, setTest] = useState<string | null>(null);
  const [pool, setPool] = useState<number | null>(null);
  const [date, setDate] = useState<string | null>(todayIso());
  const [time, setTime] = useState('');
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [cancellingId, setCancellingId] = useState<number | null>(null);

  const chosenTest = target?.test ?? test;
  const label = target?.label ?? EPREUVES.find((e) => e.test === chosenTest)?.label ?? null;
  // Natation : le bassin (25 / 50 m) est obligatoire — imposé par l'onglet, sinon choisi ici.
  const isSwim = chosenTest === 'swim_400';
  const chosenPool = target ? target.pool : pool;
  const seasonStart = currentSeasonStartIso();

  const canSend = !sending && date !== null && time.trim() !== '' && chosenTest !== null && (!isSwim || chosenPool !== null);

  async function send() {
    if (!canSend || date === null || chosenTest === null) return;
    if (currentSeasonOnly && date < seasonStart) {
      setError('La date doit être dans la saison en cours (depuis le 1er septembre).');
      return;
    }
    setSending(true);
    setError(null);
    try {
      await perfTestsApi.declare({
        test: chosenTest,
        poolLength: isSwim ? chosenPool : null,
        date,
        time: time.trim(),
        comment: comment.trim() || undefined,
      });
      setSent(true);
      setOpen(false);
      setTime('');
      setComment('');
      await onChanged();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Envoi impossible. Réessayez plus tard.');
    } finally {
      setSending(false);
    }
  }

  async function cancel(id: number) {
    setCancellingId(id);
    try {
      await perfTestsApi.cancelDeclaration(id);
      await onChanged();
    } catch {
      // Déjà traitée entre-temps : le rechargement affichera son état réel.
      await onChanged();
    } finally {
      setCancellingId(null);
    }
  }

  return (
    <View style={styles.wrap}>
      {open ? (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>⏱️ Déclarer mon temps{target ? ` — ${target.label}` : ''}</Text>
          <Text style={styles.hint}>
            Vous avez chronométré votre temps vous-même ? Indiquez-le : les entraîneurs le valident avant
            qu'il n'apparaisse dans les classements.
          </Text>

          {!target && (
            <>
              <Text style={styles.fieldLabel}>Épreuve</Text>
              <View style={styles.chips}>
                {EPREUVES.map((e) => {
                  const active = test === e.test;
                  return (
                    <Pressable
                      key={e.test}
                      onPress={() => setTest(e.test)}
                      disabled={sending}
                      accessibilityRole="radio"
                      accessibilityState={{ selected: active }}
                      style={[styles.chip, active && styles.chipActive]}
                    >
                      <Text style={[styles.chipLabel, active && styles.chipLabelActive]}>{e.short}</Text>
                    </Pressable>
                  );
                })}
              </View>
              {isSwim && (
                <>
                  <Text style={styles.fieldLabel}>Longueur du bassin</Text>
                  <View style={styles.chips}>
                    {POOLS.map((p) => {
                      const active = pool === p;
                      return (
                        <Pressable
                          key={p}
                          onPress={() => setPool(p)}
                          disabled={sending}
                          accessibilityRole="radio"
                          accessibilityState={{ selected: active }}
                          style={[styles.chip, active && styles.chipActive]}
                        >
                          <Text style={[styles.chipLabel, active && styles.chipLabelActive]}>{p} m</Text>
                        </Pressable>
                      );
                    })}
                  </View>
                </>
              )}
            </>
          )}

          <Text style={styles.fieldLabel}>Date de la prise de temps</Text>
          <DateField initialIso={todayIso()} onChange={setDate} disabled={sending} minIso={currentSeasonOnly ? seasonStart : undefined} />

          <Text style={styles.fieldLabel}>Mon temps{label && !target ? ` — ${label}` : ''}</Text>
          <TextInput
            value={time}
            onChangeText={setTime}
            placeholder="5:42  (min:s)  ou  1:02:15  (h:min:s)"
            placeholderTextColor={COLORS.textSubtle}
            autoCapitalize="none"
            autoCorrect={false}
            maxLength={12}
            editable={!sending}
            style={styles.input}
          />

          <Text style={styles.fieldLabel}>Précisions (facultatif)</Text>
          <TextInput
            value={comment}
            onChangeText={setComment}
            placeholder="Lieu, chronométreur, conditions…"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={500}
            editable={!sending}
            style={[styles.input, { minHeight: 70, textAlignVertical: 'top' }]}
          />

          {error && <Text style={styles.error}>{error}</Text>}
          <View style={styles.actions}>
            <Pressable
              onPress={() => { setOpen(false); setError(null); }}
              disabled={sending}
              style={({ pressed }) => [styles.cancelBtn, pressed && { opacity: 0.7 }]}
            >
              <Text style={styles.cancelBtnLabel}>Annuler</Text>
            </Pressable>
            <Pressable
              onPress={send}
              disabled={!canSend}
              style={({ pressed }) => [styles.sendBtn, !canSend && { opacity: 0.5 }, pressed && { opacity: 0.8 }]}
            >
              {sending ? <ActivityIndicator color="#fff" /> : <Text style={styles.sendBtnLabel}>Envoyer la demande</Text>}
            </Pressable>
          </View>
        </View>
      ) : (
        <Pressable
          onPress={() => { setOpen(true); setSent(false); setDate(todayIso()); }}
          accessibilityRole="button"
          style={({ pressed }) => [styles.linkBox, pressed && { opacity: 0.7 }]}
        >
          <Ionicons name="timer-outline" size={18} color={COLORS.secondaryDark} />
          <Text style={[styles.link, { flex: 1 }]}>Prise de temps individuelle ? Déclarer mon temps</Text>
          <Ionicons name="chevron-forward" size={16} color={COLORS.secondaryDark} />
        </Pressable>
      )}

      {sent && !open && (
        <View style={styles.sentBox}>
          <Text style={styles.sentLabel}>✅ Demande envoyée aux entraîneurs : elle apparaîtra dans les classements une fois acceptée.</Text>
        </View>
      )}

      {declarations.length > 0 && (
        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Mes demandes de temps</Text>
          {declarations.slice(0, 5).map((d) => (
            <View key={d.id} style={styles.declRow}>
              <View style={{ flex: 1 }}>
                <Text style={styles.declDate}>
                  {target ? '' : `${d.label}${d.poolLength ? ` (bassin ${d.poolLength} m)` : ''} · `}{dateLabel(d.date)} · {d.time}
                </Text>
                <Text
                  style={[
                    styles.declMeta,
                    d.status === 'accepted' && { color: COLORS.success },
                    d.status === 'rejected' && { color: COLORS.error },
                  ]}
                >
                  {d.status === 'pending' && '⏳ En attente de validation'}
                  {d.status === 'accepted' && '✅ Acceptée — visible dans les classements'}
                  {d.status === 'rejected' && `❌ Refusée${d.decisionNote ? ` — ${d.decisionNote}` : ''}`}
                </Text>
              </View>
              {d.status === 'pending' && (
                <Pressable
                  onPress={() => cancel(d.id)}
                  disabled={cancellingId === d.id}
                  hitSlop={8}
                  style={({ pressed }) => [pressed && { opacity: 0.6 }]}
                >
                  <Text style={styles.declCancel}>{cancellingId === d.id ? '…' : 'Annuler'}</Text>
                </Pressable>
              )}
            </View>
          ))}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: SPACING.md },
  card: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.lg, gap: SPACING.md, borderWidth: 1, borderColor: COLORS.border },
  cardTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  hint: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  fieldLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text, marginTop: 2 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { paddingHorizontal: 14, paddingVertical: 9, borderRadius: 999, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border },
  chipActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  chipLabel: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  chipLabelActive: { color: '#fff' },
  input: {
    borderWidth: 1, borderColor: COLORS.border, borderRadius: RADIUS.md,
    padding: SPACING.md, fontSize: 15, color: COLORS.text, backgroundColor: COLORS.surfaceAlt,
  },
  error: { fontSize: 13, color: COLORS.error },
  actions: { flexDirection: 'row', gap: SPACING.sm, justifyContent: 'flex-end' },
  cancelBtn: { paddingHorizontal: 14, paddingVertical: 11, borderRadius: RADIUS.md },
  cancelBtnLabel: { fontSize: 14, fontWeight: '600', color: COLORS.textMuted },
  sendBtn: {
    paddingHorizontal: 16, paddingVertical: 11, borderRadius: RADIUS.md,
    backgroundColor: COLORS.primary, minWidth: 150, alignItems: 'center',
  },
  sendBtnLabel: { fontSize: 14, fontWeight: '700', color: '#fff' },
  linkBox: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: COLORS.secondarySoft,
  },
  link: { fontSize: 14, fontWeight: '600', color: COLORS.secondaryDark },
  sentBox: { padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#d1fae5', gap: SPACING.xs },
  sentLabel: { fontSize: 14, fontWeight: '600', color: '#065f46' },
  declRow: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border,
  },
  declDate: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  declMeta: { fontSize: 12, color: COLORS.textMuted },
  declCancel: { fontSize: 13, fontWeight: '600', color: COLORS.error },
});
