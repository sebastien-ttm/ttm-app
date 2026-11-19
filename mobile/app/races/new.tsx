import { Stack, useRouter } from 'expo-router';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { races as racesApi } from '@/api/resources';
import type { RaceType } from '@/api/types';
import { RaceForm } from '@/components/RaceForm';
import { COLORS, SPACING } from '@/config';
import { useAuth } from '@/auth/AuthContext';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Proposer une compétition : nom, dates de début et de fin, courses, site, type, « je me propose d'être capitaine ».
 * Un triathlon est relu par un administrateur avant d'être publié aux adhérents (celui d'un administrateur est publié
 * d'emblée) : la fiche, ouverte juste après l'envoi, indique « Soumise à validation ». Les autres types de course sont
 * publiés tout de suite.
 */
export default function RaceNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';
  // Seuls les triathlons passent par la validation d'un administrateur.
  const needsApproval = (type: RaceType | null) => !isAdmin && type === 'triathlon';

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Proposer une compétition' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <RaceForm
            withCourses
            submitLabel={(t) => (needsApproval(t) ? 'Envoyer pour validation' : 'Publier la proposition')}
            notice={(t) => (needsApproval(t)
              ? "Un triathlon est relu par un administrateur avant d'être publié aux adhérents. Vous serez prévenu(e) de sa décision par notification et par e-mail ; en cas de refus, le motif vous sera indiqué."
              : undefined)}
            onSubmit={async (input) => {
              const created = await racesApi.create(input);
              router.replace(('/races/' + created.id) as never);
            }}
            onCancel={goBack}
          />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
});
