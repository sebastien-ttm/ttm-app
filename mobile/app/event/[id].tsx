import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { events as api } from '@/api/resources';
import type { EventItem } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

const TYPE_LABEL: Record<EventItem['type'], string> = {
  course: 'Compétition',
  stage: 'Stage',
  entrainement: 'Entraînement exceptionnel',
  social: 'Événement social',
  organisation: 'Organisation',
};

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate();
}

function formatLongDate(d: Date): string {
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
function formatTime(d: Date): string {
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

export default function EventDetailScreen() {
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);
  const [event, setEvent] = useState<EventItem | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!id) { setError('Identifiant d\'événement invalide.'); return; }
    try {
      setError(null);
      const resp = await api.get(id);
      setEvent(resp);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, [id]);

  useEffect(() => {
    (async () => {
      await load();
      setLoading(false);
    })();
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Événement' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !event) {
    return (
      <>
        <Stack.Screen options={{ title: 'Événement' }} />
        <ErrorState message={error ?? 'Événement introuvable.'} onRetry={load} />
      </>
    );
  }

  const start = new Date(event.startsAt);
  const end = event.endsAt ? new Date(event.endsAt) : null;
  const multiDay = end !== null && !sameDay(start, end);

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: TYPE_LABEL[event.type] ?? 'Événement' }} />
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      >
        <View style={[styles.typeBadge, { backgroundColor: event.color }]}>
          <Text style={styles.typeBadgeLabel}>{TYPE_LABEL[event.type]}</Text>
        </View>

        <Text style={styles.title}>{event.title}</Text>

        <View style={styles.metaCard}>
          <View style={styles.metaRow}>
            <Ionicons name="calendar-outline" size={18} color={COLORS.textMuted} style={styles.metaIcon} />
            <View style={{ flex: 1 }}>
              {multiDay && end ? (
                <>
                  <Text style={styles.metaValue}>Du {formatLongDate(start)}</Text>
                  <Text style={styles.metaValue}>au {formatLongDate(end)}</Text>
                </>
              ) : (
                <Text style={styles.metaValue}>{formatLongDate(start)}</Text>
              )}
              {!event.isAllDay && !multiDay && (
                <Text style={styles.metaSub}>
                  {formatTime(start)}
                  {end ? ` – ${formatTime(end)}` : ''}
                </Text>
              )}
              {event.isAllDay && !multiDay && (
                <Text style={styles.metaSub}>Toute la journée</Text>
              )}
            </View>
          </View>

          {event.location && (
            <View style={styles.metaRow}>
              <Ionicons name="location-outline" size={18} color={COLORS.textMuted} style={styles.metaIcon} />
              <Text style={[styles.metaValue, { flex: 1 }]}>{event.location}</Text>
            </View>
          )}
        </View>

        {event.description ? (
          <View style={styles.descCard}>
            <Text style={styles.descTitle}>Descriptif</Text>
            {/* Description stockée en texte brut (TextareaField admin) —
                affichage respectant les retours à la ligne. */}
            <Text style={styles.descText}>{event.description}</Text>
          </View>
        ) : (
          <View style={styles.descCard}>
            <Text style={styles.descEmpty}>Aucun descriptif complémentaire.</Text>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, paddingBottom: SPACING.xxl },
  typeBadge: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.sm,
  },
  typeBadgeLabel: { color: '#fff', fontSize: 11, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.5 },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.lg },
  metaCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    gap: SPACING.sm,
  },
  metaRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  metaIcon: { marginTop: 2 },
  metaValue: { fontSize: 15, color: COLORS.text, fontWeight: '600' },
  metaSub: { fontSize: 13, color: COLORS.textMuted, marginTop: 2 },
  descCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
  },
  descTitle: {
    fontSize: 12, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm,
  },
  descText: { fontSize: 15, color: COLORS.text, lineHeight: 22 },
  descEmpty: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic', textAlign: 'center' },
});
