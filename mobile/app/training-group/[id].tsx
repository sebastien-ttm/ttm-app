import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback } from 'react';
import { StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { GroupView } from '@/components/groups/GroupView';
import { COLORS } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Groupe d'entraînement « à la volée » : pour un adhérent, informations, encadrants, autres membres, trace GPX,
 * commentaires et positions partagées ; pour un encadrant du groupe, sa gestion (avec « Sauvegarder », qui revient à
 * l'onglet Entraînements). Ouvert depuis l'onglet Entraînement ou depuis la notification reçue quand un encadrant ajoute
 * l'adhérent à son groupe.
 */
export default function TrainingGroupScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const toTraining = useCallback(() => router.navigate('/(tabs)/training' as never), [router]);
  const { id } = useLocalSearchParams<{ id: string }>();

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Groupe d\'entraînement' }} />
      <GroupView groupId={Number(id)} onGone={goBack} onDone={toTraining} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
});
