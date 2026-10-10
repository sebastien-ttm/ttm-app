import { Platform } from 'react-native';

/**
 * Enregistre le service worker de l'appli web (`/sw.js`) : notifications push,
 * page « hors ligne » et cache des fichiers de l'appli. Web uniquement, en
 * HTTPS (ou localhost) ; sans effet en développement, où le cache gênerait.
 */
export function registerServiceWorker(): void {
  if (Platform.OS !== 'web' || __DEV__) return;
  if (typeof window === 'undefined' || typeof navigator === 'undefined') return;
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;

  const register = () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
      // Échec silencieux : l'appli fonctionne sans service worker.
    });
  };
  if (document.readyState === 'complete') register();
  else window.addEventListener('load', register, { once: true });
}
