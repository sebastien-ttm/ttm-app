/** Numéro nettoyé pour un lien `tel:` : chiffres et « + » de tête uniquement. */
export function telHref(raw: string): string {
  const trimmed = raw.trim();
  const digits = trimmed.replace(/[^\d]/g, '');
  return `tel:${trimmed.startsWith('+') ? '+' : ''}${digits}`;
}

/**
 * Affichage lisible d'un numéro français : « 06 12 34 56 78 ». Les numéros
 * en +33 sont ramenés au format national ; tout autre format est laissé tel quel.
 */
export function formatPhoneFr(raw: string): string {
  const digits = raw.replace(/[^\d+]/g, '');
  const national = digits.startsWith('+33') ? `0${digits.slice(3)}` : digits.startsWith('0033') ? `0${digits.slice(4)}` : digits;
  return /^0\d{9}$/.test(national) ? national.replace(/(\d{2})(?=\d)/g, '$1 ') : raw.trim();
}
