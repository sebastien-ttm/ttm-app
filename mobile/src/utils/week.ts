/**
 * Utilitaires de manipulation de semaine ISO-8601.
 * Lundi = 1, dimanche = 7 (cohérent avec le backend).
 */

/** Retourne le lundi 00:00 de la semaine contenant `d`. */
export function getMonday(d: Date): Date {
  const date = new Date(d);
  date.setHours(0, 0, 0, 0);
  const day = date.getDay(); // 0=dim ... 6=sam
  const diff = day === 0 ? -6 : 1 - day; // ramène à lundi
  date.setDate(date.getDate() + diff);
  return date;
}

export function addDays(d: Date, n: number): Date {
  const date = new Date(d);
  date.setDate(date.getDate() + n);
  return date;
}

export function addWeeks(d: Date, n: number): Date {
  return addDays(d, n * 7);
}

/** YYYY-MM-DD (local, pas UTC — évite les décalages). */
export function toIsoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function fromIsoDate(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}

export function isSameWeek(a: Date, b: Date): boolean {
  return toIsoDate(getMonday(a)) === toIsoDate(getMonday(b));
}

const DAY_LABELS_FR = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

export function dayLabel(dayOfWeek: number): string {
  return DAY_LABELS_FR[dayOfWeek] ?? '?';
}

const MONTHS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** "Semaine du 25 mai au 31 mai 2026" — fallback si l'API ne fournit pas le label. */
export function formatWeekRange(monday: Date): string {
  const sunday = addDays(monday, 6);
  const sameMonth = monday.getMonth() === sunday.getMonth();
  const left = sameMonth
    ? `${monday.getDate()}`
    : `${monday.getDate()} ${MONTHS_FR[monday.getMonth()]}`;
  return `Semaine du ${left} au ${sunday.getDate()} ${MONTHS_FR[sunday.getMonth()]} ${sunday.getFullYear()}`;
}

/** "25 mai" — pour les en-têtes de jour. */
export function shortDayLabel(date: Date): string {
  return `${date.getDate()} ${MONTHS_FR[date.getMonth()]}`;
}
