import type { MinibusBooking, MinibusStatus, MinibusStep } from '@/api/types';
import { COLORS } from '@/config';

/** Couleurs des états d'une demande (pastille et calendrier). */
export const MINIBUS_STATUS_STYLE: Record<MinibusStatus, { fg: string; bg: string; bar: string }> = {
  pending: { fg: '#92400e', bg: '#fef3c7', bar: '#d97706' },
  approved: { fg: '#166534', bg: '#dcfce7', bar: COLORS.secondary },
  refused: { fg: '#991b1b', bg: '#fee2e2', bar: COLORS.error },
  cancelled: { fg: '#475569', bg: '#e2e8f0', bar: COLORS.textSubtle },
};

/** Ce qu'il reste à faire à l'emprunteur d'un emprunt validé (null : rien, ou demande non validée). */
export function stepToDo(step: MinibusStep | null): string | null {
  switch (step) {
    case 'charter': return 'Charte à lire et accepter';
    case 'departure': return 'État des lieux de départ à remplir';
    case 'return': return 'État des lieux de retour à remplir';
    default: return null;
  }
}

/** Demande qui occupe (ou pourrait occuper) le minibus : en attente ou validée. */
export function isLiveBooking(b: MinibusBooking): boolean {
  return b.status === 'pending' || b.status === 'approved';
}

/** AAAA-MM-JJ d'une date locale. */
export function isoOfDate(d: Date): string {
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/** Date locale (minuit) d'un AAAA-MM-JJ. */
export function dateOfIso(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d);
}

/** La demande couvre-t-elle ce jour (AAAA-MM-JJ) ? Les AAAA-MM-JJ se comparent comme du texte. */
export function coversDay(b: MinibusBooking, iso: string): boolean {
  return b.startDate <= iso && iso <= b.endDate;
}

/** « 5 déc. → 6 déc. 2026 » ou « sam. 5 déc. 2026 » : les dates d'une demande, courtes. */
export function formatBookingRange(b: Pick<MinibusBooking, 'startDate' | 'endDate'>): string {
  const fmt = (iso: string, withYear: boolean) =>
    dateOfIso(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });
  if (b.startDate === b.endDate) {
    return dateOfIso(b.startDate).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  }
  return `${fmt(b.startDate, false)} → ${fmt(b.endDate, true)}`;
}

/** Demandes qui se terminent aujourd'hui ou plus tard, la plus proche d'abord. */
export function upcomingBookings(list: MinibusBooking[], todayIso: string): MinibusBooking[] {
  return list.filter((b) => b.endDate >= todayIso).sort((a, b) => a.startDate.localeCompare(b.startDate) || a.id - b.id);
}
