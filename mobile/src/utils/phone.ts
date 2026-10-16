/** Numéro nettoyé pour un lien `tel:` : chiffres et « + » de tête uniquement. */
export function telHref(raw: string): string {
  const trimmed = raw.trim();
  const digits = trimmed.replace(/[^\d]/g, '');
  return `tel:${trimmed.startsWith('+') ? '+' : ''}${digits}`;
}

/**
 * Lien WhatsApp (https://wa.me/…) d'un numéro : indicatif pays sans « + » ni « 00 », le 0 national devient 33
 * (« 06 12 34 56 78 » → https://wa.me/33612345678). null si le numéro est manifestement invalide (< 8 chiffres).
 * Même règle que les `whatsappUrlFor` du backend (covoiturage, goûter).
 */
export function whatsappHref(raw: string): string | null {
  let digits = raw.replace(/[^\d+]/g, '');
  if (digits.startsWith('+')) digits = digits.slice(1);
  else if (digits.startsWith('00')) digits = digits.slice(2);
  else if (digits.startsWith('0')) digits = `33${digits.slice(1)}`;
  digits = digits.replace(/\D/g, '');
  return digits.length < 8 ? null : `https://wa.me/${digits}`;
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
