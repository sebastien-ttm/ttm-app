/** Date d'une réunion du CoDir : « mercredi 23 septembre 2026 · 20h00 ». */
export function formatMeetingDate(iso: string): string {
  const d = new Date(iso);
  const day = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const h = String(d.getHours()).padStart(2, '0');
  const m = String(d.getMinutes()).padStart(2, '0');
  return `${day} · ${h}h${m}`;
}

/** « AAAA-MM-JJ » → date locale à minuit (sans décalage de fuseau). */
function parseDay(ymd: string): Date {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, (m || 1) - 1, d || 1);
}

/** Échéance d'une action : « 12 déc. 2026 ». */
export function formatDueDate(ymd: string): string {
  return parseDay(ymd).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
}

export type DueTone = 'late' | 'soon' | 'normal';

/**
 * Libellé et ton de l'échéance d'une action en cours : « En retard · 12 déc. 2026 », « Aujourd'hui »,
 * « Dans 3 j · 12 déc. 2026 » (une semaine ou moins, ton « soon »), sinon « Échéance 12 déc. 2026 ».
 */
export function dueStatus(ymd: string, overdue: boolean, now: Date = new Date()): { label: string; tone: DueTone } {
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const days = Math.round((parseDay(ymd).getTime() - today.getTime()) / 86400000);
  const date = formatDueDate(ymd);
  if (overdue || days < 0) return { label: `En retard · ${date}`, tone: 'late' };
  if (days === 0) return { label: "Aujourd'hui", tone: 'soon' };
  if (days <= 7) return { label: `Dans ${days} j · ${date}`, tone: 'soon' };
  return { label: `Échéance ${date}`, tone: 'normal' };
}
