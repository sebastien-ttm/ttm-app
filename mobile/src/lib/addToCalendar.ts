import { Linking, Platform } from 'react-native';

import type { EventItem } from '@/api/types';

/**
 * Ajoute un événement au calendrier personnel de l'utilisateur.
 *  - Web : ouvre Google Calendar dans un nouvel onglet avec l'événement
 *    pré-rempli (titre, date, heures, lieu, description).
 *  - Natif : ouvre un data-URL text/calendar via Linking (iOS + Android
 *    associent le mime type à leur calendrier système).
 */
export function addEventToCalendar(event: EventItem): void {
  const start = new Date(event.startsAt);
  const end = event.endsAt ? new Date(event.endsAt) : null;
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
