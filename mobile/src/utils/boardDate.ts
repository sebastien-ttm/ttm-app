/** Date d'une réunion du CoDir : « mercredi 23 septembre 2026 · 20h00 ». */
export function formatMeetingDate(iso: string): string {
  const d = new Date(iso);
  const day = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const h = String(d.getHours()).padStart(2, '0');
  const m = String(d.getMinutes()).padStart(2, '0');
  return `${day} · ${h}h${m}`;
}
