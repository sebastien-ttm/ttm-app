import * as WebBrowser from 'expo-web-browser';
import { Platform } from 'react-native';

import { STORAGE_KEYS, storage } from '@/auth/storage';

/**
 * Ouvre une pièce jointe (PDF, image, GPX…) servie par un endpoint
 * authentifié : `buildUrl` reçoit le jeton d'accès et renvoie l'URL
 * (jeton en query `bearer`, cf. Lexik).
 *  - web : NOUVEL ONGLET ouvert directement sur l'URL, de façon
 *    synchrone pendant le clic (sur le web le jeton est dans
 *    localStorage, lisible sans attente). Surtout pas « onglet vide puis
 *    redirection » : dans une appli web installée sur l'écran d'accueil
 *    (iPhone), l'onglet s'ouvre dans Safari et ne peut plus être
 *    redirigé — il restait vide. Les GPX, envoyés en téléchargement par
 *    le serveur, déclenchent la demande de téléchargement du navigateur.
 *  - mobile : navigateur intégré.
 */
export async function openAttachment(buildUrl: (token: string | null) => string): Promise<void> {
  if (Platform.OS !== 'web') {
    await WebBrowser.openBrowserAsync(buildUrl(await storage.getItem(STORAGE_KEYS.accessToken)));
    return;
  }

  let token: string | null = null;
  try {
    token = window.localStorage.getItem(STORAGE_KEYS.accessToken);
  } catch {
    // Stockage indisponible (navigation privée) : URL sans jeton.
  }
  // « noopener » : l'onglet ouvert n'a aucun accès à l'appli.
  window.open(buildUrl(token), '_blank', 'noopener');
}

/** Ajoute le jeton en query `bearer` à une URL. */
export function withBearer(url: string, token: string | null): string {
  if (!token) return url;
  return `${url}${url.includes('?') ? '&' : '?'}bearer=${encodeURIComponent(token)}`;
}
