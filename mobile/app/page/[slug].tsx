import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { pages as pagesApi } from '@/api/resources';
import type { StaticPage } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS } from '@/config';
import { htmlToText } from '@/utils/html';

export default function PageScreen() {
  const params = useLocalSearchParams<{ slug: string }>();
  const slug = String(params.slug ?? '');

  const [page, setPage] = useState<StaticPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!slug) return;
    try {
      setError(null);
      const data = await pagesApi.get(slug);
      setPage(data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Page introuvable');
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!page) return null;

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: page.title }} />
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title}>{page.title}</Text>
        <Text style={styles.body}>{htmlToText(page.content)}</Text>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontSize: 24, fontWeight: '700', color: COLORS.text, marginBottom: 12 },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 24 },
});
