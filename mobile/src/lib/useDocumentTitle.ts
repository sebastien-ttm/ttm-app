import { useEffect } from 'react';
import { Platform } from 'react-native';

const APP_NAME = 'TTM Toulouse Métropole';

/**
 * Sur le web, met à jour `document.title` (onglet navigateur) ET les
 * meta OG/Twitter (`og:title`, `twitter:title`, `og:description`,
 * `twitter:description`) pour que :
 *   - l'onglet navigateur affiche le nom de la page en cours ;
 *   - la prévisualisation d'un lien partagé (Slack, Twitter, WhatsApp
 *     iOS…) porte le titre de la page (côté crawler JS-capable) au
 *     lieu du seul « TTM — Triathlon Toulouse Métropole ».
 *
 * Sur natif (iOS/Android) : no-op — le titre du header est déjà géré
 * par expo-router via Stack.Screen options={{ title }}.
 *
 * Restaure les valeurs d'origine au unmount pour ne pas polluer le
 * titre entre deux navigations.
 */
export function useDocumentTitle(pageTitle: string | null | undefined, description?: string | null): void {
  useEffect(() => {
    if (Platform.OS !== 'web') return;
    if (typeof document === 'undefined') return;
    if (!pageTitle || pageTitle.trim() === '') return;

    const title = pageTitle.trim();
    const composedTitle = `${title} · ${APP_NAME}`;
    const originalTitle = document.title;

    document.title = composedTitle;

    const restorers: Array<() => void> = [() => { document.title = originalTitle; }];

    const setMeta = (selector: string, attr: 'content', value: string) => {
      const el = document.querySelector<HTMLMetaElement>(selector);
      if (el === null) return;
      const previous = el.getAttribute(attr) ?? '';
      el.setAttribute(attr, value);
      restorers.push(() => { el.setAttribute(attr, previous); });
    };

    setMeta('meta[property="og:title"]', 'content', composedTitle);
    setMeta('meta[name="twitter:title"]', 'content', composedTitle);
    if (description && description.trim() !== '') {
      const d = description.trim();
      setMeta('meta[name="description"]', 'content', d);
      setMeta('meta[property="og:description"]', 'content', d);
      setMeta('meta[name="twitter:description"]', 'content', d);
    }

    return () => {
      for (const restore of restorers) restore();
    };
  }, [pageTitle, description]);
}
