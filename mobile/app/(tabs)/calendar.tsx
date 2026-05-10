import { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { events as eventsApi } from '@/api/resources';
import type { EventItem } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS } from '@/config';
import { formatDateTime } from '@/utils/html';

const TYPE_LABEL: Record<EventItem['type'], string> = {
  course: 'Course',
  stage: 'Stage',
  entrainement: 'Entraînement',
  social: 'Événement',
};

export default function CalendarScreen() {
  const [items, setItems] = useState<EventItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const today = new Date();
      const inSixMonths = new Date();
      inSixMonths.setMonth(inSixMonths.getMonth() + 6);
      const resp = await eventsApi.list(
        today.toISOString().slice(0, 10),
        inSixMonths.toISOString().slice(0, 10),
      );
      setItems(resp.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      await load();
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  if (loading) return <FullScreenLoading />;

  return (
    <FlatList
      data={items}
      keyExtractor={(item) => String(item.id)}
      renderItem={({ item }) => <EventRow event={item} />}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      ListEmptyComponent={
        error ? (
          <ErrorState message={error} onRetry={load} />
        ) : (
          <EmptyState icon="📅" title="Pas d'événement programmé" message="Le calendrier sera bientôt rempli." />
        )
      }
      style={{ backgroundColor: COLORS.background }}
    />
  );
}

function EventRow({ event }: { event: EventItem }) {
  return (
    <View style={styles.row}>
      <View style={[styles.bar, { backgroundColor: event.color }]} />
      <View style={styles.body}>
        <View style={styles.header}>
          <Text style={[styles.type, { color: event.color }]}>{TYPE_LABEL[event.type]}</Text>
          <Text style={styles.date}>{formatDateTime(event.startsAt)}</Text>
        </View>
        <Text style={styles.title}>{event.title}</Text>
        {event.location && <Text style={styles.location}>📍 {event.location}</Text>}
        {event.description && <Text style={styles.description}>{event.description}</Text>}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  content: { padding: 12 },
  row: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    overflow: 'hidden',
    marginBottom: 10,
    elevation: 1,
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
  },
  bar: { width: 5 },
  body: { flex: 1, padding: 14 },
  header: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 },
  type: { fontSize: 11, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.5 },
  date: { fontSize: 12, color: COLORS.textMuted },
  title: { fontSize: 16, fontWeight: '700', color: COLORS.text, marginBottom: 4 },
  location: { fontSize: 13, color: COLORS.textMuted, marginBottom: 4 },
  description: { fontSize: 13, color: COLORS.text, lineHeight: 18 },
});
