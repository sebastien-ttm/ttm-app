import { useSyncExternalStore } from 'react';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import { type GeoPoint, watchPoint } from '@/lib/geo';

/**
 * Partage en direct de la position d'un encadrant dans son groupe d'entraînement. Vit en dehors des écrans : le partage
 * continue quand l'encadrant change d'onglet (tant que l'appli reste ouverte) et s'arrête quand il le décide, quand le
 * groupe se termine ou quand l'appli est fermée (la position, plus mise à jour, disparaît alors des écrans des membres
 * au bout de quelques minutes).
 *
 * Les positions sont envoyées au plus toutes les SEND_EVERY_MS, même si le GPS en fournit plus souvent.
 */
const SEND_EVERY_MS = 15_000;

type Sharing = { groupId: number; stopWatch: () => void; lastSentAt: number };

let current: Sharing | null = null;
let lastError: string | null = null;
let snapshot = { groupId: null as number | null, error: null as string | null };
const listeners = new Set<() => void>();

function publish(): void {
  snapshot = { groupId: current?.groupId ?? null, error: lastError };
  listeners.forEach((l) => l());
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

/** Démarre le partage dans ce groupe (arrête celui d'un autre groupe s'il y en avait un). */
export function startSharing(groupId: number): void {
  if (current?.groupId === groupId) return;
  void stopSharing(true);
  lastError = null;
  const sharing: Sharing = { groupId, stopWatch: () => undefined, lastSentAt: 0 };
  sharing.stopWatch = watchPoint(
    (point) => send(sharing, point),
    (message) => {
      lastError = message;
      publish();
    },
  );
  current = sharing;
  publish();
}

/** Arrête le partage ; `notifyServer` retire aussi la position des écrans des membres. */
export async function stopSharing(notifyServer: boolean): Promise<void> {
  const sharing = current;
  if (sharing === null) return;
  current = null;
  lastError = null;
  sharing.stopWatch();
  publish();
  if (notifyServer) {
    try {
      await trainingGroups.stopPosition(sharing.groupId);
    } catch {
      // Sans réseau : la position, plus mise à jour, finit par disparaître d'elle-même.
    }
  }
}

/** Ce groupe est-il en cours de partage depuis cet appareil ? (+ dernière erreur de localisation) */
export function useSharing(groupId: number): { sharing: boolean; error: string | null } {
  const state = useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => { listeners.delete(listener); };
    },
    () => snapshot,
    () => snapshot,
  );
  return { sharing: state.groupId === groupId, error: state.groupId === groupId ? state.error : null };
}
