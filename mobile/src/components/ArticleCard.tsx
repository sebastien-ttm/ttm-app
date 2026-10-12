import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { Article } from '@/api/types';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { formatRelativeFr } from '@/utils/html';

/**
 * Carte article — version compacte de la liste Actualités.
 *
 * Layout fixe sur 2 rangs (le rang 2 est toujours présent, même sans
 * réaction) :
 *   [🎉 Titre article ..........................................]
 *   [👍 3] [❤️ 2] [💬 5]                             Auteur · date
 *
 * Pas de cover, pas d'excerpt : le résumé complet reste accessible en
 * tapant sur la carte (page /article/{id}). L'objectif est la densité
 * verticale pour voir plus d'articles sans scroller.
 *
 * Un article déjà lu (`article.read`) est présenté plus discrètement : carte aplatie (fond de page,
 * sans ombre), titre en gris et poids normal, coche « lu ». Les non lus gardent le rendu habituel.
 * `onOpen` permet à la liste de marquer l'article lu tout de suite, sans attendre le rechargement.
 */
export function ArticleCard({ article, onOpen }: { article: Article; onOpen?: (article: Article) => void }) {
  const router = useRouter();
  const reactionEntries = Object.entries(article.reactionCounts).filter(([, n]) => n > 0);
  const read = article.read === true;

  return (
    <Pressable
      // react-native-web exposes `hovered` but it's not in the TS types
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      style={(state: any) => [
        styles.card,
        read && styles.cardRead,
        state.hovered && styles.hovered,
        state.pressed && styles.pressed,
      ]}
      onPress={() => {
        onOpen?.(article);
        router.push(`/article/${article.id}` as never);
      }}
    >
      <View style={styles.titleRow}>
        <Text style={[styles.title, read && styles.titleRead]} numberOfLines={1}>
          {article.icon ? <Text style={styles.titleIcon}>{article.icon} </Text> : null}
          {article.title}
        </Text>
        {read && (
          <View accessible accessibilityLabel="Article lu" style={styles.readMark}>
            <Ionicons name="checkmark-done" size={15} color={COLORS.textMuted} />
          </View>
        )}
      </View>

      {/* Rang 2 toujours affiché : stats à gauche (peut être vide), auteur
          + date à droite pour ancrer visuellement chaque carte. */}
      <View style={styles.footer}>
        <View style={styles.stats}>
          {reactionEntries.map(([emoji, n]) => (
            <View key={emoji} style={styles.statBadge}>
              <Text style={styles.statEmoji}>{emoji}</Text>
              <Text style={styles.statCount}>{n}</Text>
            </View>
          ))}
          {article.commentCount > 0 && (
            <View style={[styles.statBadge, styles.statBadgeComment]}>
              <Text style={styles.statEmoji}>💬</Text>
              <Text style={[styles.statCount, styles.statCountComment]}>{article.commentCount}</Text>
            </View>
          )}
        </View>
        <Text style={styles.meta} numberOfLines={1}>
          {article.author.fullName} · {formatRelativeFr(article.publishedAt)}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    marginHorizontal: SPACING.md,
    marginBottom: 6,
    paddingHorizontal: SPACING.md,
    paddingVertical: 10,
    borderWidth: 1,
    borderColor: COLORS.border,
    ...SHADOWS.sm,
    // @ts-expect-error web-only transition for smooth hover
    transition: 'border-color 150ms ease, box-shadow 150ms ease',
  },
  // Article déjà lu : carte aplatie (même fond que la page, sans ombre).
  cardRead: {
    backgroundColor: COLORS.background,
    shadowOpacity: 0,
    elevation: 0,
  },
  hovered: {
    borderColor: COLORS.borderStrong,
    ...SHADOWS.md,
  },
  pressed: { opacity: 0.9 },
  titleRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  title: {
    flex: 1,
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.text,
    letterSpacing: -0.1,
  },
  titleRead: { fontWeight: '500', color: '#4b5563' },
  readMark: { flexShrink: 0 },
  titleIcon: { fontSize: 16 },
  footer: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    marginTop: 6,
    minHeight: 20,
  },
  stats: {
    flexDirection: 'row',
    gap: 6,
    flexWrap: 'wrap',
    flexShrink: 1,
  },
  meta: {
    fontSize: 12,
    color: COLORS.textMuted,
    flexShrink: 0,
    textAlign: 'right',
    maxWidth: '55%',
  },
  statBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.surfaceAlt,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    gap: 3,
  },
  statEmoji: { fontSize: 12 },
  statCount: { fontSize: 11, color: COLORS.text, fontWeight: '600' },
  statBadgeComment: { backgroundColor: COLORS.secondarySoft, borderColor: COLORS.secondarySoft },
  statCountComment: { color: COLORS.secondaryDark },
});
