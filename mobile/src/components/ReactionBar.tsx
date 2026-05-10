import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { articles as articlesApi } from '@/api/resources';
import { REACTION_EMOJIS, type ReactionEmoji } from '@/api/types';
import { COLORS } from '@/config';

type Props = {
  articleId: number;
  initialCounts: Record<string, number>;
  initialMine: string[];
  onChange?: (counts: Record<string, number>) => void;
};

export function ReactionBar({ articleId, initialCounts, initialMine, onChange }: Props) {
  const [counts, setCounts] = useState<Record<string, number>>(initialCounts);
  const [mine, setMine] = useState<Set<string>>(new Set(initialMine));
  const [busy, setBusy] = useState<string | null>(null);

  async function toggle(emoji: ReactionEmoji) {
    if (busy) return;
    setBusy(emoji);

    // Optimistic update
    const wasActive = mine.has(emoji);
    const newMine = new Set(mine);
    const newCounts = { ...counts };
    if (wasActive) {
      newMine.delete(emoji);
      newCounts[emoji] = Math.max(0, (newCounts[emoji] ?? 0) - 1);
    } else {
      newMine.add(emoji);
      newCounts[emoji] = (newCounts[emoji] ?? 0) + 1;
    }
    setMine(newMine);
    setCounts(newCounts);

    try {
      const resp = await articlesApi.toggleReaction(articleId, emoji);
      setCounts(resp.reactionCounts);
      onChange?.(resp.reactionCounts);
    } catch {
      // Revert on failure
      setMine(new Set(mine));
      setCounts(counts);
    } finally {
      setBusy(null);
    }
  }

  return (
    <View style={styles.row}>
      {REACTION_EMOJIS.map((emoji) => {
        const count = counts[emoji] ?? 0;
        const active = mine.has(emoji);
        return (
          <Pressable
            key={emoji}
            onPress={() => toggle(emoji)}
            disabled={busy === emoji}
            style={[styles.button, active && styles.buttonActive, busy === emoji && styles.buttonBusy]}
          >
            <Text style={styles.emoji}>{emoji}</Text>
            {count > 0 && <Text style={[styles.count, active && styles.countActive]}>{count}</Text>}
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.background,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: COLORS.border,
    gap: 4,
  },
  buttonActive: { backgroundColor: '#FFE6E6', borderColor: COLORS.primary },
  buttonBusy: { opacity: 0.5 },
  emoji: { fontSize: 16 },
  count: { fontSize: 13, color: COLORS.textMuted, fontWeight: '600' },
  countActive: { color: COLORS.primary },
});
