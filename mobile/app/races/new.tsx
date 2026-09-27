import { Stack, useRouter } from 'expo-router';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { races as racesApi } from '@/api/resources';
import { RaceForm } from '@/components/RaceForm';
import { COLORS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/** Proposer une course : nom, date, site, type, « je suis capitaine ». */
export default function RaceNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Proposer une course' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <RaceForm
            submitLabel="Publier la proposition"
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
