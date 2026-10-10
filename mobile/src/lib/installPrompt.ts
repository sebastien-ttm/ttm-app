import { Platform } from 'react-native';

/**
 * Installation de l'appli web (PWA) sur l'écran d'accueil.
 *
 * Chrome (Android, ordinateur) prévient la page par l'événement `beforeinstallprompt`,
 * qu'il faut garder de côté pour proposer notre propre bouton « Installer ». Cet
 * événement n'arrive qu'une fois, parfois très tôt : `listenForInstallPrompt()` doit
 * donc être appelée au démarrage de l'appli (voir app/_layout.tsx), pas à l'ouverture
 * du profil. Safari (iPhone / iPad) n'a pas cet événement : on n'y peut qu'expliquer
 * la marche à suivre. Tout ce module est sans effet hors du web.
 */

interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

/**
 *  - hidden : rien à proposer (appli native, déjà installée, ou navigateur sans installation) ;
 *  - native : le navigateur sait installer l'appli en un geste (bouton « Installer ») ;
 *  - ios    : iPhone / iPad : passer par le bouton Partager de Safari ;
 *  - manual : Android sans proposition automatique : passer par le menu du navigateur.
 */
export type InstallMode = 'hidden' | 'native' | 'ios' | 'manual';

export type InstallOutcome = 'accepted' | 'dismissed' | 'unavailable';

let deferred: BeforeInstallPromptEvent | null = null;
let installed = false;
let listening = false;
const listeners = new Set<() => void>();

function isWeb(): boolean {
  return Platform.OS === 'web' && typeof window !== 'undefined' && typeof navigator !== 'undefined';
}

function notify(): void {
  listeners.forEach((listener) => listener());
}

function isIosDevice(): boolean {
  // iPadOS se présente comme un Mac tactile.
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

/** L'appli tourne-t-elle déjà depuis l'écran d'accueil (hors barre du navigateur) ? */
function isInstalledApp(): boolean {
  const standaloneIos = (navigator as unknown as { standalone?: boolean }).standalone === true;
  return standaloneIos
    || (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches);
}

/** À appeler une fois au démarrage : garde l'événement d'installation et suit l'installation. */
export function listenForInstallPrompt(): void {
  if (!isWeb() || listening) return;
  listening = true;

  window.addEventListener('beforeinstallprompt', (event) => {
    // Empêche le bandeau automatique du navigateur : l'installation passe par notre bouton.
    event.preventDefault();
    deferred = event as BeforeInstallPromptEvent;
    notify();
  });
  window.addEventListener('appinstalled', () => {
    installed = true;
    deferred = null;
    notify();
  });
}

export function getInstallMode(): InstallMode {
  if (!isWeb() || installed || isInstalledApp()) return 'hidden';
  if (deferred) return 'native';
  if (isIosDevice()) return 'ios';
  if (/Android/i.test(navigator.userAgent)) return 'manual';
  return 'hidden';
}

/** Suit les changements de `getInstallMode()` ; renvoie la fonction de désabonnement. */
export function subscribeInstallMode(listener: () => void): () => void {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}

/** Ouvre la fenêtre d'installation du navigateur (mode « native »). */
export async function requestInstall(): Promise<InstallOutcome> {
  const event = deferred;
  if (!event) return 'unavailable';
  // L'événement ne sert qu'une fois ; en cas de refus, le navigateur n'en renvoie pas tout de suite.
  deferred = null;
  try {
    await event.prompt();
    const { outcome } = await event.userChoice;
    if (outcome === 'accepted') installed = true;
    return outcome;
  } finally {
    notify();
  }
}
