import { useRouter, useSegments } from 'expo-router';
import { useCallback, useEffect, useRef } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { notices as noticesApi } from '@/api/resources';
import { useAuth } from '@/auth/AuthContext';

/**
 * Vérifie qu'aucune notice ponctuelle n'est en attente d'acquittement
 * par le viewer, et le route vers /notice/{id} le cas échéant.
 *
 * Décorrélé du charter — n'interfère pas avec l'écran d'acceptation
 * de bienvenue de début de saison (celui-ci est géré par AuthGate en
 * amont, et charterRequired doit être false pour arriver ici).
 *
 * Trigger :
 *  - au montage (une fois l'user authentifié et le charter validé)
 *  - au retour de background (AppState 'active') SI l'appli est restée
 *    en background > INACTIVITY_MS (10 min) — les réouvertures rapides
 *    (< 10 min) ne re-poll pas pour éviter le bruit réseau.
 */
const INACTIVITY_MS = 10 * 60 * 1000;

export function NoticeGate() {
  const { status, charterRequired } = useAuth();
  const router = useRouter();
  const segments = useSegments();
  const backgroundedAtRef = useRef<number | null>(null);
  const lastCheckAtRef = useRef<number>(0);
  const inFlightRef = useRef<Promise<void> | null>(null);

  const enabled = status === 'authenticated' && !charterRequired;

  const check = useCallback(async () => {
    if (!enabled) return;
    // Ne pas double-fire si un check est déjà en vol.
    if (inFlightRef.current) {
      await inFlightRef.current;
      return;
    }
    // Débounce : max 1 check toutes les 3 sec (protection contre
    // multiples AppState events consécutifs sur certains devices).
    if (Date.now() - lastCheckAtRef.current < 3000) return;
    lastCheckAtRef.current = Date.now();

    const p = (async () => {
      try {
        const resp = await noticesApi.pending();
        if (resp.data.length === 0) return;
        // FIFO : première pending d'abord. On ne route QUE si on n'est
        // pas déjà sur un écran notice (évite le loop après ack).
        const inNoticeScreen = segments[0] === 'notice';
        if (inNoticeScreen) return;
        const next = resp.data[0];
        router.push(('/notice/' + next.id) as never);
      } catch {
        // Silencieux — pas critique. Sera re-tenté au prochain retour.
      }
    })();
    inFlightRef.current = p;
    try { await p; } finally { inFlightRef.current = null; }
  }, [enabled, router, segments]);

  // Check initial une fois auth + charter OK.
  useEffect(() => {
    if (enabled) void check();
    // volontairement sans check en dep : ne re-fire pas quand le
    // callback est ré-instancié (segments changent souvent).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled]);

  // AppState : capture bg → fg avec seuil d'inactivité.
  useEffect(() => {
    if (!enabled) return;
    const sub = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'background' || next === 'inactive') {
        backgroundedAtRef.current = Date.now();
        return;
      }
      if (next === 'active') {
        const bgAt = backgroundedAtRef.current;
        backgroundedAtRef.current = null;
        if (bgAt === null) return; // premier active (cold start) — déjà couvert par le mount
        if (Date.now() - bgAt < INACTIVITY_MS) return;
        void check();
      }
    });
    return () => sub.remove();
  }, [enabled, check]);

  return null;
}
