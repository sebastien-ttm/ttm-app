import Ionicons from '@expo/vector-icons/Ionicons';
import { useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingRegistrations as registrationsApi } from '@/api/resources';
import type { TrainingRegistration, TrainingSlot } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { fromIsoDate } from '@/utils/week';

/**
 * Inscription à un entraînement sur inscription : places prises, bouton « S'inscrire » / « Se désinscrire », et
 * l'avertissement « une seule inscription par semaine » quand l'adhérent est déjà inscrit à un autre créneau de la
 * semaine. Contrôlé : l'état vient de `registration` (liste de la semaine ou détail du créneau) ; après un
 * changement, `onChanged` reçoit l'état à jour du créneau (null si le créneau n'est plus sur inscription).
 *
 *  - `size="sm"` : compact, dans une ligne de la liste de la semaine.
 *  - `size="lg"` : détail du créneau.
 */
export function TrainingRegistrationBar({
  slot,
  registration,
  onChanged,
  size = 'sm',
}: {
  slot: TrainingSlot;
  registration: TrainingRegistration;
  onChanged: (registration: TrainingRegistration | null) => void;
  size?: 'sm' | 'lg';
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lg = size === 'lg';
  const { mine, closed, full, count, capacity } = registration;
  // Anciennes réponses du serveur : pas de limit / others / blocked (une seule inscription, `other` renseigné si bloqué).
  const limit = registration.limit ?? 1;
  const others = registration.others ?? (registration.other ? [registration.other] : []);
  const blocked = registration.blocked ?? (registration.other != null);
  const places = capacity === null ? `${count} inscrit${count > 1 ? 's' : ''}` : `${count}/${capacity} places`;

  async function toggle() {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      const res = await registrationsApi.set(slot, !mine);
      onChanged(res.registration);
    } catch (e) {
      if (e instanceof ApiError) {
        setError(e.message);
        // Le refus porte l'état à jour (complet, déjà inscrit ailleurs…) : l'écran se resynchronise.
        const body = e.body as { registration?: TrainingRegistration | null } | null;
        if (body && body.registration) onChanged(body.registration);
      } else {
        setError('Inscription impossible pour le moment. Réessayez.');
      }
    } finally {
      setBusy(false);
    }
  }

  let action: ReactNode;
  if (mine) {
    action = closed ? (
      <Text style={[styles.mineText, lg && styles.textLg]}>✓ Vous êtes inscrit(e)</Text>
    ) : (
      <Pressable
        onPress={toggle}
        disabled={busy}
        accessibilityLabel="Se désinscrire"
        style={({ pressed }) => [styles.btn, lg && styles.btnLg, styles.btnMine, pressed && { opacity: 0.8 }]}
      >
        {busy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="checkmark" size={lg ? 18 : 14} color="#fff" />}
        <Text style={[styles.btnLabel, lg && styles.textLg]}>Inscrit(e) · Se désinscrire</Text>
      </Pressable>
    );
  } else if (closed) {
    action = <Text style={[styles.mutedText, lg && styles.textLg]}>Inscriptions closes</Text>;
  } else if (full) {
    action = <Text style={[styles.fullText, lg && styles.textLg]}>Complet</Text>;
  } else {
    // Limite d'inscriptions de la semaine atteinte : le bouton est visible mais inactif, avec l'avertissement ci-dessous.
    action = (
      <Pressable
        onPress={toggle}
        disabled={busy || blocked}
        accessibilityLabel="S'inscrire"
        style={({ pressed }) => [styles.btn, lg && styles.btnLg, styles.btnJoin, blocked && styles.btnBlocked, pressed && { opacity: 0.8 }]}
      >
        {busy ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="person-add" size={lg ? 18 : 14} color="#fff" />}
        <Text style={[styles.btnLabel, lg && styles.textLg]}>S'inscrire</Text>
      </Pressable>
    );
  }

  return (
    <View style={[styles.wrap, lg && styles.wrapLg]}>
      <View style={styles.row}>
        <Text style={[styles.places, lg && styles.placesLg, full && !mine && styles.placesFull]}>
          ✍️ Sur inscription · {places}
        </Text>
        {action}
      </View>
      {!mine && !closed && blocked && others.length > 0 && (
        <View style={styles.warning}>
          <Text style={styles.warningText}>
            {limit <= 1
              ? `⚠️ Une seule inscription par semaine : vous êtes déjà inscrit(e) à ${listOthers(others)}. Désinscrivez-vous de ce créneau pour vous inscrire ici.`
              : `⚠️ ${limit} inscriptions maximum par semaine : vous êtes déjà inscrit(e) à ${listOthers(others)}. Désinscrivez-vous de l'un d'eux pour vous inscrire ici.`}
          </Text>
        </View>
      )}
      {/* Semaine débloquée par un entraîneur : une 2e inscription est possible. */}
      {!mine && !closed && !full && !blocked && limit > 1 && others.length > 0 && (
        <View style={styles.info}>
          <Text style={styles.infoText}>
            🔓 Inscriptions débloquées cette semaine : vous pouvez vous inscrire à un 2e entraînement (déjà inscrit(e) à {listOthers(others)}).
          </Text>
        </View>
      )}
      {error !== null && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

/** « lundi 14 octobre à 18:30 » */
function describe(other: { date: string; startTime: string | null }): string {
  const day = fromIsoDate(other.date).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
  return other.startTime ? `${day} à ${other.startTime}` : day;
}

/** « « Natation » (lundi 14 octobre à 18:30) » ; plusieurs : reliés par « et ». */
function listOthers(others: { title: string; date: string; startTime: string | null }[]): string {
  return others.map((o) => `« ${o.title} » (${describe(o)})`).join(' et ');
}

const styles = StyleSheet.create({
  wrap: { marginTop: 6, gap: 6 },
  wrapLg: { marginTop: 4, gap: 8 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' },
  places: { fontSize: 12, fontWeight: '600', color: COLORS.secondaryDark, flexShrink: 1 },
  placesLg: { fontSize: 14 },
  placesFull: { color: COLORS.error },
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: RADIUS.sm,
  },
  btnLg: { paddingHorizontal: 16, paddingVertical: 11, borderRadius: RADIUS.md },
  btnJoin: { backgroundColor: COLORS.secondary },
  btnMine: { backgroundColor: COLORS.success },
  btnBlocked: { backgroundColor: COLORS.textSubtle },
  btnLabel: { color: '#fff', fontSize: 12, fontWeight: '700' },
  textLg: { fontSize: 14 },
  mineText: { fontSize: 12, fontWeight: '700', color: COLORS.success },
  mutedText: { fontSize: 12, fontWeight: '600', color: COLORS.textMuted },
  fullText: { fontSize: 12, fontWeight: '700', color: COLORS.error },
  warning: {
    backgroundColor: '#FEF3C7',
    borderRadius: RADIUS.sm,
    paddingHorizontal: SPACING.sm,
    paddingVertical: 6,
  },
  warningText: { fontSize: 12, color: '#92400E', lineHeight: 17 },
  info: {
    backgroundColor: '#ECFDF5',
    borderRadius: RADIUS.sm,
    paddingHorizontal: SPACING.sm,
    paddingVertical: 6,
  },
  infoText: { fontSize: 12, color: '#047857', lineHeight: 17 },
  errorText: { fontSize: 12, color: COLORS.error },
});
