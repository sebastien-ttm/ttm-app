import { Stack, useLocalSearchParams } from 'expo-router';
import { StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { YouthDocumentsView } from '@/components/youth/YouthDocumentsView';
import { COLORS } from '@/config';

/**
 * Documents officiels d'un jeune (fiche sanitaire, autorisation de sortie du territoire). Ouvert par le jeune lui-même
 * (profil), ses parents (Ma famille), ou le staff (onglet « Jeunes », annuaire, groupe d'entraînement).
 */
export default function YouthDocumentsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Documents du jeune' }} />
      <YouthDocumentsView youthId={Number(id)} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
});
