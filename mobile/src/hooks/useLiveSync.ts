import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';

/**
 * Réponse d'un endpoint « état en direct » du Staff : soit l'état complet avec sa `version`,
 * soit `{ unchanged: true }` quand la version envoyée est toujours la bonne (voir
 * StaffLiveStateTrait côté serveur).
 */
export type LiveState<T> = { version: string; unchanged: true } | ({ version: string; unchanged?: undefined } & T);

type Options<T> = {
  /** Faux : pas d'actualisation (ex : l'écran n'a pas encore chargé sa feuille). */
  enabled?: boolean;
  /** Délai entre deux actualisations (ms). */
  intervalMs?: number;
  /** Interroge le serveur ; `version` = dernière version reçue (null la première fois). */
  fetchState: (version: string | null) => Promise<LiveState<T>>;
  /** Appelé avec l'état complet quand il a changé. */
  onState: (state: { version: string } & T) => void;
};

/**
 * Synchronise un écran avec les modifications des autres utilisateurs : l'état est redemandé
 * toutes les quelques secondes. L'actualisation s'arrête quand l'écran n'est plus au premier plan
 * (autre écran, appli en arrière-plan, onglet masqué) et reprend aussitôt au retour.
 *
 * Après une erreur, l'intervalle s'allonge ; au bout de 2 échecs d'affilée `online` passe à false
 * (l'écran affiche « Hors ligne ») puis repasse à true dès qu'une réponse arrive.
 *
 * `resync()` oublie la version connue : la prochaine actualisation renvoie l'état complet. À appeler
 * après une action locale, pour que l'écran se recale sur le serveur même si rien n'a changé ailleurs.
 */
export function useLiveSync<T>({ enabled = true, intervalMs = 4000, fetchState, onState }: Options<T>) {
  const [focused, setFocused] = useState(true);
  const [appActive, setAppActive] = useState(AppState.currentState !== 'background');
  const [online, setOnline] = useState(true);
  const versionRef = useRef<string | null>(null);
  // Toujours la dernière version des fonctions, sans relancer la boucle à chaque rendu.
  const fetchRef = useRef(fetchState);
  const onStateRef = useRef(onState);
  fetchRef.current = fetchState;
  onStateRef.current = onState;

  useFocusEffect(useCallback(() => {
    setFocused(true);
    return () => setFocused(false);
  }, []));

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => setAppActive(state === 'active'));
    return () => subscription.remove();
  }, []);

  useEffect(() => {
    if (!enabled || !focused || !appActive) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let failures = 0;

    const tick = async () => {
      try {
        const response = await fetchRef.current(versionRef.current);
        if (cancelled) return;
        failures = 0;
        setOnline(true);
        versionRef.current = response.version;
        if (!response.unchanged) onStateRef.current(response as { version: string } & T);
      } catch {
        if (cancelled) return;
        failures += 1;
        if (failures >= 2) setOnline(false);
      }
      if (!cancelled) timer = setTimeout(tick, failures > 0 ? Math.min(intervalMs * 3, 15000) : intervalMs);
    };

    void tick(); // dès l'ouverture ou le retour sur l'écran : état à jour sans attendre
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [enabled, focused, appActive, intervalMs]);

  const resync = useCallback(() => { versionRef.current = null; }, []);

  return { online, resync };
}

/**
 * Surlignage temporaire des lignes modifiées par quelqu'un d'autre : `flash([ids])` les
 * ajoute à `flashed`, qui les retire d'elle-même après `durationMs`.
 */
export function useFlashRows(durationMs = 2500) {
  const [flashed, setFlashed] = useState<Set<number>>(new Set());
  const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

  const flash = useCallback((ids: number[]) => {
    if (ids.length === 0) return;
    setFlashed((current) => {
      const next = new Set(current);
      ids.forEach((id) => next.add(id));
      return next;
    });
    for (const id of ids) {
      const previous = timers.current.get(id);
      if (previous) clearTimeout(previous);
      timers.current.set(id, setTimeout(() => {
        timers.current.delete(id);
        setFlashed((current) => {
          const next = new Set(current);
          next.delete(id);
          return next;
        });
      }, durationMs));
    }
  }, [durationMs]);

  useEffect(() => {
    const pending = timers.current;
    return () => pending.forEach((timer) => clearTimeout(timer));
  }, []);

  return { flashed, flash };
}
