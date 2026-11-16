import { Redirect } from 'expo-router';

/**
 * L'ordre du jour du Comité directeur n'est plus dans l'onglet Social mais dans l'espace Staff (onglet « Comité
 * directeur », src/components/gestion/BoardTab). Cette adresse reste valable pour les anciens liens et favoris.
 */
export default function BoardMeetingsRedirect() {
  return <Redirect href="/gestion?tab=codir" />;
}
