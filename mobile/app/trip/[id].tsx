import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { trips } from '@/api/resources';
import type { Article, TripDetail } from '@/api/types';
import { ArticleCard } from '@/components/ArticleCard';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { ContextHero, ContextSection, ParticipantsGrid } from '@/components/trips/ContextParts';
import { EventRow } from '@/components/UpcomingEvents';
import { COLORS, SPACING } from '@/config';
import { useDocumentTitle } from '@/lib/useDocumentTitle';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

/**
 * Vue de contexte d'un déplacement, réservée à ses participants : informations pratiques, événements du calendrier
 * rattachés (avec leur vote de présence), articles dédiés (absents du fil d'actualités) et liste des participants.
 * Ouverte depuis l'accueil ou depuis la notification reçue à l'ajout au déplacement.
 */
export default function TripScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id);

  const [trip, setTrip] = useState<TripDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const tripRef = useRef<TripDetail | null>(null);
  tripRef.current = trip;

  useDocumentTitle(trip?.title ?? 'Déplacement');

  const load = useCallback(async () => {
    if (!Number.isFinite(id)) return;
    try {
      setError(null);
      setTrip(await trips.get(id));
    } catch (err) {
      // 404 : déplacement retiré, dépublié, ou je n'en suis plus participant → écran « Contenu non autorisé ».
      if (err instanceof ApiError && (err.status === 403 || err.status === 404)) {
        router.replace({
          pathname: '/access-denied',
          params: { reason: err.status === 403 ? 'forbidden' : 'not-found' },
        } as never);
        return;
      }
      // Rechargement silencieux raté : on garde l'affichage actuel plutôt qu'un écran d'erreur.
      if (tripRef.current === null) setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [id, router]);

  // Au retour sur l'écran (vote sur un événement, lecture d'un article) et au retour de background : relecture.
  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  const markRead = useCallback((article: Article) => {
    setTrip((prev) => prev && { ...prev, articles: prev.articles.map((a) => (a.id === article.id ? { ...a, read: true } : a)) });
  }, []);

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <Stack.Screen options={{ title: trip?.title ?? 'Déplacement' }} />
      {loading && !trip ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : !trip ? null : (
        <ScrollView
          contentContainerStyle={styles.container}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} tintColor={COLORS.primary} />}
        >
          <ContextHero item={trip} fallbackIcon="airplane" />

          {trip.description || trip.events.length > 0 || trip.articles.length > 0 ? null : (
            <Text style={styles.empty}>Les informations de ce déplacement arriveront bientôt.</Text>
          )}

          {trip.description ? (
            <ContextSection title="Informations pratiques" icon="information-circle">
              <RichContent html={trip.description} style={styles.body} />
            </ContextSection>
          ) : null}

          {trip.events.length > 0 ? (
            <ContextSection title={trip.events.length > 1 ? 'Au programme' : 'Au programme : événement'} icon="calendar">
              {trip.events.map((e) => (
                <EventRow key={e.id} event={e} inset={false} />
              ))}
            </ContextSection>
          ) : null}

          {trip.articles.length > 0 ? (
            <ContextSection title={`Articles du déplacement (${trip.articles.length})`} icon="newspaper">
              {trip.articles.map((a) => (
                <ArticleCard key={a.id} article={a} onOpen={markRead} />
              ))}
            </ContextSection>
          ) : null}

          <ContextSection title={`Participants (${trip.participants.length})`} icon="people">
            <ParticipantsGrid participants={trip.participants} />
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
