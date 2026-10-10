import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { staffCheckIn } from '@/api/resources';
import type { StaffCheckInEvent } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/** « sam. 12 octobre · 18:30 » (heure omise pour un événement sur la journée). */
export function formatEventWhen(startsAt: string, isAllDay: boolean): string {
  const d = new Date(startsAt);
  const day = d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'long' });
  return isAllDay ? day : `${day} · ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
}

/**
 * Onglet « Émargements » de l'espace Staff : feuille de présence des
 * événements soumis au vote, et remise des bonnets du club. Les compteurs se
 * rafraîchissent au retour d'une feuille.
 */
export function EmargementTab() {
  const router = useRouter();
  const [events, setEvents] = useState<StaffCheckInEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setEvents((await staffCheckIn.events()).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, []);

  // Au retour d'une feuille d'émargement, les compteurs « émargés » doivent être à jour.
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error && events.length === 0) return <ErrorState message={error} onRetry={load} />;

  return (
    <ScrollView
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
    >
      <Text style={styles.sectionTitle}>🧢 Bonnets de bain</Text>
      <Pressable
        onPress={() => router.push('/staff-caps' as never)}
        style={({ pressed }) => [styles.card, styles.cardCaps, pressed && { opacity: 0.7 }]}
      >
        <View style={[styles.iconWrap, { backgroundColor: COLORS.secondary }]}>
          <Ionicons name="water" size={22} color="#fff" />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.title}>Remise des bonnets</Text>
          <Text style={styles.sub}>Qui a reçu son bonnet du club</Text>
        </View>
        <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
      </Pressable>

      <Text style={[styles.sectionTitle, { marginTop: SPACING.lg }]}>✅ Présence aux événements</Text>
      <Text style={styles.hint}>
        Événements soumis au vote de présence : émargez les adhérents présents sur place, même sans vote.
      </Text>
      {events.length === 0 ? (
        <EmptyState icon="📅" title="Aucun événement à émarger" message="Les événements soumis au vote apparaîtront ici." />
      ) : (
        events.map((e) => (
          <Pressable
            key={e.id}
            onPress={() => router.push({ pathname: '/staff-check-in/[id]', params: { id: String(e.id) } } as never)}
            style={({ pressed }) => [styles.card, pressed && { opacity: 0.7 }]}
          >
            <View style={{ flex: 1 }}>
              <Text style={styles.title} numberOfLines={2}>{e.title}</Text>
              <Text style={styles.sub}>
                {formatEventWhen(e.startsAt, e.isAllDay)}{e.location ? ` · ${e.location}` : ''}
              </Text>
              <Text style={styles.votes}>
                Votes : ✅ {e.votes.yes} · ❓ {e.votes.maybe} · ❌ {e.votes.no}
              </Text>
            </View>
            <View style={styles.checkedBox}>
              <Text style={styles.checkedNumber}>{e.checkedCount}</Text>
              <Text style={styles.checkedLabel}>émargé{e.checkedCount > 1 ? 's' : ''}</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  sectionTitle: { fontSize: 14, fontWeight: '800', color: COLORS.text, marginBottom: SPACING.sm },
  hint: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17, marginBottom: SPACING.md },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.sm,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.success,
  },
  cardCaps: { borderLeftColor: COLORS.secondary },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  votes: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  checkedBox: { alignItems: 'center', minWidth: 52 },
  checkedNumber: { fontSize: 22, fontWeight: '800', color: COLORS.success },
  checkedLabel: { fontSize: 11, color: COLORS.textMuted },
});
