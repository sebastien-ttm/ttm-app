import { useRouter } from 'expo-router';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';

import type { Article } from '@/api/types';
import { COLORS } from '@/config';
import { formatRelativeFr, htmlExcerpt } from '@/utils/html';

export function ArticleCard({ article }: { article: Article }) {
  const router = useRouter();
  const cover = article.photos.find((p) => p.url) ?? null;
  const totalReactions = Object.values(article.reactionCounts).reduce((a, b) => a + b, 0);

  return (
    <Pressable
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
      onPress={() => router.push(`/article/${article.id}` as never)}
    >
      {cover?.url && (
        <Image source={{ uri: cover.url }} style={styles.cover} resizeMode="cover" accessibilityLabel={cover.alt ?? article.title} />
      )}
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={2}>
          {article.title}
        </Text>
        <Text style={styles.excerpt} numberOfLines={3}>
          {htmlExcerpt(article.content, 200)}
        </Text>
        <View style={styles.footer}>
          <Text style={styles.meta}>
            {article.author.fullName} · {formatRelativeFr(article.publishedAt)}
          </Text>
          <View style={styles.stats}>
            {totalReactions > 0 && (
              <View style={styles.statBadge}>
                <Text style={styles.statText}>
                  {Object.entries(article.reactionCounts)
                    .map(([emoji, n]) => `${emoji} ${n}`)
                    .join(' · ')}
                </Text>
              </View>
            )}
            {article.commentCount > 0 && (
              <View style={styles.statBadge}>
                <Text style={styles.statText}>💬 {article.commentCount}</Text>
              </View>
            )}
          </View>
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    overflow: 'hidden',
    marginBottom: 12,
    shadowColor: '#000',
    shadowOpacity: 0.06,
    shadowRadius: 4,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  pressed: { opacity: 0.85 },
  cover: { width: '100%', aspectRatio: 16 / 9, backgroundColor: COLORS.border },
  body: { padding: 14 },
  title: { fontSize: 17, fontWeight: '700', color: COLORS.text, marginBottom: 6 },
  excerpt: { fontSize: 14, color: COLORS.textMuted, lineHeight: 20 },
  footer: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 12,
    flexWrap: 'wrap',
    gap: 6,
  },
  meta: { fontSize: 12, color: COLORS.textMuted, flex: 1 },
  stats: { flexDirection: 'row', gap: 6, flexWrap: 'wrap' },
  statBadge: {
    backgroundColor: COLORS.background,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 12,
  },
  statText: { fontSize: 12, color: COLORS.text, fontWeight: '500' },
});
