import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import { events as eventsApi } from '@/api/resources';
import type { AttendanceStatus, EventItem } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Barre des 3 boutons de vote de présence sur un événement.
 *
 *  - « J'y serai / Peut-être / Pas là » (comportement standard).
 *  - Si `event.externalRegistrationUrl` est renseignée, le premier bouton
 *    devient « Je m'inscris » qui vote « yes » ET ouvre l'URL externe.
 *
 * Re-tap sur le vote actif → l'efface (undo). Optimistic UI : le state
 * change immédiatement, rollback en cas d'erreur réseau.
 *
 * Deux modes d'affichage :
 *  - `size="sm"` (défaut) : compact, adapté aux cards de la home.
 *  - `size="lg"`           : plus grand, adapté à une page détail.
 */
export function EventVoteBar({ event, size = 'sm' }: { event: EventItem; size?: 'xs' | 'sm' | 'lg' }) {
  const [myVote, setMyVote] = useState<AttendanceStatus | null>(event.myVote);
  const [voting, setVoting] = useState(false);

  /**
   * @param allowUnvote  true (défaut) = re-tap sur le vote actif l'efface
   *                     (comportement standard). false = ne toggle jamais,
   *                     un reclick reste sur ce statut (utilisé pour
   *                     « Je m'inscris » : l'user doit pouvoir re-ouvrir
   *                     l'URL externe sans perdre son vote « oui »).
   */
  async function castVote(next: AttendanceStatus, allowUnvote = true) {
    if (voting) return;
    const target: AttendanceStatus | null =
      allowUnvote && myVote === next ? null : next;
    // Optimisation : si aucun changement à envoyer (déjà à cette valeur
    // avec allowUnvote=false), on ne fait pas de round-trip inutile.
    if (target === myVote) return;
    setVoting(true);
    const previous = myVote;
    setMyVote(target);
    try {
      await eventsApi.setAttendance(event.id, target);
    } catch {
      setMyVote(previous);
    } finally {
      setVoting(false);
    }
  }

  const s = size === 'lg' ? largeStyles : size === 'xs' ? xsStyles : smallStyles;
  const iconOnly = size === 'xs';

  return (
    <View style={s.bar}>
      {event.externalRegistrationUrl ? (
        <VoteBtn
          label="Je m'inscris"
          shortLabel="Inscription"
          icon="open-outline"
          active={myVote === 'yes'}
          disabled={voting}
          onPress={() => {
            // Pas de toggle : un reclick maintient l'engagement « oui »
            // et ré-ouvre l'URL pour finaliser l'inscription. Les autres
            // boutons (Peut-être / Pas là) restent exclusifs et
            // désactivent bien le vert.
            void castVote('yes', /* allowUnvote */ false);
            void Linking.openURL(event.externalRegistrationUrl!);
          }}
          accent={COLORS.success}
          styles={s}
          iconOnly={iconOnly}
        />
      ) : (
        <VoteBtn label="J'y serai" shortLabel="Oui" icon="checkmark" active={myVote === 'yes'} disabled={voting}
          onPress={() => castVote('yes')} accent={COLORS.success} styles={s} iconOnly={iconOnly} />
      )}
      <VoteBtn label="Peut-être" shortLabel="?" icon="help" active={myVote === 'maybe'} disabled={voting}
        onPress={() => castVote('maybe')} accent={COLORS.warning ?? COLORS.textMuted} styles={s} iconOnly={iconOnly} />
      <VoteBtn label="Pas là" shortLabel="Non" icon="close" active={myVote === 'no'} disabled={voting}
        onPress={() => castVote('no')} accent={COLORS.error} styles={s} iconOnly={iconOnly} />
    </View>
  );
}

function VoteBtn({
  label, shortLabel, icon, active, disabled, onPress, accent, styles: s, iconOnly,
}: {
  label: string;
  shortLabel: string;
  icon: 'checkmark' | 'help' | 'close' | 'open-outline';
  active: boolean;
  disabled: boolean;
  onPress: () => void;
  accent: string;
  styles: { btn: object; label: object };
  iconOnly?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityLabel={label}
      style={({ pressed }) => [
        s.btn,
        active && { backgroundColor: accent, borderColor: accent },
        pressed && { opacity: 0.7 },
      ]}
    >
      <Ionicons name={icon} size={active ? 16 : 14} color={active ? '#fff' : accent} />
      {!iconOnly && (
        <Text style={[s.label, { color: active ? '#fff' : accent }]} numberOfLines={1}>
          {label}
        </Text>
      )}
    </Pressable>
  );
}

// xs = icon only, compact — pour tenir sur la même ligne que le titre
// (« Prochainement » sur la home). Boutons carrés ~28×28.
const xsStyles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    gap: 4,
  },
  btn: {
    width: 30,
    height: 30,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 6,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: '#fff',
  },
  label: { fontSize: 0 }, // unused (iconOnly)
});

const smallStyles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    gap: 6,
    paddingHorizontal: SPACING.md,
    paddingBottom: SPACING.sm,
    paddingTop: 4,
  },
  btn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingVertical: 7,
    paddingHorizontal: 8,
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: '#fff',
  },
  label: { fontSize: 12, fontWeight: '600' },
});

const largeStyles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: SPACING.md,
  },
  btn: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: 14,
    paddingHorizontal: 10,
    borderRadius: RADIUS.md,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  label: { fontSize: 14, fontWeight: '700' },
});
