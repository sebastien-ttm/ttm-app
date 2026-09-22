import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { surveys as surveysApi } from '@/api/resources';

/**
 * Compteur global des sondages ouverts auxquels le user n'a pas
 * encore répondu — alimente le 2e badge (couleur distincte) de
 * l'onglet Contact, indépendant du badge « messages non lus ».
 *
 * Même schéma provider/hook que useUnreadMessages : polling en tâche
 * de fond + refresh à la demande après soumission d'une réponse.
 */
type UnansweredSurveysContextValue = {
  count: number;
  refresh: () => Promise<void>;
};

const UnansweredSurveysContext = createContext<UnansweredSurveysContextValue>({
  count: 0,
  refresh: async () => {},
});

export function UnansweredSurveysProvider({ enabled, children }: { enabled: boolean; children: ReactNode }) {
  const [count, setCount] = useState(0);

  const refresh = useCallback(async () => {
    if (!enabled) return;
    try {
      const r = await surveysApi.unansweredCount();
      setCount(r.count);
    } catch {
      // Silencieux : un badge qui ne se met pas à jour est moins grave
      // qu'une erreur remontée à l'user pour un chiffre secondaire.
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      setCount(0);
      return;
    }
    void refresh();

    const interval = setInterval(refresh, 120_000);
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void refresh();
    });

    return () => {
      clearInterval(interval);
      sub.remove();
    };
  }, [enabled, refresh]);

  const value = useMemo(() => ({ count, refresh }), [count, refresh]);
  return createElement(UnansweredSurveysContext.Provider, { value }, children);
}

export function useUnansweredSurveys(): UnansweredSurveysContextValue {
  return useContext(UnansweredSurveysContext);
}
