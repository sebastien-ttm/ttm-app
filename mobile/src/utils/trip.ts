import type { TripSummary } from '@/api/types';

/** « AAAA-MM-JJ » → date locale à minuit (évite le décalage de fuseau d'un `new Date('AAAA-MM-JJ')`, lu en UTC). */
function parseDay(day: string): Date {
  const [y, m, d] = day.split('-').map(Number);
  return new Date(y, (m || 1) - 1, d || 1);
}

function startOfToday(now: Date): Date {
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

/** « 12 nov. », « du 12 au 14 nov. », « du 30 oct. au 2 nov. » (+ l'année si ce n'est pas l'année en cours). */
export function tripDatesLabel(trip: Pick<TripSummary, 'startsOn' | 'endsOn'>, now: Date = new Date()): string {
  if (!trip.startsOn) return '';
  const start = parseDay(trip.startsOn);
  const end = trip.endsOn ? parseDay(trip.endsOn) : null;
  const withYear = start.getFullYear() !== now.getFullYear() || (end !== null && end.getFullYear() !== now.getFullYear());
  const day = (d: Date, opts: Intl.DateTimeFormatOptions) => d.toLocaleDateString('fr-FR', opts);
  const full: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) };

  if (end === null || end.getTime() === start.getTime()) return day(start, full);
  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    return `du ${start.getDate()} au ${day(end, full)}`;
  }
  return `du ${day(start, full)} au ${day(end, full)}`;
}

export type TripPhase = 'upcoming' | 'ongoing' | 'past';

export function tripPhase(trip: Pick<TripSummary, 'startsOn' | 'endsOn'>, now: Date = new Date()): TripPhase {
  if (!trip.startsOn) return 'upcoming';
  const today = startOfToday(now).getTime();
  const start = parseDay(trip.startsOn).getTime();
  const end = parseDay(trip.endsOn ?? trip.startsOn).getTime();
  if (today < start) return 'upcoming';
  return today > end ? 'past' : 'ongoing';
}

/** « Dans 12 jours », « Demain », « En cours », « Terminé » : l'état du déplacement en quelques mots. */
export function tripStatusLabel(trip: Pick<TripSummary, 'startsOn' | 'endsOn'>, now: Date = new Date()): string {
  const phase = tripPhase(trip, now);
  if (phase === 'ongoing') return 'En cours';
  if (phase === 'past') return 'Terminé';
  const days = Math.round((parseDay(trip.startsOn as string).getTime() - startOfToday(now).getTime()) / 86_400_000);
  if (days <= 1) return 'Demain';
  return `Dans ${days} jours`;
}
