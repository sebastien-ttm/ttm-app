import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RaceForm } from '@/components/RaceForm';
import { COLORS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/** Modification d'une course proposée (auteur uniquement — 404 sinon côté API). */
export default function RaceEditScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [race, setRace] = useState<RaceProposal | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      setLoading(false);
      return;
    }
    try {
      setError(null);
      setRace(await racesApi.get(id));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error || !race) return <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Modifier la course' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <RaceForm
            initial={{
              name: race.name,
              raceDate: race.raceDate,
              url: race.url ?? '',
              captain: race.captain,
              type: race.type,
            }}
            submitLabel="Enregistrer"
            onSubmit={async (input) => {
              await racesApi.update(race.id, input);
              router.replace(('/races/' + race.id) as never);
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
