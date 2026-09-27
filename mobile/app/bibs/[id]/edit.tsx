import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { bibs as bibsApi } from '@/api/resources';
import type { BibOffer } from '@/api/types';
import { BibForm, centsToInput } from '@/components/BibForm';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/** Modification d'une offre de dossards (auteur uniquement — 404 sinon côté API). */
export default function BibEditScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [offer, setOffer] = useState<BibOffer | null>(null);
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
      setOffer(await bibsApi.get(id));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error || !offer) return <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Modifier l\'offre' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <BibForm
            initial={{
              raceName: offer.raceName,
              raceDate: offer.raceDate,
              quantity: offer.quantity,
              exchangeType: offer.exchangeType,
              unitPrice: centsToInput(offer.unitPriceCents),
              negotiable: offer.negotiable,
              description: offer.description ?? '',
            }}
            submitLabel="Enregistrer"
            onSubmit={async (input) => {
              await bibsApi.update(offer.id, input);
              router.replace(('/bibs/' + offer.id) as never);
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
