import { useSyncExternalStore } from 'react';
import { Platform } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import { type GeoPoint, watchPoint } from '@/lib/geo';

/**
 * Partage en direct de la position d'un encadrant dans son groupe d'entraînement. Vit en dehors des écrans : le partage
 * continue quand l'encadrant change d'onglet (tant que l'appli reste ouverte) et s'arrête quand il le décide, quand le
 * groupe se termine ou quand l'appli est fermée (la position, plus mise à jour, disparaît alors des écrans des membres
 * au bout de quelques minutes).
 *
 * Sur le web, une page qui passe en arrière-plan (autre appli, écran verrouillé) est suspendue par le navigateur : le GPS
 * s'arrête. Pendant le partage, l'appli demande donc au navigateur de GARDER L'ÉCRAN ALLUMÉ (Screen Wake Lock), pour que
 * le suivi continue tant que l'appli reste affichée ; quand la page redevient visible, le verrou d'écran (que le
 * navigateur a libéré) et le suivi GPS (qu'il a pu couper) sont relancés.
 *
 * Les positions sont envoyées au plus toutes les SEND_EVERY_MS, même si le GPS en fournit plus souvent.
 */
const SEND_EVERY_MS = 15_000;

type Sharing = { groupId: number; stopWatch: () => void; lastSentAt: number };

/** Verrou d'écran (Screen Wake Lock API) : seul `release` et l'événement `release` nous servent. */
type WakeLockSentinelLike = {
  release: () => Promise<void>;
  addEventListener: (type: 'release', listener: () => void) => void;
};

let current: Sharing | null = null;
let lastError: string | null = null;
let wakeLock: WakeLockSentinelLike | null = null;
let snapshot = { groupId: null as number | null, error: null as string | null, awake: false };
const listeners = new Set<() => void>();

function publish(): void {
  snapshot = { groupId: current?.groupId ?? null, error: lastError, awake: wakeLock !== null };
  listeners.forEach((l) => l());
}

/** Le navigateur sait-il garder l'écran allumé ? (web uniquement : Chrome / Android, Safari 16.4+) */
export function wakeLockSupported(): boolean {
  return Platform.OS === 'web' && typeof navigator !== 'undefined' && 'wakeLock' in navigator;
}

/** Garde l'écran allumé pendant le partage. Sans effet si déjà actif, non pris en charge ou refusé (économie d'énergie…). */
async function acquireWakeLock(): Promise<void> {
  if (!wakeLockSupported() || wakeLock !== null || current === null) return;
  try {
    const api = (navigator as unknown as { wakeLock: { request: (type: 'screen') => Promise<WakeLockSentinelLike> } }).wakeLock;
    const sentinel = await api.request('screen');
    if (current === null || wakeLock !== null) {
      // Le partage s'est arrêté (ou un autre verrou est arrivé) pendant la demande.
      void sentinel.release().catch(() => undefined);
      return;
    }
    wakeLock = sentinel;
    // Le navigateur libère le verrou quand la page est masquée : on le sait, pour le redemander au retour.
    sentinel.addEventListener('release', () => {
      if (wakeLock === sentinel) {
        wakeLock = null;
        publish();
      }
    });
    publish();
  } catch {
    // Refusé (batterie faible, page masquée…) : le partage continue sans, l'écran peut alors s'éteindre.
  }
}

function releaseWakeLock(): void {
  const sentinel = wakeLock;
  wakeLock = null;
  if (sentinel !== null) void sentinel.release().catch(() => undefined);
}

function send(sharing: Sharing, point: GeoPoint): void {
  const now = Date.now();
  if (now - sharing.lastSentAt < SEND_EVERY_MS) return;
  sharing.lastSentAt = now;
  trainingGroups
    .setPosition(sharing.groupId, {
      latitude: point.latitude,
      longitude: point.longitude,
      ...(point.accuracy !== null ? { accuracy: Math.round(point.accuracy) } : {}),
    })
    .then(() => {
      if (lastError !== null) {
        lastError = null;
        publish();
      }
    })
    .catch((e: unknown) => {
      // Groupe terminé (ou plus encadrant) : plus rien à partager.
      if (e instanceof ApiError && e.status === 404) {
        void stopSharing(false);
      }
    });
}

/** Lance (ou relance) le suivi GPS de ce partage ; la prochaine position est envoyée sans attendre. */
function beginWatch(sharing: Sharing): void {
  sharing.lastSentAt = 0;
  sharing.stopWatch = watchPoint(
    (point) => send(sharing, point),
    (message) => {
      lastError = message;
      publish();
    },
  );
}

/**
 * La page redevient visible : le navigateur a libéré le verrou d'écran et peut avoir coupé le GPS pendant qu'elle était
 * masquée. On redemande l'un et on relance l'autre.
 */
function onVisibilityChange(): void {
  if (typeof document === 'undefined' || document.visibilityState !== 'visible' || current === null) return;
  void acquireWakeLock();
  current.stopWatch();
  beginWatch(current);
}

function listenVisibility(on: boolean): void {
  if (Platform.OS !== 'web' || typeof document === 'undefined') return;
  if (on) document.addEventListener('visibilitychange', onVisibilityChange);
  else document.removeEventListener('visibilitychange', onVisibilityChange);
}

/** Démarre le partage dans ce groupe (arrête celui d'un autre groupe s'il y en avait un). */
export function startSharing(groupId: number): void {
  if (current?.groupId === groupId) return;
  void stopSharing(true);
  lastError = null;
  const sharing: Sharing = { groupId, stopWatch: () => undefined, lastSentAt: 0 };
  current = sharing;
  beginWatch(sharing);
  listenVisibility(true);
  void acquireWakeLock();
  publish();
}

/** Arrête le partage ; `notifyServer` retire aussi la position des écrans des membres. */
export async function stopSharing(notifyServer: boolean): Promise<void> {
  const sharing = current;
  if (sharing === null) return;
  current = null;
  lastError = null;
  sharing.stopWatch();
  listenVisibility(false);
  releaseWakeLock();
  publish();
  if (notifyServer) {
    try {
      await trainingGroups.stopPosition(sharing.groupId);
    } catch {
      // Sans réseau : la position, plus mise à jour, finit par disparaître d'elle-même.
    }
  }
}

/**
 * Ce groupe est-il en cours de partage depuis cet appareil ? + dernière erreur de localisation + l'écran est-il maintenu
 * allumé (`awake`) pour que le suivi continue.
 */
export function useSharing(groupId: number): { sharing: boolean; error: string | null; awake: boolean } {
  const state = useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => { listeners.delete(listener); };
    },
    () => snapshot,
    () => snapshot,
  );
  const mine = state.groupId === groupId;
  return { sharing: mine, error: mine ? state.error : null, awake: mine && state.awake };
}
