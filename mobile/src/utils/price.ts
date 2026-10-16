/**
 * Prix d'une annonce en euros : « 45 € », « 45,50 € », « Gratuit » pour 0. Les centimes ne s'affichent que s'il y en a.
 */
export function formatPrice(price: number | null | undefined): string | null {
  if (price === null || price === undefined || !Number.isFinite(price)) return null;
  if (price === 0) return 'Gratuit';
  const hasCents = Math.round(price * 100) % 100 !== 0;
  return `${price.toLocaleString('fr-FR', { minimumFractionDigits: hasCents ? 2 : 0, maximumFractionDigits: 2 })} €`;
}

/**
 * Saisie du prix (« 45 », « 45,50 », « 45.5 € »…) → nombre, ou null si vide. `valid` est false si la saisie n'est pas
 * un montant positif à 2 décimales au plus.
 */
export function parsePriceInput(text: string): { value: number | null; valid: boolean } {
  const cleaned = text.replace(/[\s €]/g, '').replace(',', '.');
  if (cleaned === '') return { value: null, valid: true };
  if (!/^\d{1,6}(\.\d{1,2})?$/.test(cleaned)) return { value: null, valid: false };
  return { value: Number(cleaned), valid: true };
}
