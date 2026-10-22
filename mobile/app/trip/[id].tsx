import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useRef, useState, type ComponentProps, type ReactNode } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { trips } from '@/api/resources';
import type { Article, TripDetail } from '@/api/types';
import { ArticleCard } from '@/components/ArticleCard';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { RichContent } from '@/components/RichContent';
import { EventRow } from '@/components/UpcomingEvents';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useDocumentTitle } from '@/lib/useDocumentTitle';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { tripDatesLabel, tripPhase, tripStatusLabel } from '@/utils/trip';

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
          <TripHero trip={trip} />

          {trip.description || trip.events.length > 0 || trip.articles.length > 0 ? null : (
            <Text style={styles.empty}>Les informations de ce déplacement arriveront bientôt.</Text>
          )}

          {trip.description ? (
            <Section title="Informations pratiques" icon="information-circle">
              <RichContent html={trip.description} style={styles.body} />
            </Section>
          ) : null}

          {trip.events.length > 0 ? (
            <Section title={trip.events.length > 1 ? 'Au programme' : 'Au programme : événement'} icon="calendar">
              {trip.events.map((e) => (
                <EventRow key={e.id} event={e} inset={false} />
              ))}
            </Section>
          ) : null}

          {trip.articles.length > 0 ? (
            <Section title={`Articles du déplacement (${trip.articles.length})`} icon="newspaper">
              {trip.articles.map((a) => (
                <ArticleCard key={a.id} article={a} onOpen={markRead} />
              ))}
            </Section>
          ) : null}

          <Section title={`Participants (${trip.participants.length})`} icon="people">
            <View style={styles.people}>
              {trip.participants.map((p) => (
                <View key={p.id} style={styles.person}>
                  <MemberAvatar prenom={p.prenom} nom={p.nom} avatarUrl={p.avatarUrl} size={44} />
                  <Text style={styles.personName} numberOfLines={2}>{p.prenom} {p.nom}</Text>
                </View>
              ))}
            </View>
          </Section>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function TripHero({ trip }: { trip: TripDetail }) {
  const phase = tripPhase(trip);
  return (
    <View style={styles.hero}>
      <View style={styles.heroIcon}>
        {trip.icon ? <Text style={styles.heroEmoji}>{trip.icon}</Text> : <Ionicons name="airplane" size={30} color="#fff" />}
      </View>
      <View style={{ flex: 1, gap: 4 }}>
        <Text style={styles.heroTitle}>{trip.title}</Text>
        <View style={styles.heroLine}>
          <Ionicons name="calendar-outline" size={14} color={COLORS.textMuted} />
          <Text style={styles.heroMeta}>{tripDatesLabel(trip)}</Text>
        </View>
        {trip.location ? (
          <View style={styles.heroLine}>
            <Ionicons name="location-outline" size={14} color={COLORS.textMuted} />
            <Text style={styles.heroMeta}>{trip.location}</Text>
          </View>
        ) : null}
        <View style={[styles.status, phase === 'past' && styles.statusPast]}>
          <Text style={[styles.statusLabel, phase === 'past' && styles.statusLabelPast]}>{tripStatusLabel(trip)}</Text>
        </View>
      </View>
    </View>
  );
}

function Section({ title, icon, children }: { title: string; icon: ComponentProps<typeof Ionicons>['name']; children: ReactNode }) {
  return (
    <View style={styles.section}>
      <View style={styles.sectionHeader}>
        <Ionicons name={icon} size={18} color={COLORS.primary} />
        <Text style={styles.sectionTitle}>{title}</Text>
      </View>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: COLORS.background },
  container: { padding: SPACING.md, paddingBottom: 40, gap: SPACING.lg },
  hero: {
    flexDirection: 'row', gap: 14, alignItems: 'center', padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    borderLeftWidth: 4, borderLeftColor: COLORS.secondary,
  },
  heroIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center' },
  heroEmoji: { fontSize: 30 },
  heroTitle: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  heroLine: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  heroMeta: { fontSize: 13, color: COLORS.textMuted, flexShrink: 1 },
  status: { alignSelf: 'flex-start', marginTop: 4, paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.secondary },
  statusPast: { backgroundColor: COLORS.border },
  statusLabel: { fontSize: 12, fontWeight: '800', color: '#fff' },
  statusLabelPast: { color: COLORS.textMuted },
  empty: { textAlign: 'center', color: COLORS.textMuted, fontSize: 14, paddingVertical: SPACING.md },
  section: { gap: SPACING.sm },
  sectionHeader: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  sectionTitle: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 23 },
  people: { flexDirection: 'row', flexWrap: 'wrap', gap: 14 },
  person: { width: 76, alignItems: 'center', gap: 4 },
  personName: { fontSize: 11, color: COLORS.text, textAlign: 'center' },
});
