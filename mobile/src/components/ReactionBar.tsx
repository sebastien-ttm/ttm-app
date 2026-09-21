import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { articles as articlesApi } from '@/api/resources';
import { REACTION_EMOJIS, type ReactionEmoji } from '@/api/types';
import { COLORS } from '@/config';

/**
 * Barre de réactions d'article (page détail).
 *
 * Comportement :
 *  - Exclusivité : un user a au plus UN emoji actif à la fois.
 *    Cliquer un nouvel emoji retire automatiquement l'ancien (garanti
 *    aussi côté backend, cf. ArticleController::toggleReaction).
 *  - Pas de compteurs affichés ici. La page de détail n'expose que le
 *    choix personnel — l'agrégat (compteurs par emoji) reste réservé
 *    à la liste des articles (ArticleCard).
 */
type Props = {
  articleId: number;
  initialMine: string[];
  onChange?: (counts: Record<string, number>) => void;
};

export function ReactionBar({ articleId, initialMine, onChange }: Props) {
  const [mine, setMine] = useState<string | null>(initialMine[0] ?? null);
  const [busy, setBusy] = useState<string | null>(null);

  async function toggle(emoji: ReactionEmoji) {
    if (busy) return;
    setBusy(emoji);

    // Optimistic exclusif : si on reclique la même → on efface ; sinon
    // on remplace directement (pas de double-affichage transitoire).
    const previous = mine;
    setMine(previous === emoji ? null : emoji);

    try {
      const resp = await articlesApi.toggleReaction(articleId, emoji);
      setMine(resp.myReactions[0] ?? null);
      onChange?.(resp.reactionCounts);
    } catch {
      // Revert on failure
      setMine(previous);
    } finally {
      setBusy(null);
    }
  }

  return (
    <View style={styles.row}>
      {REACTION_EMOJIS.map((emoji) => {
        const active = mine === emoji;
        return (
          <Pressable
            key={emoji}
            onPress={() => toggle(emoji)}
            disabled={busy === emoji}
            style={[styles.button, active && styles.buttonActive, busy === emoji && styles.buttonBusy]}
          >
            <Text style={styles.emoji}>{emoji}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  button: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: COLORS.background,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: COLORS.border,
    minWidth: 44,
  },
  buttonActive: { backgroundColor: '#FFE6E6', borderColor: COLORS.primary },
  buttonBusy: { opacity: 0.5 },
  emoji: { fontSize: 18 },
});
