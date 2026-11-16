import { StyleSheet, Text, View } from 'react-native';

import type { RaceProposal, RaceProposalStatus } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Mention affichée dès qu'une proposition est de type triathlon (formulaire, fiche en attente). */
export const FFTRI_NOTICE = 'Uniquement les événements affiliés à la FFTri seront acceptés.';

type StatusSource = Pick<RaceProposal, 'status'>;

/** Absent (ancienne version du serveur) = publiée. */
export function raceStatus(race: StatusSource): RaceProposalStatus {
  return race.status ?? 'validated';
}

/** Publiée = visible de tous les adhérents : validée, ou en pause. */
export function isRacePublished(race: StatusSource): boolean {
  const s = raceStatus(race);
  return s === 'validated' || s === 'paused';
}

/** Réponses ouvertes : seule une compétition validée (ni en attente, ni refusée, ni en pause) accepte réponses et inscriptions. */
export function raceAcceptsResponses(race: StatusSource): boolean {
  return raceStatus(race) === 'validated';
}

const BADGES: Partial<Record<RaceProposalStatus, { label: string; bg: string; fg: string }>> = {
  pending: { label: '⏳ En attente de validation', bg: '#fef3c7', fg: '#92400e' },
  paused: { label: '⏸ En pause', bg: COLORS.secondarySoft, fg: COLORS.secondaryDark },
  refused: { label: 'Non retenue', bg: '#fee2e2', fg: COLORS.error },
};

/** Pastille de statut d'une carte : rien pour une compétition validée. */
export function RaceStatusBadge({ race }: { race: StatusSource }) {
  const badge = BADGES[raceStatus(race)];
  if (!badge) return null;
  return (
    <View style={[styles.badge, { backgroundColor: badge.bg }]}>
      <Text style={[styles.badgeLabel, { color: badge.fg }]}>{badge.label}</Text>
    </View>
  );
}

/**
 * Encadré de la fiche d'une compétition qui n'est pas (ou plus) ouverte aux réponses : en attente de validation et
 * refusée (vues de l'auteur seul), ou en pause (vue de tous, avec le motif de l'administrateur s'il y en a un).
 */
export function RaceStatusNotice({ race }: { race: Pick<RaceProposal, 'status' | 'moderationNote' | 'type'> }) {
  const status = raceStatus(race);
  const badge = BADGES[status];
  if (!badge) return null;
  const note = race.moderationNote?.trim() ? race.moderationNote.trim() : null;

  return (
    <View style={[styles.box, { backgroundColor: badge.bg }]} accessibilityRole="alert">
      {status === 'pending' && (
        <>
          <Text style={[styles.title, { color: badge.fg }]}>⏳ En attente de validation</Text>
          <Text style={[styles.text, { color: badge.fg }]}>
            Vous seul(e) voyez cette proposition pour l'instant : un administrateur la relit avant de la publier aux
            adhérents. Vous serez prévenu(e) de sa décision.
          </Text>
          {race.type === 'triathlon' && <Text style={[styles.text, styles.bold, { color: badge.fg }]}>{FFTRI_NOTICE}</Text>}
        </>
      )}
      {status === 'refused' && (
        <>
          <Text style={[styles.title, { color: badge.fg }]}>Proposition non retenue</Text>
          {note && <Text style={[styles.text, { color: badge.fg }]}>Motif : {note}</Text>}
          {race.type === 'triathlon' && <Text style={[styles.text, styles.bold, { color: badge.fg }]}>{FFTRI_NOTICE}</Text>}
          <Text style={[styles.text, { color: badge.fg }]}>
            Vous pouvez la modifier : elle repartira alors en validation.
          </Text>
        </>
      )}
      {status === 'paused' && (
        <>
          <Text style={[styles.title, { color: badge.fg }]}>⏸ Compétition en pause</Text>
          <Text style={[styles.text, { color: badge.fg }]}>
            Les réponses, les inscriptions aux courses et l'ajout de courses sont suspendus pour le moment.
          </Text>
          {note && <Text style={[styles.text, styles.bold, { color: badge.fg }]}>{note}</Text>}
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  badge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: RADIUS.full },
  badgeLabel: { fontSize: 11, fontWeight: '700' },
  box: { marginTop: SPACING.md, padding: SPACING.md, borderRadius: RADIUS.md, gap: 4 },
  title: { fontSize: 14, fontWeight: '800' },
  text: { fontSize: 13, lineHeight: 19 },
  bold: { fontWeight: '700' },
});
