import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { articles as articlesApi } from '@/api/resources';
import type { Article, Comment } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { ReactionBar } from '@/components/ReactionBar';
import { COLORS } from '@/config';
import { formatDate, formatRelativeFr, htmlToText } from '@/utils/html';

export default function ArticleScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id);

  const [article, setArticle] = useState<Article | null>(null);
  const [comments, setComments] = useState<Comment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!Number.isFinite(id)) return;
    try {
      setError(null);
      const [art, cmts] = await Promise.all([articlesApi.get(id), articlesApi.comments(id, 1)]);
      setArticle(art);
      setComments(cmts.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!article) return null;

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Article' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container}>
          {article.photos.length > 0 && (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.photos}>
              {article.photos.map((p) =>
                p.url ? (
                  <Image
                    key={p.id}
                    source={{ uri: p.url }}
                    style={[styles.photo, article.photos.length === 1 && styles.photoFull]}
                    resizeMode="cover"
                    accessibilityLabel={p.alt ?? article.title}
                  />
                ) : null,
              )}
            </ScrollView>
          )}

          <View style={styles.content}>
            <Text style={styles.title}>{article.title}</Text>
            <Text style={styles.meta}>
              {article.author.fullName} · {formatDate(article.publishedAt)}
            </Text>

            <Text style={styles.body}>{htmlToText(article.content)}</Text>

            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Réactions</Text>
              <ReactionBar
                articleId={article.id}
                initialCounts={article.reactionCounts}
                initialMine={article.myReactions}
              />
            </View>

            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Commentaires ({comments.length})</Text>
              {comments.length === 0 ? (
                <Text style={styles.empty}>Aucun commentaire pour le moment. Soyez le premier !</Text>
              ) : (
                <View style={{ gap: 12 }}>
                  {comments.map((c) => (
                    <CommentItem key={c.id} comment={c} />
                  ))}
                </View>
              )}
              <CommentForm
                articleId={article.id}
                onPosted={(c) => setComments((prev) => [...prev, c])}
              />
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function CommentItem({ comment }: { comment: Comment }) {
  return (
    <View style={styles.comment}>
      <View style={styles.commentHeader}>
        <Text style={styles.commentAuthor}>{comment.user.fullName}</Text>
        <Text style={styles.commentTime}>{formatRelativeFr(comment.createdAt)}</Text>
      </View>
      <Text style={styles.commentBody}>{comment.content}</Text>
    </View>
  );
}

function CommentForm({ articleId, onPosted }: { articleId: number; onPosted: (c: Comment) => void }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    if (!text.trim() || busy) return;
    setBusy(true);
    setErr(null);
    try {
      const created = await articlesApi.addComment(articleId, text.trim());
      onPosted(created);
      setText('');
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'Erreur');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.form}>
      <TextInput
        value={text}
        onChangeText={setText}
        placeholder="Votre commentaire…"
        multiline
        style={styles.input}
        editable={!busy}
      />
      {err && <Text style={styles.formError}>{err}</Text>}
      <Pressable
        style={[styles.submit, (busy || !text.trim()) && styles.submitDisabled]}
        onPress={submit}
        disabled={busy || !text.trim()}
      >
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>Publier</Text>}
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: COLORS.background },
  container: { paddingBottom: 32 },
  photos: { backgroundColor: COLORS.black },
  photo: { width: 320, height: 200, marginRight: 0 },
  photoFull: { width: '100%', height: 220 },
  content: { padding: 16 },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginBottom: 6 },
  meta: { fontSize: 13, color: COLORS.textMuted, marginBottom: 16 },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 23 },
  section: {
    marginTop: 24,
    paddingTop: 16,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
  },
  sectionTitle: { fontSize: 16, fontWeight: '700', color: COLORS.text, marginBottom: 12 },
  empty: { color: COLORS.textMuted, fontSize: 14 },
  comment: {
    backgroundColor: COLORS.surface,
    padding: 12,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  commentHeader: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 },
  commentAuthor: { fontWeight: '700', color: COLORS.text, fontSize: 14 },
  commentTime: { color: COLORS.textMuted, fontSize: 12 },
  commentBody: { color: COLORS.text, fontSize: 14, lineHeight: 20 },
  form: { marginTop: 16 },
  input: {
    backgroundColor: COLORS.surface,
    borderColor: COLORS.border,
    borderWidth: 1,
    borderRadius: 8,
    padding: 12,
    fontSize: 14,
    minHeight: 80,
    textAlignVertical: 'top',
    color: COLORS.text,
  },
  formError: { color: COLORS.error, marginTop: 6, fontSize: 13 },
  submit: {
    backgroundColor: COLORS.primary,
    borderRadius: 8,
    padding: 12,
    alignItems: 'center',
    marginTop: 8,
  },
  submitDisabled: { opacity: 0.5 },
  submitLabel: { color: '#fff', fontWeight: '700' },
});
