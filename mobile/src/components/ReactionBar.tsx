import { useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { articles as articlesApi } from '@/api/resources';
import { REACTION_EMOJIS, type ReactionEmoji } from '@/api/types';
import { COLORS, RADIUS } from '@/config';

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
 *
 * L'état `mine` est initialisé une fois depuis `initialMine` au mount
 * et géré exclusivement en local ensuite (aucun useEffect « sync »
 * depuis les props — le parent ne refetch pas l'article après un
 * clic, donc `initialMine` reste figé à la valeur pré-clic et
 * écraserait tout le travail optimiste). ReactionBar est remounté
 * proprement par le parent quand l'article change (setArticle(null)
 * sur changement d'id) : rien à synchroniser en cours de vie.
 */
type Props = {
  articleId: number;
  initialMine: string[];
  onChange?: (counts: Record<string, number>) => void;
};

export function ReactionBar({ articleId, initialMine, onChange }: Props) {
  const [mine, setMine] = useState<string | null>(initialMine[0] ?? null);
  const [busy, setBusy] = useState<string | null>(null);
  const mineRef = useRef<string | null>(mine);
  mineRef.current = mine;

  async function toggle(emoji: ReactionEmoji) {
    if (busy) return;

    // Optimistic exclusif : on met à jour la sélection AVANT de marquer
    // busy, pour que le rerender applique immédiatement la surbrillance.
    const previous = mineRef.current;
    const nextOptimistic = previous === emoji ? null : emoji;
    setMine(nextOptimistic);
    setBusy(emoji);

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
            // Bloqué pendant qu'une requête est en cours (le `if (busy)
            // return` dans toggle en garantit aussi côté handler) — évite
            // les doubles-clics rapides qui produiraient des requêtes
            // concurrentes désynchronisées.
            disabled={busy !== null}
            style={({ pressed }) => [
              styles.button,
              active && styles.buttonActive,
              busy === emoji && styles.buttonBusy,
              pressed && { opacity: 0.7 },
            ]}
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
    backgroundColor: COLORS.surface,
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: RADIUS.full,
    borderWidth: 2,
    borderColor: COLORS.border,
    minWidth: 52,
  },
  buttonActive: {
    backgroundColor: COLORS.primarySoft,
    borderColor: COLORS.primary,
    transform: [{ scale: 1.08 }],
  },
  buttonBusy: { opacity: 0.55 },
  emoji: { fontSize: 20 },
});
