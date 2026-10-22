import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { StageSurvey } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatDate, htmlExcerpt } from '@/utils/html';

/**
 * Sondage dédié au stage : titre, échéance et état (à répondre / répondu / clos). Un appui ouvre l'écran de réponse
 * habituel des sondages.
 */
export function StageSurveyCard({ survey }: { survey: StageSurvey }) {
  const router = useRouter();
  const pending = !survey.answered && !survey.isClosed;
  const intro = survey.description ? htmlExcerpt(survey.description, 110) : '';

  return (
    <Pressable
      onPress={() => router.push(('/survey/' + survey.id) as never)}
      accessibilityRole="button"
      accessibilityLabel={`Sondage : ${survey.title}`}
      style={({ pressed }) => [styles.card, pending && styles.cardPending, pressed && { opacity: 0.75 }]}
    >
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={styles.title} numberOfLines={2}>{survey.title}</Text>
        {intro ? <Text style={styles.intro} numberOfLines={2}>{intro}</Text> : null}
        <Text style={styles.meta}>
          {survey.isClosed
            ? 'Sondage clos'
            : survey.closesAt ? `À remplir avant le ${formatDate(survey.closesAt)}` : `${survey.sectionCount} question${survey.sectionCount > 1 ? 's' : ''}`}
        </Text>
      </View>
      {survey.answered ? (
        <View style={styles.doneChip}>
          <Ionicons name="checkmark" size={14} color="#fff" />
          <Text style={styles.chipLabel}>Répondu</Text>
        </View>
      ) : pending ? (
        <View style={styles.todoChip}><Text style={styles.chipLabel}>À répondre</Text></View>
      ) : null}
      <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  cardPending: { borderLeftWidth: 4, borderLeftColor: COLORS.secondary },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  intro: { fontSize: 12, color: COLORS.textMuted },
  meta: { fontSize: 12, color: COLORS.textMuted },
  doneChip: { flexDirection: 'row', alignItems: 'center', gap: 3, paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.success },
  todoChip: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.secondary },
  chipLabel: { fontSize: 11, fontWeight: '800', color: '#fff' },
});
