import { Stack, useRouter } from 'expo-router';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { races as racesApi } from '@/api/resources';
import { RaceForm } from '@/components/RaceForm';
import { COLORS, SPACING } from '@/config';
import { useAuth } from '@/auth/AuthContext';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Proposer une compétition : nom, dates de début et de fin, courses, site, type, « je me propose d'être capitaine ».
 * Une proposition est relue par un administrateur avant d'être publiée aux adhérents (celles des administrateurs sont
 * publiées d'emblée) : la fiche, ouverte juste après l'envoi, indique « En attente de validation ».
 */
export default function RaceNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Proposer une compétition' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <RaceForm
            withCourses
            submitLabel={isAdmin ? 'Publier la proposition' : 'Envoyer pour validation'}
            notice={isAdmin ? undefined : "Votre proposition est relue par un administrateur avant d'être publiée aux adhérents. Vous serez prévenu(e) de sa décision."}
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
