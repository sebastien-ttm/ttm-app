import { useRouter } from 'expo-router';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';

import type { Article } from '@/api/types';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { formatRelativeFr, htmlExcerpt } from '@/utils/html';

export function ArticleCard({ article }: { article: Article }) {
  const router = useRouter();
  const cover = article.photos.find((p) => p.url) ?? null;
  const reactionEntries = Object.entries(article.reactionCounts).filter(([, n]) => n > 0);

  return (
    <Pressable
      // react-native-web exposes `hovered` but it's not in the TS types
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      style={(state: any) => [
        styles.card,
        state.hovered && styles.hovered,
        state.pressed && styles.pressed,
      ]}
      onPress={() => router.push(`/article/${article.id}` as never)}
    >
      {cover?.url && (
        <Image
          source={{ uri: cover.url }}
          style={styles.cover}
          resizeMode="cover"
          accessibilityLabel={cover.alt ?? article.title}
        />
      )}
      <View style={styles.body}>
        <Text style={styles.title} numberOfLines={2}>
          {article.title}
        </Text>
        <Text style={styles.excerpt} numberOfLines={3}>
          {htmlExcerpt(article.content, 200)}
        </Text>

        <View style={styles.meta}>
          <Text style={styles.author} numberOfLines={1}>
            {article.author.fullName}
          </Text>
          <Text style={styles.dot}> · </Text>
          <Text style={styles.metaTime}>{formatRelativeFr(article.publishedAt)}</Text>
        </View>

        {(reactionEntries.length > 0 || article.commentCount > 0) && (
          <View style={styles.stats}>
            {reactionEntries.map(([emoji, n]) => (
              <View key={emoji} style={styles.statBadge}>
                <Text style={styles.statEmoji}>{emoji}</Text>
                <Text style={styles.statCount}>{n}</Text>
              </View>
            ))}
            {article.commentCount > 0 && (
              <View style={styles.statBadge}>
                <Text style={styles.statEmoji}>💬</Text>
                <Text style={styles.statCount}>{article.commentCount}</Text>
              </View>
            )}
          </View>
        )}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.lg,
    overflow: 'hidden',
    marginHorizontal: SPACING.md,
    marginBottom: SPACING.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    ...SHADOWS.sm,
    // @ts-expect-error web-only transition for smooth hover
    transition: 'transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease',
  },
  hovered: {
    borderColor: COLORS.borderStrong,
    ...SHADOWS.md,
    transform: [{ translateY: -1 }],
  },
  pressed: { opacity: 0.92 },
  cover: { width: '100%', aspectRatio: 16 / 9, backgroundColor: COLORS.border },
  body: { padding: SPACING.lg },
  title: { fontSize: 18, fontWeight: '700', color: COLORS.text, marginBottom: 6, letterSpacing: -0.1 },
  excerpt: { fontSize: 14, color: COLORS.textMuted, lineHeight: 21 },
  meta: { flexDirection: 'row', alignItems: 'center', marginTop: SPACING.md },
  author: { fontSize: 13, color: COLORS.text, fontWeight: '600' },
  dot: { fontSize: 13, color: COLORS.textSubtle },
  metaTime: { fontSize: 13, color: COLORS.textMuted },
  stats: { flexDirection: 'row', gap: 6, flexWrap: 'wrap', marginTop: SPACING.md },
  statBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.surfaceAlt,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    gap: 4,
  },
  statEmoji: { fontSize: 13 },
  statCount: { fontSize: 12, color: COLORS.text, fontWeight: '600' },
});
