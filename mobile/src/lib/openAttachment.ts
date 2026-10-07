import * as WebBrowser from 'expo-web-browser';
import { Platform } from 'react-native';

/**
 * Ouvre une pièce jointe (PDF, image…) dont l'URL authentifiée se
 * construit de façon asynchrone (lecture du jeton) :
 *  - web : dans un NOUVEL ONGLET. L'onglet est ouvert immédiatement,
 *    pendant le clic, puis redirigé une fois l'URL prête — un
 *    window.open() appelé après un `await` est bloqué comme popup par
 *    Safari. (WebBrowser.openBrowserAsync ouvre une fenêtre popup.)
 *  - mobile : navigateur intégré, comme avant.
 */
export async function openAttachment(buildUrl: () => Promise<string>): Promise<void> {
  if (Platform.OS !== 'web') {
    await WebBrowser.openBrowserAsync(await buildUrl());
    return;
  }

  const tab = window.open('about:blank', '_blank');
  let url: string;
  try {
    url = await buildUrl();
  } catch (e) {
    tab?.close();
    throw e;
  }
  if (tab) {
    tab.opener = null; // l'onglet ouvert ne peut pas agir sur l'appli
    tab.location.href = url;
  } else {
    // Onglets bloqués par le navigateur : ouverture dans l'onglet courant.
    window.location.href = url;
  }
}

/**
 * Fichier à télécharger plutôt qu'à afficher (GPX…) sur le web : le
 * serveur l'envoie en « attachment », le navigateur le télécharge sans
 * quitter l'appli ni ouvrir d'onglet vide.
 */
export async function downloadAttachmentOnWeb(buildUrl: () => Promise<string>): Promise<void> {
  window.location.href = await buildUrl();
}
