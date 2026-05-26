/**
 * Mapping des entités HTML nommées les plus courantes (français + base).
 * Pour les entités numériques (&#XXX; / &#xXXX;) on a un décodeur générique
 * dans decodeEntities() ci-dessous.
 */
const NAMED_ENTITIES: Record<string, string> = {
  nbsp: ' ',
  amp: '&', lt: '<', gt: '>',
  quot: '"', apos: "'",
  // Accents français
  eacute: 'é', egrave: 'è', ecirc: 'ê', euml: 'ë',
  Eacute: 'É', Egrave: 'È', Ecirc: 'Ê', Euml: 'Ë',
  agrave: 'à', acirc: 'â', auml: 'ä', aring: 'å', aelig: 'æ',
  Agrave: 'À', Acirc: 'Â', Auml: 'Ä', Aring: 'Å', AElig: 'Æ',
  ugrave: 'ù', ucirc: 'û', uuml: 'ü',
  Ugrave: 'Ù', Ucirc: 'Û', Uuml: 'Ü',
  igrave: 'ì', icirc: 'î', iuml: 'ï',
  Igrave: 'Ì', Icirc: 'Î', Iuml: 'Ï',
  ograve: 'ò', ocirc: 'ô', ouml: 'ö', oelig: 'œ',
  Ograve: 'Ò', Ocirc: 'Ô', Ouml: 'Ö', OElig: 'Œ',
  ccedil: 'ç', Ccedil: 'Ç',
  ntilde: 'ñ', Ntilde: 'Ñ',
  // Ponctuation typographique
  laquo: '«', raquo: '»',
  lsquo: '‘', rsquo: '’',
  ldquo: '“', rdquo: '”',
  hellip: '…', mdash: '—', ndash: '–',
  copy: '©', reg: '®', trade: '™',
  middot: '·', bull: '•', deg: '°',
  euro: '€', pound: '£', yen: '¥', cent: '¢',
};

/** Décode toutes les entités HTML (nommées + numériques décimales/hexa). */
export function decodeEntities(s: string): string {
  return s
    .replace(/&#x([0-9a-fA-F]+);/g, (_, hex: string) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec: string) => String.fromCodePoint(parseInt(dec, 10)))
    .replace(/&([a-zA-Z]+);/g, (m, name: string) => NAMED_ENTITIES[name] ?? m);
}

/**
 * Minimal HTML → plain text converter for displaying article content
 * without pulling in a heavy renderer. Good enough for short editorial
 * content with paragraphs and lists.
 */
export function htmlToText(html: string): string {
  return decodeEntities(
    html
      .replace(/<\s*br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div|h[1-6])>/gi, '\n\n')
      .replace(/<li[^>]*>/gi, '• ')
      .replace(/<\/li>/gi, '\n')
      .replace(/<\/(ul|ol)>/gi, '\n')
      .replace(/<[^>]+>/g, ''),
  )
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

export function htmlExcerpt(html: string, max = 200): string {
  const txt = htmlToText(html);
  if (txt.length <= max) return txt;
  return txt.slice(0, max).replace(/\s+\S*$/, '') + '…';
}

export function formatDate(iso: string | null): string {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    return d.toLocaleDateString('fr-FR', {
      day: 'numeric',
      month: 'long',
      year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined,
    });
  } catch {
    return '';
  }
}

export function formatDateTime(iso: string | null): string {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    return d.toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
  } catch {
    return '';
  }
}

export function formatRelativeFr(iso: string | null): string {
  if (!iso) return '';
  const now = Date.now();
  const ts = new Date(iso).getTime();
  const diff = Math.round((now - ts) / 1000);
  if (diff < 60) return "à l'instant";
  if (diff < 3600) return `il y a ${Math.round(diff / 60)} min`;
  if (diff < 86400) return `il y a ${Math.round(diff / 3600)} h`;
  if (diff < 86400 * 7) return `il y a ${Math.round(diff / 86400)} j`;
  return formatDate(iso);
}
