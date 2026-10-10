import { Platform } from 'react-native';

import { webPush as webPushApi } from '@/api/resources';

/**
 * Notifications push WEB (appli installée / navigateur), par Web Push + VAPID.
 * Les notifications natives (iOS / Android) restent gérées par
 * registerForPush.ts. Tout ce module est sans effet hors du web.
 *
 * États d'un appareil :
 *  - unsupported   : navigateur sans service worker / Push API ;
 *  - needs-install : iPhone/iPad hors appli installée — Safari n'autorise les
 *                    notifications que pour une appli ajoutée à l'écran d'accueil ;
 *  - unconfigured  : le serveur n'a pas (encore) de clés VAPID ;
 *  - denied        : l'adhérent a bloqué les notifications dans le navigateur ;
 *  - off / on      : autorisées ou non sur cet appareil.
 */
export type WebPushState = 'unsupported' | 'needs-install' | 'unconfigured' | 'denied' | 'off' | 'on';

/** Désactivation volontaire sur cet appareil : la resynchronisation automatique doit la respecter. */
const OPT_OUT_KEY = 'ttm.webpush.optout';
const READY_TIMEOUT_MS = 8000;

function isWeb(): boolean {
  return Platform.OS === 'web' && typeof window !== 'undefined' && typeof navigator !== 'undefined';
}

function isIosDevice(): boolean {
  const ua = navigator.userAgent;
  // iPadOS se présente comme un Mac tactile.
  return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isInstalledApp(): boolean {
  const standaloneIos = (navigator as unknown as { standalone?: boolean }).standalone === true;
  return standaloneIos || (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches);
}

function pushSupported(): boolean {
  return isWeb() && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function readOptOut(): boolean {
  try { return window.localStorage.getItem(OPT_OUT_KEY) === '1'; } catch { return false; }
}

function writeOptOut(value: boolean): void {
  try {
    if (value) window.localStorage.setItem(OPT_OUT_KEY, '1');
    else window.localStorage.removeItem(OPT_OUT_KEY);
  } catch { /* stockage indisponible : sans conséquence */ }
}

/** Clé publique VAPID (base64url) → octets attendus par PushManager.subscribe(). */
function urlBase64ToUint8Array(base64Url: string): Uint8Array {
  const padded = base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4);
  const raw = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

/** Service worker prêt — avec délai maximal (il n'existe pas en développement). */
async function readyRegistration(): Promise<ServiceWorkerRegistration> {
  const timeout = new Promise<never>((_, reject) => {
    setTimeout(() => reject(new Error('Service worker indisponible.')), READY_TIMEOUT_MS);
  });
  return Promise.race([navigator.serviceWorker.ready, timeout]);
}

async function subscribeBrowser(publicKey: string): Promise<PushSubscription> {
  const registration = await readyRegistration();
  const existing = await registration.pushManager.getSubscription();
  if (existing) return existing;
  return registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  });
}

/** État actuel des notifications push web sur cet appareil. */
export async function getWebPushState(): Promise<WebPushState> {
  if (!isWeb()) return 'unsupported';
  if (!pushSupported()) return isIosDevice() && !isInstalledApp() ? 'needs-install' : 'unsupported';

  try {
    const config = await webPushApi.config();
    if (!config.enabled) return 'unconfigured';
  } catch {
    return 'unsupported';
  }
  if (Notification.permission === 'denied') return 'denied';

  try {
    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = registration ? await registration.pushManager.getSubscription() : null;
    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
  } catch {
    return 'off';
  }
}

/**
 * Active les notifications sur cet appareil. À appeler depuis un appui de
 * l'adhérent (les navigateurs refusent la demande d'autorisation sinon).
 */
export async function enableWebPush(): Promise<WebPushState> {
  if (!pushSupported()) return getWebPushState();

  const config = await webPushApi.config();
  if (!config.enabled || !config.publicKey) return 'unconfigured';

  const permission = await Notification.requestPermission();
  if (permission === 'denied') return 'denied';
  if (permission !== 'granted') return 'off';

  const subscription = await subscribeBrowser(config.publicKey);
  await webPushApi.subscribe(subscription.toJSON());
  writeOptOut(false);
  return 'on';
}

/** Désactive volontairement les notifications sur cet appareil. */
export async function disableWebPush(): Promise<WebPushState> {
  writeOptOut(true);
  if (!pushSupported()) return getWebPushState();

  try {
    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = registration ? await registration.pushManager.getSubscription() : null;
    if (subscription) {
      // Serveur d'abord (il faut encore être connecté), puis le navigateur.
      await webPushApi.unsubscribe(subscription.endpoint).catch(() => undefined);
      await subscription.unsubscribe();
    }
  } catch {
    // Le serveur supprimera l'abonnement au prochain envoi (410).
  }
  return getWebPushState();
}

/**
 * À chaque connexion / ouverture de l'appli : si les notifications sont
 * autorisées sur cet appareil (et pas désactivées volontairement), s'assure
 * que l'abonnement existe et que le serveur le rattache à l'adhérent connecté.
 */
export async function syncWebPush(): Promise<void> {
  try {
    if (!pushSupported() || Notification.permission !== 'granted' || readOptOut()) return;
    const config = await webPushApi.config();
    if (!config.enabled || !config.publicKey) return;
    const subscription = await subscribeBrowser(config.publicKey);
    await webPushApi.subscribe(subscription.toJSON());
  } catch {
    // Au mieux : un échec ne doit jamais gêner la connexion.
  }
}

/**
 * À la déconnexion : retire l'abonnement du navigateur pour que l'appareil
 * ne reçoive plus les notifications du compte qui vient de se déconnecter
 * (le serveur nettoie sa ligne au prochain envoi). Aucun appel réseau : la
 * session est déjà terminée, et la prochaine connexion se réabonne seule.
 */
export function detachWebPush(): void {
  if (!pushSupported()) return;
  navigator.serviceWorker.getRegistration('/')
    .then((registration) => registration?.pushManager.getSubscription())
    .then((subscription) => subscription?.unsubscribe())
    .catch(() => undefined);
}

/** Envoie une notification de test à mes appareils (diagnostic). */
export async function sendTestWebPush(): Promise<{ sent: number; failed: number }> {
  return webPushApi.test();
}
