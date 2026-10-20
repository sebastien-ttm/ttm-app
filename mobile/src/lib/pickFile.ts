import { Platform } from 'react-native';

/** Le choix d'un fichier est disponible (appli web uniquement : pas de sélecteur de documents natif embarqué). */
export const canPickFile = Platform.OS === 'web';

/**
 * Ouvre le sélecteur de fichiers du navigateur. `accept` : extensions admises (« .gpx »). Résout avec le fichier choisi,
 * ou null si l'utilisateur annule.
 */
export function pickFile(accept: string): Promise<File | null> {
  return new Promise((resolve) => {
    if (!canPickFile || typeof document === 'undefined') {
      resolve(null);
      return;
    }
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.style.display = 'none';

    let settled = false;
    const done = (file: File | null) => {
      if (settled) return;
      settled = true;
      input.remove();
      resolve(file);
    };
    input.addEventListener('change', () => done(input.files?.[0] ?? null));
    input.addEventListener('cancel', () => done(null));
    document.body.appendChild(input);
    input.click();
  });
}
