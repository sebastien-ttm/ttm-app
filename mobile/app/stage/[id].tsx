import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { stages } from '@/api/resources';
import type { Article, StageDetail } from '@/api/types';
import { ArticleCard } from '@/components/ArticleCard';
import { LinkedTrainingSlotCard } from '@/components/LinkedTrainingSlotCard';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { StagePerfTestCard } from '@/components/stages/StagePerfTestCard';
import { StageSurveyCard } from '@/components/stages/StageSurveyCard';
import { ContextHero, ContextSection, ParticipantsGrid } from '@/components/trips/ContextParts';
import { EventRow } from '@/components/UpcomingEvents';
import { COLORS, SPACING } from '@/config';
import { useDocumentTitle } from '@/lib/useDocumentTitle';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

/**
 * Vue de contexte d'un stage, réservée à ses participants : informations pratiques, puis TOUT ce qui est propre au
 * stage — entraînements (avec leur inscription), événements (avec leur vote de présence), sondages, prises de temps
 * et articles — et la liste des participants. Ces éléments ne figurent nulle part ailleurs dans l'appli. Ouverte
 * depuis l'accueil ou depuis la notification reçue à l'ajout au stage.
 */
export default function StageScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id);

  const [stage, setStage] = useState<StageDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const stageRef = useRef<StageDetail | null>(null);
  stageRef.current = stage;

  useDocumentTitle(stage?.title ?? 'Stage');

  const load = useCallback(async () => {
    if (!Number.isFinite(id)) return;
    try {
      setError(null);
      setStage(await stages.get(id));
    } catch (err) {
      // 404 : stage retiré, dépublié, ou je n'en suis plus participant → écran « Contenu non autorisé ».
      if (err instanceof ApiError && (err.status === 403 || err.status === 404)) {
        router.replace({
          pathname: '/access-denied',
          params: { reason: err.status === 403 ? 'forbidden' : 'not-found' },
        } as never);
        return;
      }
      // Rechargement silencieux raté : on garde l'affichage actuel plutôt qu'un écran d'erreur.
      if (stageRef.current === null) setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [id, router]);

  // Au retour sur l'écran (inscription à un entraînement, vote, réponse à un sondage, lecture d'un article) et au
  // retour de background : relecture, pour des états à jour.
  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  const markRead = useCallback((article: Article) => {
    setStage((prev) => prev && { ...prev, articles: prev.articles.map((a) => (a.id === article.id ? { ...a, read: true } : a)) });
  }, []);

  const hasContent = stage !== null && (
    stage.description || stage.trainingSlots.length > 0 || stage.events.length > 0 || stage.surveys.length > 0
    || stage.perfTests.length > 0 || stage.articles.length > 0
  );

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <Stack.Screen options={{ title: stage?.title ?? 'Stage' }} />
      {loading && !stage ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : !stage ? null : (
        <ScrollView
          contentContainerStyle={styles.container}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} tintColor={COLORS.primary} />}
        >
          <ContextHero item={stage} fallbackIcon="bonfire" />

          {hasContent ? null : <Text style={styles.empty}>Le programme de ce stage arrivera bientôt.</Text>}

          {stage.description ? (
            <ContextSection title="Informations pratiques" icon="information-circle">
              <RichContent html={stage.description} style={styles.body} />
            </ContextSection>
          ) : null}

          {stage.trainingSlots.length > 0 ? (
            <ContextSection title={`Entraînements (${stage.trainingSlots.length})`} icon="barbell">
              {stage.trainingSlots.map((s, i) => (
                <LinkedTrainingSlotCard key={`${s.id ?? 'v'}-${s.templateId ?? 'o'}-${i}`} slot={s} />
              ))}
            </ContextSection>
          ) : null}

          {stage.events.length > 0 ? (
            <ContextSection title={`Événements (${stage.events.length})`} icon="calendar">
              {stage.events.map((e) => (
                <EventRow key={e.id} event={e} inset={false} />
              ))}
            </ContextSection>
          ) : null}

          {stage.surveys.length > 0 ? (
            <ContextSection title={stage.surveys.length > 1 ? 'Sondages' : 'Sondage'} icon="bar-chart">
              {stage.surveys.map((s) => (
                <StageSurveyCard key={s.id} survey={s} />
              ))}
            </ContextSection>
          ) : null}

          {stage.perfTests.length > 0 ? (
            <ContextSection title={stage.perfTests.length > 1 ? 'Prises de temps' : 'Prise de temps'} icon="stopwatch">
              {stage.perfTests.map((t) => (
                <StagePerfTestCard key={t.id} test={t} />
              ))}
            </ContextSection>
          ) : null}

          {stage.articles.length > 0 ? (
            <ContextSection title={`Articles du stage (${stage.articles.length})`} icon="newspaper">
              {stage.articles.map((a) => (
                <ArticleCard key={a.id} article={a} onOpen={markRead} />
              ))}
            </ContextSection>
          ) : null}

          <ContextSection title={`Participants (${stage.participants.length})`} icon="people">
            <ParticipantsGrid participants={stage.participants} />
          </ContextSection>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: COLORS.background },
  container: { padding: SPACING.md, paddingBottom: 40, gap: SPACING.lg },
  empty: { textAlign: 'center', color: COLORS.textMuted, fontSize: 14, paddingVertical: SPACING.md },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 23 },
});
