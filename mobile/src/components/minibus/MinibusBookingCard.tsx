import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { MinibusBooking } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { MINIBUS_STATUS_STYLE, formatBookingRange, stepToDo } from '@/utils/minibus';

/**
 * Carte d'une demande d'emprunt : dates, emprunteur, raison, état (pastille), remorque, problème signalé et — pour ses
 * propres emprunts validés — la prochaine chose à faire (charte, état des lieux), mise en évidence.
 */
export function MinibusBookingCard({ booking, showRequester = true, onPress }: {
  booking: MinibusBooking;
  /** Masqué quand la liste ne contient que ses propres demandes. */
  showRequester?: boolean;
  onPress: () => void;
}) {
  const s = MINIBUS_STATUS_STYLE[booking.status];
  const todo = booking.isMine ? stepToDo(booking.step) : null;
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, pressed && { opacity: 0.8 }]} accessibilityRole="button">
      <View style={{ flex: 1, gap: 4 }}>
        <View style={styles.row}>
          <Text style={styles.dates}>{formatBookingRange(booking)}</Text>
          <View style={[styles.pill, { backgroundColor: s.bg }]}>
            <Text style={[styles.pillText, { color: s.fg }]}>{booking.status === 'approved' && booking.step === 'done' ? 'Terminé' : booking.statusLabel}</Text>
          </View>
        </View>
        {showRequester && <Text style={styles.who}>{booking.requesterName}{booking.isMine ? ' (moi)' : ''}</Text>}
        <Text style={styles.reason} numberOfLines={2}>{booking.reason}</Text>
        <View style={styles.row}>
          {booking.withTrailer && (
            <View style={[styles.pill, { backgroundColor: COLORS.secondarySoft }]}>
              <Text style={[styles.pillText, { color: COLORS.secondaryDark }]}>Avec remorque</Text>
            </View>
          )}
          {booking.hasProblem && (
            <View style={[styles.pill, { backgroundColor: '#fee2e2' }]}>
              <Text style={[styles.pillText, { color: '#991b1b' }]}>⚠ Problème signalé</Text>
            </View>
          )}
        </View>
        {todo && (
          <View style={styles.todo}>
            <Ionicons name="alert-circle" size={15} color="#92400e" />
            <Text style={styles.todoText}>{todo}</Text>
          </View>
        )}
      </View>
      <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  row: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6 },
  dates: { fontSize: 15, fontWeight: '700', color: COLORS.text, flexShrink: 1 },
  who: { fontSize: 13, fontWeight: '600', color: COLORS.brandNavy },
  reason: { fontSize: 13, color: COLORS.textMuted },
  pill: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  pillText: { fontSize: 11, fontWeight: '700' },
  todo: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#fef3c7', borderRadius: RADIUS.sm, paddingHorizontal: 8, paddingVertical: 5, alignSelf: 'flex-start' },
  todoText: { fontSize: 12, fontWeight: '700', color: '#92400e' },
});
