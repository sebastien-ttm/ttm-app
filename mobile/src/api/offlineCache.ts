import AsyncStorage from '@react-native-async-storage/async-storage';
import { useSyncExternalStore } from 'react';

/**
 * Cache « hors connexion » des lectures (GET) de l'API.
 *
 * Chaque réponse GET réussie est conservée sur l'appareil ; quand le réseau est indisponible (ou ne répond pas à
 * temps), la dernière réponse connue est réaffichée à la place d'une erreur, avec un bandeau « hors connexion ».
 * Le cache n'est JAMAIS préféré au réseau : en ligne, les données sont toujours celles du serveur.
 *
 * Les entrées sont propres à l'utilisateur connecté (changer de profil lié ne montre pas les données du précédent)
 * et effacées à la déconnexion. Stockage : AsyncStorage (localStorage sur le web).
 */
const PREFIX = 'ttm.apicache.';
/** Au-delà, une réponse n'est pas conservée (quota de stockage du navigateur : ~5 Mo au total). */
const MAX_ENTRY_CHARS = 400_000;

let owner: number | null = null;

/** Utilisateur dont les entrées sont lues / écrites ; null = aucun (ni lecture ni écriture). */
export function setOfflineCacheOwner(userId: number | null): void {
  owner = userId;
}

/**
 * Chemins jamais conservés : état en direct (périmé en quelques secondes, dont les relectures « ?v=… » des écrans qui
 * se synchronisent), jetons, abonnements push.
 */
function isCacheable(path: string): boolean {
  return !/\/state(\?|$)/.test(path) && !/[?&]v=/.test(path) && !path.startsWith('/api/auth/') && !path.includes('/push');
}

function keyFor(path: string): string | null {
  return owner === null || !isCacheable(path) ? null : `${PREFIX}${owner}.${path}`;
}

export async function readCachedResponse<T>(path: string): Promise<T | undefined> {
  const key = keyFor(path);
  if (key === null) return undefined;
  try {
    const raw = await AsyncStorage.getItem(key);
    if (raw === null) return undefined;
    return (JSON.parse(raw) as { d: T }).d;
  } catch {
    return undefined;
  }
}

export function writeCachedResponse(path: string, data: unknown): void {
  const key = keyFor(path);
  if (key === null || data === undefined) return;
  try {
    const raw = JSON.stringify({ t: Date.now(), d: data });
    if (raw.length > MAX_ENTRY_CHARS) return;
    // Échec (quota, mode privé) sans conséquence : la réponse n'est simplement pas conservée.
    void AsyncStorage.setItem(key, raw).catch(() => undefined);
  } catch {
    /* donnée non sérialisable : ignorée */
  }
}

/** Efface tout le cache (déconnexion : les données d'un compte ne doivent pas rester sur l'appareil). */
export async function clearOfflineCache(): Promise<void> {
  try {
    const keys = await AsyncStorage.getAllKeys();
    const mine = keys.filter((k) => k.startsWith(PREFIX));
    if (mine.length > 0) await AsyncStorage.multiRemove(mine);
  } catch {
    /* sans importance */
  }
}

/* ------------------------------------------------------------------ état « hors connexion » */

let serving = false;
const listeners = new Set<() => void>();

function setServing(next: boolean): void {
  if (serving === next) return;
  serving = next;
  listeners.forEach((l) => l());
}

/** Une réponse vient d'être servie depuis le cache (le réseau ne répondait pas). */
export function markServedFromCache(): void {
  setServing(true);
}

/** Le serveur a répondu : on est de nouveau en ligne. */
export function markOnline(): void {
  setServing(false);
}

/** true tant que l'écran affiche des données du cache faute de réseau (bandeau « hors connexion »). */
export function useServedFromCache(): boolean {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => { listeners.delete(listener); };
    },
    () => serving,
    () => false,
  );
}
