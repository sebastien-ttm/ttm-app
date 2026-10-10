import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { staffCheckIn, staffPerfTests } from '@/api/resources';
import type { StaffCheckInEvent, StaffPerfTestSession } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canCheckIn, canManageCapsAndTimes } from '@/utils/profile';

const STATUS_LABELS: Record<StaffPerfTestSession['status'], string> = {
  ongoing: 'En cours',
  upcoming: 'À venir',
  past: 'Terminée',
};

/** « sam. 12 octobre · 18:30 » (heure omise pour un événement sur la journée). */
export function formatEventWhen(startsAt: string, isAllDay: boolean): string {
  const d = new Date(startsAt);
  const day = d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'long' });
  return isAllDay ? day : `${day} · ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
}

/** « lun. 12 octobre » pour une date AAAA-MM-JJ (construite en local : pas de décalage de fuseau). */
function formatYmd(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'long' });
}

/**
 * Quand a lieu l'événement : « sam. 12 octobre · 18:30 » le temps d'un jour,
 * « du sam. 12 octobre au lun. 14 octobre » sur plusieurs jours (l'événement
 * reste proposé à l'émargement chaque jour de sa durée).
 */
export function formatEventPeriod(event: { startsAt: string; lastDay: string; isAllDay: boolean }): string {
  // Jour de début tel qu'enregistré par le serveur (les 10 premiers caractères de la date ISO).
  const firstDay = event.startsAt.slice(0, 10);
  if (event.lastDay > firstDay) {
    return `du ${formatYmd(firstDay)} au ${formatYmd(event.lastDay)}`;
  }
  return formatEventWhen(event.startsAt, event.isAllDay);
}

/**
 * Onglet « Émargements » de l'espace Staff : feuille de présence des
 * événements soumis au vote (entraîneurs et CoDir), remise des bonnets du club
 * et saisie des temps des tests chronométrés (entraîneurs seulement). Les
 * compteurs se rafraîchissent au retour d'une feuille.
 */
export function EmargementTab() {
  const router = useRouter();
  const { user } = useAuth();
  const showCheckIn = canCheckIn(user);
  const showManage = canManageCapsAndTimes(user);
  const [events, setEvents] = useState<StaffCheckInEvent[]>([]);
  const [sessions, setSessions] = useState<StaffPerfTestSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    // Deux sources indépendantes : l'échec de l'une ne vide pas l'autre.
    const [ev, ses] = await Promise.allSettled([
      showCheckIn ? staffCheckIn.events() : Promise.resolve({ data: [] as StaffCheckInEvent[] }),
      showManage ? staffPerfTests.list() : Promise.resolve({ data: [] as StaffPerfTestSession[] }),
    ]);
    if (ev.status === 'fulfilled') setEvents(ev.value.data);
    if (ses.status === 'fulfilled') setSessions(ses.value.data);
    const failure = ev.status === 'rejected' ? ev.reason : ses.status === 'rejected' ? ses.reason : null;
    if (failure) setError(failure instanceof ApiError ? failure.message : 'Erreur de chargement');
    setLoading(false);
  }, [showCheckIn, showManage]);

  // Au retour d'une feuille, les compteurs (émargés, temps saisis) doivent être à jour.
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  if (loading) return <FullScreenLoading />;
  if (error && events.length === 0 && sessions.length === 0) return <ErrorState message={error} onRetry={load} />;

  return (
    <ScrollView
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
    >
      {showManage && (
        <>
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

          <Text style={[styles.sectionTitle, { marginTop: SPACING.lg }]}>⏱️ Tests chronométrés</Text>
          <Text style={styles.hint}>
            Saisie des temps des prises de temps en cours ou terminées depuis moins de 45 jours.
          </Text>
          {sessions.length === 0 ? (
            <EmptyState
              icon="⏱️"
              title="Aucune prise de temps récente"
              message="Créez la prise de temps dans le backend (Tests chronométrés) pour saisir les temps ici."
            />
          ) : (
            sessions.map((s) => (
              <Pressable
                key={s.id}
                onPress={() => router.push({ pathname: '/staff-perf-tests/[id]', params: { id: String(s.id) } } as never)}
                style={({ pressed }) => [styles.card, styles.cardTimes, pressed && { opacity: 0.7 }]}
              >
                <View style={{ flex: 1 }}>
                  <Text style={styles.title} numberOfLines={2}>{s.icon} {s.label}</Text>
                  <Text style={styles.sub}>
                    {s.datesLabel} · {STATUS_LABELS[s.status]}
                  </Text>
                  {s.notes ? <Text style={styles.votes} numberOfLines={1}>{s.notes}</Text> : null}
                </View>
                <View style={styles.checkedBox}>
                  <Text style={[styles.checkedNumber, { color: COLORS.secondaryDark }]}>{s.resultsCount ?? 0}</Text>
                  <Text style={styles.checkedLabel}>temps</Text>
                </View>
                <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
              </Pressable>
            ))
          )}
        </>
      )}

      {showCheckIn && (
        <>
          <Text style={[styles.sectionTitle, showManage && { marginTop: SPACING.lg }]}>✅ Présence aux événements</Text>
          <Text style={styles.hint}>
            Événements du jour soumis au vote de présence (un événement sur plusieurs jours reste proposé chaque jour) : émargez les adhérents présents sur place, même sans vote.
          </Text>
          {events.length === 0 ? (
            <EmptyState icon="📅" title="Aucun événement aujourd'hui" message="Les événements du jour soumis au vote de présence apparaîtront ici." />
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
                    {formatEventPeriod(e)}{e.location ? ` · ${e.location}` : ''}
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
        </>
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
  cardTimes: { borderLeftColor: COLORS.brandNavy },
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
