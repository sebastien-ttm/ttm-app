import { useSegments } from 'expo-router';
import { useCallback, useEffect, useRef } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { useAuth } from '@/auth/AuthContext';

/**
 * Refetch le statut d'acceptation de la charte au retour de background
 * après un temps d'inactivité — sans exiger que l'utilisateur ferme et
 * relance l'appli.
 *
 * Motivation : avec le refresh-token 30 jours, un adhérent peut rester
 * connecté plusieurs semaines sans jamais rouvrir « à froid ». Or
 * `fetchCharterStatus()` n'est appelé qu'au cold-start ou après un
 * login. Sans ce composant, un renouvellement CSV créant une nouvelle
 * ClubCharter pour la saison N+1 ne déclencherait jamais le tunnel
 * d'acceptation chez cet adhérent.
 *
 * L'AuthGate observe charterRequired et route automatiquement vers
 * /charter-acceptance dès qu'il passe à true — ici on ne fait que
 * rafraîchir l'info.
 *
 * Décorrélé du NoticeGate qui, lui, s'occupe des notices ponctuelles :
 * mêmes triggers (mount + AppState 'active' après > INACTIVITY_MS) mais
 * responsabilités distinctes.
 */
const INACTIVITY_MS = 10 * 60 * 1000;

export function CharterGate() {
  const { status, refreshCharterStatus } = useAuth();
  const segments = useSegments();
  const backgroundedAtRef = useRef<number | null>(null);
  const lastCheckAtRef = useRef<number>(0);
  const inFlightRef = useRef<Promise<void> | null>(null);

  const enabled = status === 'authenticated';
  // On évite de re-poll si l'user est déjà bloqué sur l'écran
  // d'acceptation — le charter est en cours d'ack, pas la peine de
  // re-fetch en boucle.
  const inCharterScreen = segments[0] === 'charter-acceptance';

  const check = useCallback(async () => {
    if (!enabled || inCharterScreen) return;
    if (inFlightRef.current) { await inFlightRef.current; return; }
    if (Date.now() - lastCheckAtRef.current < 3000) return; // débounce
    lastCheckAtRef.current = Date.now();

    const p = (async () => {
      try { await refreshCharterStatus(); } catch { /* silencieux — retry au prochain trigger */ }
    })();
    inFlightRef.current = p;
    try { await p; } finally { inFlightRef.current = null; }
  }, [enabled, inCharterScreen, refreshCharterStatus]);

  // Check initial au montage (une fois authentifié) — couvre le cas
  // où l'user vient de rentrer par un cold-start ; c'est déjà géré
  // par AuthContext lui-même mais rejouer ici est idempotent (débounce
  // de 3s + inFlight) et couvre les remontages du composant.
  useEffect(() => {
    if (enabled) void check();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled]);

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
        if (bgAt === null) return;
        if (Date.now() - bgAt < INACTIVITY_MS) return;
        void check();
      }
    });
    return () => sub.remove();
  }, [enabled, check]);

  return null;
}
