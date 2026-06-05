import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { events as eventsApi } from '@/api/resources';
import type { EventItem } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

const MAX_PREVIEW = 3;

/**
 * En-tête de l'onglet « Vie du Club » : affiche les 2-3 prochains
 * événements (du jour à +60 jours) avec un lien vers le calendrier complet.
 * Silencieux si rien à afficher ou si la requête échoue (le feed prend
 * toute la place).
 */
export function UpcomingEvents() {
  const router = useRouter();
  const [items, setItems] = useState<EventItem[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const from = new Date();
      from.setHours(0, 0, 0, 0);
      const to = new Date(from);
      to.setDate(to.getDate() + 60);

      const resp = await eventsApi.list(from.toISOString(), to.toISOString());
      // Le backend renvoie déjà trié par startsAt ASC.
      setItems(resp.data.slice(0, MAX_PREVIEW));
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  if (loading) {
    return (
      <View style={styles.card}>
        <ActivityIndicator color={COLORS.primary} />
      </View>
    );
  }

  // Si pas d'événements à venir, on n'affiche RIEN (on ne polluera pas le feed
  // avec un bloc « 0 événement »).
  if (items.length === 0) return null;

  return (
    <View style={styles.card}>
      <View style={styles.header}>
        <Ionicons name="calendar" size={18} color={COLORS.primary} />
        <Text style={styles.title}>Prochainement</Text>
      </View>

      {items.map((e) => (
        <EventRow key={e.id} event={e} />
      ))}

      <Pressable
        onPress={() => router.push('/calendar' as never)}
        style={({ pressed }) => [styles.allLink, pressed && { opacity: 0.6 }]}
      >
        <Text style={styles.allLinkLabel}>Voir tout le calendrier</Text>
        <Ionicons name="chevron-forward" size={16} color={COLORS.primary} />
      </Pressable>
    </View>
  );
}

function EventRow({ event }: { event: EventItem }) {
  const router = useRouter();
  const start = new Date(event.startsAt);
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && { opacity: 0.6 }]}
      onPress={() => router.push('/calendar' as never)}
    >
      <View style={[styles.dateBox, { backgroundColor: event.color || COLORS.primary }]}>
        <Text style={styles.dateDay}>{start.getDate()}</Text>
        <Text style={styles.dateMonth}>{monthShort(start)}</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.eventTitle} numberOfLines={1}>{event.title}</Text>
        <Text style={styles.eventSub} numberOfLines={1}>
          {weekdayShort(start)} {start.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
          {event.location ? ` · ${event.location}` : ''}
        </Text>
      </View>
    </Pressable>
  );
}

function monthShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '').toUpperCase();
}
function weekdayShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { weekday: 'short' }).replace('.', '');
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    margin: SPACING.md,
    padding: SPACING.md,
    gap: 4,
  },
  header: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: SPACING.sm },
  title: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 8,
    gap: 12,
  },
  dateBox: {
    width: 48,
    height: 48,
    borderRadius: RADIUS.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dateDay: { color: '#fff', fontSize: 18, fontWeight: '700', lineHeight: 20 },
  dateMonth: { color: '#fff', fontSize: 10, fontWeight: '600', letterSpacing: 0.5 },
  eventTitle: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  eventSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2, textTransform: 'capitalize' },
  allLink: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingTop: SPACING.sm,
    marginTop: SPACING.xs,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
  },
  allLinkLabel: { color: COLORS.primary, fontWeight: '600', fontSize: 13 },
});
