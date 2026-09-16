import { useRouter } from 'expo-router';
import { useCallback } from 'react';

/**
 * Retour arrière robuste — utilisable partout où l'user peut arriver
 * via un deep-link direct (URL partagée) sans aucune entrée dans
 * l'historique.
 *
 * router.back() est silencieusement ignoré quand canGoBack() est
 * false : le bouton « Retour » ne réagit pas et l'user est bloqué
 * sur la page. Ce hook fallback vers la home `/(tabs)` dans ce cas.
 *
 * Usage :
 *   const goBack = useGoBackOrHome();
 *   <Pressable onPress={goBack}>Retour</Pressable>
 */
export function useGoBackOrHome(): () => void {
  const router = useRouter();
  return useCallback(() => {
    if (router.canGoBack()) {
      router.back();
      return;
    }
    router.replace('/(tabs)' as never);
  }, [router]);
}
