import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { TrainingSlot } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { fromIsoDate } from '@/utils/week';

/**
 * Entraînement (créneau occasionnel) lié à un événement ou intégré à un article : une carte cliquable qui ouvre le
 * détail du créneau (même écran que depuis l'onglet Entraînements). Pour un créneau sur inscription, indique les
 * places prises et mon inscription — l'inscription elle-même se fait dans le détail du créneau.
 */
export function LinkedTrainingSlotCard({ slot }: { slot: TrainingSlot }) {
  const router = useRouter();

  return (
    <Pressable
      onPress={() => router.push({ pathname: '/training-slot', params: { slot: JSON.stringify(slot) } })}
      style={({ pressed }) => [styles.card, pressed && { opacity: 0.75 }]}
      accessibilityLabel={`Ouvrir le créneau d'entraînement : ${slot.title || slot.sportLabel}`}
    >
      <Text style={styles.icon}>{slot.sportIcon}</Text>
      <View style={{ flex: 1 }}>
        <Text style={styles.kicker}>Entraînement</Text>
        <Text style={[styles.title, slot.isCancelled && styles.cancelled]} numberOfLines={1}>
          {slot.title || slot.sportLabel}
        </Text>
        <Text style={styles.meta} numberOfLines={1}>
          {fromIsoDate(slot.date).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}
          {' · '}{slot.startTime}
          {slot.location ? ` · ${slot.location}` : ''}
        </Text>
        {slot.isCancelled && <Text style={styles.cancelledLabel}>Créneau annulé</Text>}
        {!slot.isCancelled && slot.registration && (
          <Text style={styles.meta} numberOfLines={1}>
            ✍️ Sur inscription · {slot.registration.count}{slot.registration.capacity !== null ? `/${slot.registration.capacity}` : ''}
            {slot.registration.mine ? ' · ✅ Vous êtes inscrit(e)' : slot.registration.full ? ' · Complet' : ''}
          </Text>
        )}
      </View>
      <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.md,
    paddingVertical: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    marginBottom: SPACING.md,
  },
  icon: { fontSize: 26 },
  kicker: {
    fontSize: 11, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5,
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text, marginTop: 2 },
  cancelled: { textDecorationLine: 'line-through', color: COLORS.textMuted },
  meta: { fontSize: 13, color: COLORS.textMuted, marginTop: 2 },
  cancelledLabel: { fontSize: 12, fontWeight: '700', color: '#991B1B', marginTop: 2 },
});
