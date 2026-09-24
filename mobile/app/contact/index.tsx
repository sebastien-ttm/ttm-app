import { Redirect } from 'expo-router';

/**
 * Ancienne adresse de l'onglet, rebaptisé « Social » (/social). Conservée
 * pour les favoris et raccourcis d'écran d'accueil existants.
 */
export default function LegacyContactTabRedirect() {
  return <Redirect href={'/social' as never} />;
}
