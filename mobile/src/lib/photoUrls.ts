import { useCallback, useEffect, useState } from 'react';

import { STORAGE_KEYS, storage } from '@/auth/storage';
import { API_BASE_URL } from '@/config';

/**
 * URLs des images de « Photos du club ». Les albums Piwigo sont privés :
 * les images passent par le backend, qui exige d'être connecté. Une
 * balise <img> / expo-image ne peut pas toujours envoyer d'en-tête
 * Authorization (web), d'où le jeton en paramètre ?bearer= — même
 * mécanisme que les pièces jointes des articles.
 *
 * `reload()` relit le jeton (à appeler après un appel API, qui a pu le
 * rafraîchir).
 */
export function usePhotoUrls() {
  const [token, setToken] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setToken(await storage.getItem(STORAGE_KEYS.accessToken));
  }, []);

  useEffect(() => { void reload(); }, [reload]);

  const url = useCallback(
    (imageId: number, variant: 'grid' | 'full') =>
      token === null
        ? null
        : `${API_BASE_URL}/api/photos/images/${imageId}/${variant}?bearer=${encodeURIComponent(token)}`,
    [token],
  );

  return { url, reload };
}
