import Ionicons from '@expo/vector-icons/Ionicons';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

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
  /**
   * Acquittement : fourni pour les actions dont le membre connecté est porteur. Bouton « Marquer comme faite » tant
   * que l'action est en cours, lien « Rouvrir » une fois faite.
   */
  onToggleDone?: () => void;
  /** Acquittement en cours (bouton désactivé, indicateur). */
  busy?: boolean;
};

/**
 * Une action du Comité directeur : texte, échéance (rouge si en retard) et porteurs. Mise en avant (« Pour moi »)
 * quand le membre connecté en est l'un des porteurs et qu'elle est encore en cours ; grisée et barrée une fois faite
 * (« Faite le … »). Ses porteurs l'acquittent d'un bouton ; les autres membres voient qu'elle est faite.
 */
export function BoardActionRow({ action, showContext = false, onPress, onToggleDone, busy = false }: Props) {
  const focus = action.isMine && !action.closed;
  const due = action.dueDate && !action.closed ? dueStatus(action.dueDate, action.overdue) : null;
  const context = showContext && 'meetingLabel' in action ? action : null;
  const canToggle = onToggleDone !== undefined && action.isMine;

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
              Faite{action.closedAt ? ' le ' + new Date(action.closedAt).toLocaleDateString('fr-FR') : ''}
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
      {canToggle && (
        action.closed ? (
          <Pressable onPress={onToggleDone} disabled={busy} accessibilityRole="button" hitSlop={8} style={styles.reopen}>
            <Text style={[styles.reopenLabel, busy && { opacity: 0.5 }]}>Rouvrir cette action</Text>
          </Pressable>
        ) : (
          <Pressable
            onPress={onToggleDone}
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel="Marquer cette action comme faite"
            style={({ pressed }) => [styles.done, (pressed || busy) && { opacity: 0.7 }]}
          >
            {busy ? <ActivityIndicator color="#fff" size="small" /> : <Ionicons name="checkmark-circle" size={16} color="#fff" />}
            <Text style={styles.doneLabel}>Marquer comme faite</Text>
          </Pressable>
        )
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
  done: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, backgroundColor: COLORS.success, borderRadius: RADIUS.sm, paddingVertical: 8, paddingHorizontal: 14, marginTop: 6, alignSelf: 'flex-start' },
  doneLabel: { color: '#fff', fontWeight: '800', fontSize: 13.5 },
  reopen: { alignSelf: 'flex-start', paddingVertical: 2 },
  reopenLabel: { color: COLORS.secondary, fontWeight: '700', fontSize: 12.5 },
});
