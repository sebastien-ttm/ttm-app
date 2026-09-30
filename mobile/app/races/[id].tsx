import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal, RaceVote } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { formatRaceDate, raceTypeMeta } from '@/components/RaceForm';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { formatRelativeFr } from '@/utils/html';

/**
 * Détail d'une course proposée : infos, lien vers le site, vote
 * d'intérêt (Intéressé(e) / Peut-être) et liste des adhérents
 * intéressés — utile au capitaine pour l'inscription groupée. Si c'est
 * ma proposition : modifier / supprimer.
 */
export default function RaceDetailScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [race, setRace] = useState<RaceProposal | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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

  async function vote(status: RaceVote) {
    if (!race) return;
    setBusy(true);
    try {
      setRace(await racesApi.vote(race.id, race.myVote === status ? null : status));
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!race) return;
    const confirmed = await confirmAsync('Supprimer cette proposition ?', 'Les votes associés seront aussi supprimés.');
    if (!confirmed) return;
    setBusy(true);
    try {
      await racesApi.remove(race.id);
      router.replace('/races' as never);
    } catch (e) {
      showError(e);
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Course' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !race) {
    return (
      <>
        <Stack.Screen options={{ title: 'Course' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  const meta = raceTypeMeta(race.type);
  const isMine = user !== null && race.authorId === user.id;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: race.name }} />
      <ScrollView contentContainerStyle={{ paddingBottom: SPACING.xl }}>
        <View style={[styles.hero, { backgroundColor: meta.color }]}>
          <Text style={styles.heroEmoji}>{meta.emoji}</Text>
          <Text style={styles.heroType}>{race.typeLabel}</Text>
        </View>

        <View style={styles.body}>
          <Text style={styles.title}>{race.name}</Text>
          <View style={styles.dateRow}>
            <Ionicons name="calendar-outline" size={16} color={COLORS.text} />
            <Text style={styles.date}>{formatRaceDate(race.raceDate)}</Text>
          </View>
          <Text style={styles.author}>
            Proposée par {race.authorFullName} · {formatRelativeFr(race.createdAt)}
          </Text>

          {race.captain && (
            <View style={styles.captainBox}>
              <Text style={styles.captainEmoji}>🧢</Text>
              <Text style={styles.captainText}>
                <Text style={{ fontWeight: '700' }}>{race.authorFirstName} se propose d'être capitaine</Text>
                {' '}: il/elle prend contact avec l'organisateur pour pouvoir proposer une inscription groupée.
              </Text>
            </View>
          )}

          {race.carpoolingEnabled && (
            <Pressable
              onPress={() => router.push({ pathname: '/races/[id]/carpool', params: { id: String(race.id) } })}
              style={({ pressed }) => [styles.carpoolBtn, pressed && { opacity: 0.75 }]}
              accessibilityLabel="Ouvrir la page covoiturage"
            >
              <Ionicons name="car" size={20} color="#fff" />
              <Text style={styles.carpoolBtnLabel}>Covoiturage</Text>
              <Ionicons name="chevron-forward" size={18} color="#fff" style={{ marginLeft: 'auto' }} />
            </Pressable>
          )}

          {race.url && (
            <Pressable
              onPress={() => void WebBrowser.openBrowserAsync(race.url as string)}
              style={({ pressed }) => [styles.linkBtn, pressed && { opacity: 0.7 }]}
            >
              <Ionicons name="globe-outline" size={18} color={COLORS.primary} />
              <Text style={styles.linkLabel} numberOfLines={1}>{prettyUrl(race.url)}</Text>
              <Ionicons name="open-outline" size={16} color={COLORS.primary} />
            </Pressable>
          )}

          <Text style={styles.sectionTitle}>{race.captain ? 'Ça vous intéresse ?' : 'Vous participez ?'}</Text>
          <View style={styles.voteRow}>
            <VoteBtn
              icon="thumbs-up"
              label={race.captain ? 'Intéressé(e)' : 'Je suis inscrit(e)'}
              count={race.interestedCount}
              active={race.myVote === 'interested'}
              onPress={() => void vote('interested')}
              disabled={busy}
            />
            <VoteBtn
              icon="help-circle"
              label="Peut-être"
              count={race.maybeCount}
              active={race.myVote === 'maybe'}
              onPress={() => void vote('maybe')}
              disabled={busy}
            />
          </View>

          <PeopleList title={race.captain ? 'Intéressés' : 'Inscrits'} people={race.interested} />
          <PeopleList title="Peut-être" people={race.maybe} />

          {isMine && (
            <View style={styles.ownerActions}>
              <Text style={styles.ownerActionsTitle}>Gérer ma proposition</Text>
              <View style={styles.ownerActionsRow}>
                <Pressable
                  onPress={() => router.push(('/races/' + race.id + '/edit') as never)}
                  disabled={busy}
                  style={({ pressed }) => [styles.ownerBtn, pressed && { opacity: 0.6 }]}
                >
                  <Ionicons name="create-outline" size={18} color={COLORS.text} />
                  <Text style={styles.ownerBtnLabel}>Modifier</Text>
                </Pressable>
                <Pressable
                  onPress={() => void remove()}
                  disabled={busy}
                  style={({ pressed }) => [styles.ownerBtn, pressed && { opacity: 0.6 }]}
                >
                  <Ionicons name="trash-outline" size={18} color={COLORS.error} />
                  <Text style={[styles.ownerBtnLabel, { color: COLORS.error }]}>Supprimer</Text>
                </Pressable>
              </View>
            </View>
          )}

          <Pressable onPress={goBack} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Retour</Text>
          </Pressable>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function VoteBtn({ icon, label, count, active, onPress, disabled }: {
  icon: 'thumbs-up' | 'help-circle';
  label: string;
  count: number;
  active: boolean;
  onPress: () => void;
  disabled: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [
        styles.voteBtn,
        active && styles.voteBtnActive,
        pressed && { opacity: 0.7 },
        disabled && { opacity: 0.6 },
      ]}
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
    >
      <Ionicons
        name={active ? icon : (`${icon}-outline` as const)}
        size={20}
        color={active ? '#fff' : COLORS.primary}
      />
      <Text style={[styles.voteLabel, active && { color: '#fff' }]}>{label}</Text>
      <Text style={[styles.voteCount, active && { color: '#fff' }]}>{count}</Text>
    </Pressable>
  );
}

function PeopleList({ title, people }: { title: string; people: { id: number; fullName: string }[] }) {
  if (people.length === 0) return null;
  return (
    <View style={styles.people}>
      <Text style={styles.peopleTitle}>{title} ({people.length})</Text>
      <View style={styles.peopleChips}>
        {people.map((p) => (
          <View key={p.id} style={styles.personChip}>
            <Text style={styles.personChipLabel}>{p.fullName}</Text>
          </View>
        ))}
      </View>
    </View>
  );
}

function prettyUrl(url: string): string {
  return url.replace(/^https?:\/\//i, '').replace(/\/$/, '');
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : (e instanceof Error ? e.message : 'Erreur inattendue.');
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
}

function confirmAsync(title: string, message: string): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(typeof window !== 'undefined' ? window.confirm(title + '\n' + message) : false);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
      { text: 'Supprimer', style: 'destructive', onPress: () => resolve(true) },
    ]);
  });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  hero: { height: 140, alignItems: 'center', justifyContent: 'center', gap: 4 },
  heroEmoji: { fontSize: 56 },
  heroType: { color: '#fff', fontSize: 13, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6 },
  body: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text },
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 6 },
  date: { fontSize: 15, fontWeight: '600', color: COLORS.text },
  author: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  captainBox: {
    flexDirection: 'row', alignItems: 'flex-start', gap: 10,
    marginTop: SPACING.md, padding: SPACING.md,
    backgroundColor: '#fef3c7', borderRadius: RADIUS.md,
  },
  captainEmoji: { fontSize: 20 },
  captainText: { flex: 1, color: '#92400e', fontSize: 13, lineHeight: 19 },
  carpoolBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: COLORS.brandNavy,
    paddingHorizontal: SPACING.md,
    paddingVertical: 14,
    borderRadius: RADIUS.md,
    marginTop: SPACING.md,
  },
  carpoolBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  linkBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    marginTop: SPACING.md, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
  },
  linkLabel: { flex: 1, color: COLORS.primary, fontWeight: '600', fontSize: 14 },
  sectionTitle: {
    fontSize: 12, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5,
    marginTop: SPACING.lg, marginBottom: SPACING.sm,
  },
  voteRow: { flexDirection: 'row', gap: SPACING.sm },
  voteBtn: {
    flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6,
    paddingVertical: 12, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.primary, backgroundColor: COLORS.surface,
  },
  voteBtnActive: { backgroundColor: COLORS.primary },
  voteLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  voteCount: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted },
  people: { marginTop: SPACING.md },
  peopleTitle: { fontSize: 13, fontWeight: '700', color: COLORS.text, marginBottom: 6 },
  peopleChips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  personChip: {
    paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12,
    backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border,
  },
  personChipLabel: { fontSize: 12, color: COLORS.text },
  ownerActions: {
    marginTop: SPACING.lg, backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md, padding: SPACING.md,
  },
  ownerActionsTitle: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm },
  ownerActionsRow: { flexDirection: 'row', justifyContent: 'space-around' },
  ownerBtn: { alignItems: 'center', gap: 4, paddingVertical: 6, paddingHorizontal: 8 },
  ownerBtnLabel: { fontSize: 12, fontWeight: '600', color: COLORS.text },
  backBtn: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.md },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
