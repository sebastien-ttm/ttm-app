import { Stack } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { events as eventsApi } from '@/api/resources';
import type { EventItem } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MonthCalendar } from '@/components/MonthCalendar';
import { COLORS } from '@/config';
import { formatDateTime } from '@/utils/html';

const TYPE_LABEL: Record<EventItem['type'], string> = {
  course: 'Course',
  stage: 'Stage',
  entrainement: 'Entraînement',
  social: 'Événement',
};

function startOfYear(d: Date): Date {
  return new Date(d.getFullYear(), 0, 1);
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

export default function CalendarScreen() {
  const [items, setItems] = useState<EventItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [visibleMonth, setVisibleMonth] = useState<Date>(() => startOfMonth(new Date()));
  const [selectedDate, setSelectedDate] = useState<Date | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      // Fetch a wide range so the month grid + chronological list both have data:
      // from start of current year to ~14 months ahead.
      const from = startOfYear(new Date());
      const to = new Date();
      to.setMonth(to.getMonth() + 14);
      const resp = await eventsApi.list(from.toISOString().slice(0, 10), to.toISOString().slice(0, 10));
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

  // Si un jour est sélectionné : événements qui couvrent ce jour
  // (un événement de 2 jours apparaît sur les 2 jours).
  // Sinon : événements à venir uniquement (non terminés à l'instant T).
  const listData = useMemo(() => {
    if (selectedDate) {
      const dayStart = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), selectedDate.getDate());
      const dayEnd = new Date(dayStart);
      dayEnd.setDate(dayEnd.getDate() + 1);
      return items.filter((e) => {
        const start = new Date(e.startsAt);
        const end = e.endsAt ? new Date(e.endsAt) : start;
        return start < dayEnd && end >= dayStart;
      });
    }
    const now = Date.now();
    return items.filter((e) => {
      const endTs = e.endsAt ? new Date(e.endsAt).getTime() : new Date(e.startsAt).getTime();
      return endTs >= now;
    });
  }, [items, selectedDate]);

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Calendrier' }} />
        <FullScreenLoading />
      </>
    );
  }

  return (
    <>
      <Stack.Screen options={{ title: 'Calendrier' }} />
      <FlatList
        data={listData}
        keyExtractor={(item) => String(item.id)}
        renderItem={({ item }) => <EventRow event={item} />}
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListHeaderComponent={
          <>
            <MonthCalendar
              visibleMonth={visibleMonth}
              events={items}
              selectedDate={selectedDate}
              onChangeMonth={setVisibleMonth}
              onSelectDate={setSelectedDate}
            />
            <View style={styles.listHeader}>
              <Text style={styles.listTitle}>
                {selectedDate
                  ? `Événements du ${selectedDate.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}`
                  : 'Tous les événements à venir'}
              </Text>
              {selectedDate && (
                <Pressable onPress={() => setSelectedDate(null)} hitSlop={6}>
                  <Text style={styles.clearLabel}>Tout afficher</Text>
                </Pressable>
              )}
            </View>
          </>
        }
        ListEmptyComponent={
          error ? (
            <ErrorState message={error} onRetry={load} />
          ) : selectedDate ? (
            <EmptyState icon="📅" title="Aucun événement ce jour" />
          ) : (
            <EmptyState icon="📅" title="Pas d'événement programmé" message="Le calendrier sera bientôt rempli." />
          )
        }
        style={{ backgroundColor: COLORS.background }}
      />
    </>
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
  content: { paddingBottom: 24 },
  listHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 14,
    paddingTop: 4,
    paddingBottom: 8,
  },
  listTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  clearLabel: { fontSize: 13, color: COLORS.primary, fontWeight: '600' },
  row: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    overflow: 'hidden',
    marginHorizontal: 12,
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
