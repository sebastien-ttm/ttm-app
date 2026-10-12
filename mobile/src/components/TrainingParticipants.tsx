import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import type { TrainingParticipant } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * « Qui participe ? » d'un entraînement sur inscription : les inscrits, dans l'ordre d'inscription, avec leur
 * photo. Visible de tous ceux qui voient le créneau.
 */
export function TrainingParticipants({ participants, count, capacity }: {
  /** null = pas encore chargés. */
  participants: TrainingParticipant[] | null;
  count: number;
  capacity: number | null;
}) {
  const { user } = useAuth();

  return (
    <View style={styles.card}>
      <Text style={styles.title}>
        Qui participe ? ({count}{capacity !== null ? `/${capacity}` : ''})
      </Text>
      {participants === null ? (
        count > 0 ? <ActivityIndicator color={COLORS.secondary} style={{ marginVertical: SPACING.sm }} /> : null
      ) : participants.length === 0 ? (
        <Text style={styles.empty}>Personne ne s'est encore inscrit.</Text>
      ) : (
        participants.map((p) => (
          <View key={p.id} style={styles.row}>
            <MemberAvatar prenom={p.prenom} nom={p.nom} avatarUrl={p.avatarUrl} size={34} />
            <Text style={styles.name} numberOfLines={1}>
              {p.prenom} <Text style={styles.nom}>{p.nom.toUpperCase()}</Text>
              {p.id === user?.id ? <Text style={styles.me}>  (vous)</Text> : null}
            </Text>
          </View>
        ))
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
  },
  title: {
    fontSize: 13, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm,
  },
  empty: { fontSize: 13, color: COLORS.textMuted, paddingVertical: SPACING.sm },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 6 },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  me: { color: COLORS.textMuted, fontSize: 13 },
});
