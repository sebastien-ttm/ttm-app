import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { BoardAction, BoardOpenAction } from '@/api/types';
import { LinkedText } from '@/components/LinkedText';
import { COLORS, RADIUS } from '@/config';
import { dueStatus, type DueTone } from '@/utils/boardDate';
import { poleTone } from '@/utils/boardPoles';

const TONES: Record<DueTone, { bg: string; fg: string }> = {
  late: { bg: '#fee2e2', fg: '#b91c1c' },
  soon: { bg: '#fef3c7', fg: '#92400e' },
  normal: { bg: '#f1f5f9', fg: COLORS.textMuted },
};

type Props = {
  action: BoardAction | BoardOpenAction;
  /** Affiche la réunion et le point concernés (liste des actions en cours). */
  showContext?: boolean;
  onPress?: () => void;
};

/**
 * Une action du Comité directeur : texte, échéance (rouge si en retard) et porteurs. Mise en avant (« Pour moi »)
 * quand le membre connecté en est l'un des porteurs et qu'elle est encore en cours ; grisée une fois clôturée.
 */
export function BoardActionRow({ action, showContext = false, onPress }: Props) {
  const focus = action.isMine && !action.closed;
  const due = action.dueDate && !action.closed ? dueStatus(action.dueDate, action.overdue) : null;
  const context = showContext && 'meetingLabel' in action ? action : null;

  const content = (
    <View style={[styles.row, focus && styles.rowFocus, action.closed && styles.rowClosed]}>
      <View style={styles.head}>
        {focus && (
          <View style={styles.mine}>
            <Ionicons name="person" size={11} color="#fff" />
            <Text style={styles.mineLabel}>Pour moi</Text>
          </View>
        )}
        {due && (
          <View style={[styles.due, { backgroundColor: TONES[due.tone].bg }]}>
            <Ionicons name="calendar-outline" size={11} color={TONES[due.tone].fg} />
            <Text style={[styles.dueLabel, { color: TONES[due.tone].fg }]}>{due.label}</Text>
          </View>
        )}
        {action.closed && (
          <View style={[styles.due, { backgroundColor: '#dcfce7' }]}>
            <Ionicons name="checkmark-circle" size={12} color="#166534" />
            <Text style={[styles.dueLabel, { color: '#166534' }]}>
              Clôturée{action.closedAt ? ' le ' + new Date(action.closedAt).toLocaleDateString('fr-FR') : ''}
            </Text>
          </View>
        )}
      </View>
      <LinkedText text={action.text} style={[styles.text, focus && styles.textFocus, action.closed && styles.textClosed]} />
      <Text style={styles.owners}>
        {action.assignees.length > 0 ? (action.assignees.length > 1 ? 'Porteurs : ' : 'Porteur : ') + action.assignees.join(', ') : 'Porteur à désigner'}
      </Text>
      {context && (
        <Text style={styles.context} numberOfLines={2}>
          {context.meetingLabel} · <Text style={{ color: poleTone(context.pole).ink, fontWeight: '700' }}>{context.poleLabel}</Text> — {context.pointText}
        </Text>
      )}
    </View>
  );

  if (!onPress) return content;
  return (
    <Pressable onPress={onPress} style={({ pressed }) => pressed && { opacity: 0.8 }}>
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { gap: 4, padding: 10, borderRadius: RADIUS.sm, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border },
  rowFocus: { backgroundColor: '#fffbeb', borderColor: COLORS.warning, borderWidth: 2 },
  rowClosed: { backgroundColor: COLORS.surfaceAlt },
  head: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6 },
  mine: { flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: '#d97706', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  mineLabel: { color: '#fff', fontSize: 11, fontWeight: '800' },
  due: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  dueLabel: { fontSize: 11.5, fontWeight: '700' },
  text: { fontSize: 14, color: COLORS.text, lineHeight: 19 },
  textFocus: { fontWeight: '700' },
  textClosed: { color: COLORS.textMuted, textDecorationLine: 'line-through' },
  owners: { fontSize: 12.5, color: COLORS.textMuted, fontWeight: '600' },
  context: { fontSize: 12, color: COLORS.textSubtle },
});
