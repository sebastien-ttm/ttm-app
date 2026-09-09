import { useEffect, useRef } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

/**
 * Appelle `callback` chaque fois que l'appli revient au premier plan
 * après être restée en background AU MOINS `inactivityMs`.
 *
 * Nécessaire depuis que la session mobile dure 30 j (refresh token) :
 * sans cold-start, `useEffect`/`useFocusEffect` ne se re-déclenchent
 * pas et les onglets restent avec des données figées. Ce hook, ajouté
 * dans chaque onglet, force un refetch quand l'user revient dans
 * l'appli après une pause raisonnable (60 s par défaut).
 *
 * - Le callback est mémorisé dans un ref : peut être ré-instancié
 *   à chaque render (pas besoin de useCallback côté appelant).
 * - Le débounce interne (300 ms) évite de rejouer plusieurs fois
 *   sur les rafales AppState de certains devices.
 */
export function useRefreshOnResume(callback: () => void | Promise<void>, inactivityMs = 60_000) {
  const cbRef = useRef(callback);
  const backgroundedAtRef = useRef<number | null>(null);
  const lastFireRef = useRef<number>(0);

  useEffect(() => { cbRef.current = callback; }, [callback]);

  useEffect(() => {
    const sub = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'background' || next === 'inactive') {
        // Ne pas écraser un timestamp déjà posé (background → inactive
        // enchaîné) — on veut la vraie durée depuis le premier passage
        // en sortie de foreground.
        if (backgroundedAtRef.current === null) {
          backgroundedAtRef.current = Date.now();
        }
        return;
      }
      if (next === 'active') {
        const bgAt = backgroundedAtRef.current;
        backgroundedAtRef.current = null;
        if (bgAt === null) return; // premier active (cold start)
        if (Date.now() - bgAt < inactivityMs) return;
        if (Date.now() - lastFireRef.current < 300) return;
        lastFireRef.current = Date.now();
        void cbRef.current();
      }
    });
    return () => sub.remove();
  }, [inactivityMs]);
}
