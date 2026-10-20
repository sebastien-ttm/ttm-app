import { Stack, useLocalSearchParams } from 'expo-router';
import { StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { GroupView } from '@/components/groups/GroupView';
import { COLORS } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Groupe d'entraînement « à la volée » : pour un adhérent, informations, encadrants, autres membres, trace GPX,
 * commentaires et position des encadrants ; pour un encadrant du groupe, sa gestion. Ouvert depuis l'onglet
 * Entraînement ou depuis la notification reçue quand un encadrant ajoute l'adhérent à son groupe.
 */
export default function TrainingGroupScreen() {
  const goBack = useGoBackOrHome();
  const { id } = useLocalSearchParams<{ id: string }>();

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Groupe d\'entraînement' }} />
      <GroupView groupId={Number(id)} onGone={goBack} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
});
