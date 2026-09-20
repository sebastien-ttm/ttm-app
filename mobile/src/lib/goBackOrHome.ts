import { useRouter } from 'expo-router';
import { useCallback } from 'react';

/**
 * Retour arrière robuste — utilisable partout où l'user peut arriver
 * via un deep-link direct (URL partagée) sans aucune entrée dans
 * l'historique.
 *
 * router.back() est silencieusement ignoré quand canGoBack() est
 * false : le bouton « Retour » ne réagit pas et l'user est bloqué
 * sur la page. Ce hook fallback vers la home `/(tabs)` par défaut,
 * ou vers `fallbackPath` s'il est fourni (utile quand la page fait
 * partie d'un onglet spécifique — ex : les pages statiques sont
 * accédées depuis l'onglet « Club », donc fallback = /(tabs)/practical).
 *
 * Usage :
 *   const goBack = useGoBackOrHome();                        // → Actualités
 *   const goBack = useGoBackOrHome('/(tabs)/practical');     // → Club
 *   <Pressable onPress={goBack}>Retour</Pressable>
 */
export function useGoBackOrHome(fallbackPath: string = '/(tabs)'): () => void {
  const router = useRouter();
  return useCallback(() => {
    if (router.canGoBack()) {
      router.back();
      return;
    }
    router.replace(fallbackPath as never);
  }, [router, fallbackPath]);
}
