import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RaceForm } from '@/components/RaceForm';
import { isRacePublished, raceStatus } from '@/components/RaceStatus';
import { COLORS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Modification d'une compétition proposée (auteur uniquement — 404 sinon côté API). Seuls les triathlons sont validés
 * par un administrateur : le formulaire prévient quand l'enregistrement renvoie la compétition en validation (refusée
 * corrigée, ou publiée devenue triathlon) ou, au contraire, la publie (en attente mais plus un triathlon).
 */
export default function RaceEditScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

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
      <Stack.Screen options={{ title: 'Modifier la compétition' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <RaceForm
            initial={{
              name: race.name,
              raceDate: race.raceDate,
              raceEndDate: race.raceEndDate ?? '',
              url: race.url ?? '',
              description: race.description ?? '',
              captain: race.captain,
              registrationDeadline: race.registrationDeadline ?? '',
              carpoolingEnabled: race.carpoolingEnabled,
              type: race.type,
            }}
            submitLabel={(t) => (!isAdmin && raceStatus(race) === 'refused' && t === 'triathlon' ? 'Renvoyer pour validation' : 'Enregistrer')}
            notice={(t) => {
              if (isAdmin) return undefined;
              const status = raceStatus(race);
              if (isRacePublished(race) && race.type !== 'triathlon' && t === 'triathlon') {
                return "Passer en triathlon renvoie la compétition en validation : les autres adhérents ne la verront plus tant qu'un administrateur ne l'a pas validée.";
              }
              if (status === 'refused' && t === 'triathlon') return 'En enregistrant, votre proposition repart en validation.';
              if ((status === 'refused' || status === 'pending') && t !== 'triathlon') {
                return "Seuls les triathlons sont relus par un administrateur : hors triathlon, la compétition sera publiée dès l'enregistrement.";
              }
              return undefined;
            }}
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
