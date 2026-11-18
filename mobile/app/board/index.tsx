import { Redirect, Stack } from 'expo-router';

/**
 * L'ordre du jour du Comité directeur n'est plus dans l'onglet Social mais dans l'espace Staff (onglet « Comité
 * directeur », src/components/gestion/BoardTab). Cette adresse reste valable pour les anciens liens et favoris.
 * Pas d'en-tête le temps de la redirection : il afficherait le nom de la route (« board »).
 */
export default function BoardMeetingsRedirect() {
  return (
    <>
      <Stack.Screen options={{ headerShown: false }} />
      <Redirect href="/gestion?tab=codir" />
    </>
  );
}
