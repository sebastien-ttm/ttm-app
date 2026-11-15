import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { board as boardApi } from '@/api/resources';
import type { BoardMeetingSummary } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatMeetingDate } from '@/utils/boardDate';

/**
 * Comité directeur (membres du CoDir et administrateurs) : toutes les réunions, la prochaine en tête.
 * Une réunion s'ouvre d'un toucher : son détail permet d'ajouter ses points, de commenter ceux des autres et,
 * une fois la réunion close, de lire le compte rendu (réponses, décisions, actions).
 */
export default function BoardMeetingsScreen() {
  const router = useRouter();
  const [items, setItems] = useState<BoardMeetingSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      setItems((await boardApi.meetings()).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (items === null) return <FullScreenLoading />;

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: 'Comité directeur' }} />
      <FlatList
        data={items}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        ListEmptyComponent={<EmptyState icon="🏛️" title="Aucune réunion programmée" message="Les réunions du CoDir apparaîtront ici." />}
        renderItem={({ item }) => (
          <Pressable
            onPress={() => router.push(('/board/' + item.id) as never)}
            style={({ pressed }) => [styles.card, item.isNext && styles.cardNext, pressed && { opacity: 0.8 }]}
          >
            <View style={{ flex: 1, gap: 3 }}>
              {item.isNext && <Text style={styles.nextTag}>Prochaine réunion</Text>}
              <Text style={styles.title}>{item.label}</Text>
              <Text style={styles.meta}>{formatMeetingDate(item.scheduledAt)}{item.location ? ' · ' + item.location : ''}</Text>
              <Text style={styles.meta}>
                {item.itemCount} point{item.itemCount > 1 ? 's' : ''} à l'ordre du jour
                {item.closed ? ` · compte rendu (${item.answeredCount} traité${item.answeredCount > 1 ? 's' : ''})` : ''}
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  list: { padding: SPACING.md, gap: SPACING.sm, maxWidth: 720, width: '100%', alignSelf: 'center' },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  cardNext: { borderColor: COLORS.secondary, borderWidth: 2 },
  nextTag: { alignSelf: 'flex-start', fontSize: 11, fontWeight: '700', color: COLORS.secondary, backgroundColor: COLORS.secondarySoft, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10, overflow: 'hidden' },
  title: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 13, color: COLORS.textMuted },
});
