import { storage } from '@/auth/storage';

/**
 * « Nouveautés non vues » des listes de l'onglet Social (courses
 * proposées, bourse aux équipements, bourse aux dossards).
 *
 * On mémorise, par liste et par utilisateur, le plus grand id affiché
 * la dernière fois que la liste a été ouverte (les ids sont croissants
 * côté serveur, donc insensible aux horloges). Est « nouvelle » toute
 * entrée d'id supérieur, publiée par quelqu'un d'autre. Stockage local
 * à l'appareil (localStorage / SecureStore).
 *
 * Une entrée modérée (compétitions proposées) peut être publiée longtemps après sa création : elle porte alors un
 * `publishedRank` — nombre croissant, toujours supérieur aux ids existants à sa validation — qui sert de repère à la
 * place de l'id (`seenMark`).
 */
export type SeenList = 'races' | 'marketplace' | 'bibs' | 'photos';

function key(list: SeenList, userId: number): string {
  return `ttm.seen.${list}.${userId}`;
}

/** Repère croissant d'une entrée : son rang de publication s'il en a un, sinon son id. */
export function seenMark(item: { id: number; publishedRank?: number | null }): number {
  return item.publishedRank ?? item.id;
}

/** Plus grand id déjà vu, ou null si la liste n'a jamais été ouverte. */
export async function getLastSeenId(list: SeenList, userId: number): Promise<number | null> {
  const raw = await storage.getItem(key(list, userId));
  const n = raw === null ? NaN : Number(raw);
  return Number.isFinite(n) ? n : null;
}

/** À appeler quand la liste vient d'être affichée avec ces entrées. */
export async function markSeen(list: SeenList, userId: number, ids: number[]): Promise<void> {
  if (ids.length === 0) return;
  const previous = (await getLastSeenId(list, userId)) ?? 0;
  const max = Math.max(previous, ...ids);
  if (max > previous) await storage.setItem(key(list, userId), String(max));
}

/**
 * Nombre d'entrées non vues. Jamais ouverte → tout ce qui n'est pas à
 * moi compte comme nouveau. `authorId` absent → compté (on ne peut pas
 * savoir que c'est la mienne).
 */
export function countUnseen(
  items: { id: number; authorId?: number; publishedRank?: number | null }[],
  lastSeenId: number | null,
  userId: number,
): number {
  return items.filter((i) => i.authorId !== userId && (lastSeenId === null || seenMark(i) > lastSeenId)).length;
}
