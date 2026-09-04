import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Linking, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

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
      // Fenêtre large (aujourd'hui → +12 mois) pour couvrir les
      // événements planifiés loin à l'avance (compétitions estivales
      // programmées en début de saison, etc.). MAX_PREVIEW cape
      // l'affichage aux N plus proches — la fenêtre n'a pas besoin
      // d'être serrée.
      const from = new Date();
      from.setHours(0, 0, 0, 0);
      const to = new Date(from);
      to.setMonth(to.getMonth() + 12);

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
      <View style={styles.loadingBlock}>
        <ActivityIndicator color={COLORS.primary} />
      </View>
    );
  }

  // Rend TOUJOURS le bloc (même sans événements à venir) — le lien
  // « Voir tout le calendrier » reste ainsi accessible depuis la home.
  const hasEvents = items.length > 0;

  return (
    <View>
      <View style={styles.header}>
        <Ionicons name="calendar" size={18} color={COLORS.primary} />
        <Text style={styles.title}>Prochainement</Text>
      </View>

      {hasEvents ? (
        items.map((e) => <EventRow key={e.id} event={e} />)
      ) : (
        <Text style={styles.empty}>Aucun événement programmé pour l'instant.</Text>
      )}

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
  const end = event.endsAt ? new Date(event.endsAt) : null;
  const color = event.color || COLORS.primary;
  const isMultiDay = end !== null && !sameDay(start, end);

  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && { opacity: 0.6 }]}
      onPress={() => router.push({ pathname: '/event/[id]', params: { id: String(event.id) } } as never)}
    >
      {isMultiDay && end ? (
        <View style={styles.dateRange}>
          <DateBox date={start} color={color} />
          <Ionicons name="arrow-forward" size={12} color={COLORS.textMuted} style={styles.dateArrow} />
          <DateBox date={end} color={color} />
        </View>
      ) : (
        <DateBox date={start} color={color} />
      )}
      <View style={{ flex: 1 }}>
        <Text style={styles.eventTitle} numberOfLines={1}>{event.title}</Text>
        {/* Le jour de la semaine est désormais intégré dans la DateBox.
            Sur cette ligne : heure (en gras pour visibilité) OU « Toute
            la journée » + lieu si présent. Multi-jour → uniquement le lieu. */}
        {(() => {
          const timeStr = !isMultiDay && !event.isAllDay
            ? start.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
            : null;
          const allDayLabel = !isMultiDay && event.isAllDay ? 'Toute la journée' : null;
          if (!timeStr && !allDayLabel && !event.location) return null;
          return (
            <Text style={styles.eventSub} numberOfLines={1}>
              {timeStr ? <Text style={styles.eventTime}>{timeStr}</Text> : null}
              {allDayLabel ? allDayLabel : null}
              {event.location ? ((timeStr || allDayLabel) ? ' · ' : '') + event.location : ''}
            </Text>
          );
        })()}
      </View>
      {/* Bouton discret « ajouter au calendrier » — icône seule, à droite.
          stopPropagation via un handler dédié pour ne pas déclencher la
          navigation vers le détail de l'événement en même temps. */}
      <Pressable
        onPress={(e) => {
          e.stopPropagation?.();
          addEventToCalendar(event);
        }}
        hitSlop={8}
        accessibilityLabel="Ajouter à mon calendrier"
        style={({ pressed }) => [styles.calBtn, pressed && { opacity: 0.5 }]}
      >
        <Ionicons name="calendar-outline" size={20} color={COLORS.textMuted} />
      </Pressable>
    </Pressable>
  );
}

/**
 * Ajoute un événement au calendrier personnel.
 *  - Web : ouvre Google Calendar dans un nouvel onglet avec l'événement
 *    pré-rempli (titre, date, heures, lieu, description).
 *  - Natif : ouvre un data-URL text/calendar via Linking (iOS + Android
 *    associent le mime type à leur calendrier système).
 */
function addEventToCalendar(event: EventItem): void {
  const start = new Date(event.startsAt);
  const end = event.endsAt ? new Date(event.endsAt) : null;
  // Format Google Calendar :
  //  - all-day : YYYYMMDD/YYYYMMDDNextDay (fin exclusive)
  //  - timed   : YYYYMMDDTHHMMSSZ/YYYYMMDDTHHMMSSZ (UTC)
  const gcalDates = buildGcalDates(start, end, event.isAllDay);

  if (Platform.OS === 'web' && typeof window !== 'undefined') {
    const params = new URLSearchParams({
      action: 'TEMPLATE',
      text: event.title,
      dates: gcalDates,
    });
    if (event.location) params.set('location', event.location);
    if (event.description) params.set('details', event.description);
    window.open(`https://calendar.google.com/calendar/render?${params.toString()}`, '_blank');
    return;
  }

  // Natif : ICS text/calendar via Linking
  const ics = buildEventIcs(event, start, end);
  void Linking.openURL('data:text/calendar;charset=utf-8,' + encodeURIComponent(ics));
}

function pad(n: number): string { return String(n).padStart(2, '0'); }
function ymdLocal(d: Date): string {
  return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}`;
}
function ymdUtc(d: Date): string {
  return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}`;
}
function hmsUtc(d: Date): string {
  return `${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}`;
}

function buildGcalDates(start: Date, end: Date | null, isAllDay: boolean): string {
  if (isAllDay) {
    const s = ymdLocal(start);
    // Fin exclusive : jour après la date de fin (ou start si pas d'end)
    const endBase = end ? new Date(end) : new Date(start);
    endBase.setDate(endBase.getDate() + 1);
    return `${s}/${ymdLocal(endBase)}`;
  }
  const effectiveEnd = end ?? new Date(start.getTime() + 60 * 60_000); // 1 h par défaut
  return `${ymdUtc(start)}T${hmsUtc(start)}Z/${ymdUtc(effectiveEnd)}T${hmsUtc(effectiveEnd)}Z`;
}

function buildEventIcs(event: EventItem, start: Date, end: Date | null): string {
  const now = new Date();
  const stamp = `${ymdUtc(now)}T${hmsUtc(now)}Z`;
  const uid = `ttm-event-${event.id}-${now.getTime()}@ttm`;
  const lines: string[] = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//TTM//events//FR',
    'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    `UID:${uid}`,
    `DTSTAMP:${stamp}`,
  ];
  if (event.isAllDay) {
    const endBase = end ? new Date(end) : new Date(start);
    endBase.setDate(endBase.getDate() + 1);
    lines.push(`DTSTART;VALUE=DATE:${ymdLocal(start)}`);
    lines.push(`DTEND;VALUE=DATE:${ymdLocal(endBase)}`);
  } else {
    const effectiveEnd = end ?? new Date(start.getTime() + 60 * 60_000);
    lines.push(`DTSTART:${ymdUtc(start)}T${hmsUtc(start)}Z`);
    lines.push(`DTEND:${ymdUtc(effectiveEnd)}T${hmsUtc(effectiveEnd)}Z`);
  }
  lines.push(`SUMMARY:${(event.title || '').replace(/\r?\n/g, ' ')}`);
  if (event.location) lines.push(`LOCATION:${event.location.replace(/\r?\n/g, ' ')}`);
  if (event.description) lines.push(`DESCRIPTION:${event.description.replace(/\r?\n/g, ' ')}`);
  lines.push('END:VEVENT', 'END:VCALENDAR');
  return lines.join('\r\n');
}

function DateBox({ date, color }: { date: Date; color: string }) {
  return (
    <View style={[styles.dateBox, { backgroundColor: color }]}>
      <Text style={styles.dateWeekday}>{weekdayShort(date).toUpperCase()}</Text>
      <Text style={styles.dateDay}>{date.getDate()}</Text>
      <Text style={styles.dateMonth}>{monthShort(date)}</Text>
    </View>
  );
}

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate();
}

function monthShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { month: 'short' }).replace('.', '').toUpperCase();
}
function weekdayShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { weekday: 'short' }).replace('.', '');
}

const styles = StyleSheet.create({
  loadingBlock: {
    marginHorizontal: SPACING.md,
    marginVertical: SPACING.md,
    alignItems: 'center',
  },
  // Header aligné avec les section titles standards (mêmes marges que
  // « Articles ») — l'ancien wrapper card est supprimé pour éviter le
  // décalage visuel entre les 2 sections.
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginHorizontal: SPACING.md,
    marginTop: SPACING.md,
    marginBottom: SPACING.sm,
    minHeight: 18,
  },
  title: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, lineHeight: 18 },
  // Chaque row devient sa propre carte visuelle (bg + radius + margin)
  // depuis la suppression du wrapper englobant.
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: SPACING.md,
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    marginHorizontal: SPACING.md,
    marginBottom: SPACING.sm,
  },
  dateBox: {
    width: 48,
    height: 60,
    borderRadius: RADIUS.sm,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 4,
  },
  dateRange: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  dateArrow: { marginHorizontal: 1 },
  dateWeekday: { color: 'rgba(255,255,255,0.9)', fontSize: 9, fontWeight: '700', letterSpacing: 0.5, lineHeight: 11 },
  dateDay: { color: '#fff', fontSize: 18, fontWeight: '700', lineHeight: 22 },
  dateMonth: { color: '#fff', fontSize: 10, fontWeight: '600', letterSpacing: 0.5, lineHeight: 12 },
  eventTitle: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  eventSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  eventTime: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  allLink: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingVertical: SPACING.sm,
    marginHorizontal: SPACING.md,
    marginBottom: SPACING.sm,
  },
  allLinkLabel: { color: COLORS.primary, fontWeight: '600', fontSize: 13 },
  calBtn: {
    marginLeft: 6,
    padding: 6,
    alignSelf: 'center',
  },
  empty: {
    fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic',
    paddingVertical: SPACING.sm,
    marginHorizontal: SPACING.md,
  },
});
