import { Stack, useRouter } from 'expo-router';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { bibs as bibsApi } from '@/api/resources';
import { BibForm } from '@/components/BibForm';
import { COLORS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/** Proposer des dossards (don ou revente). */
export default function BibNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Proposer des dossards' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <BibForm
            submitLabel="Publier l'offre"
            onSubmit={async (input) => {
              const created = await bibsApi.create(input);
              router.replace(('/bibs/' + created.id) as never);
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
