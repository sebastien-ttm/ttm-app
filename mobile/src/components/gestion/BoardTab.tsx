import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { board as boardApi } from '@/api/resources';
import type { BoardMeetingSummary, BoardOpenAction } from '@/api/types';
import { BoardActionRow } from '@/components/BoardActionRow';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatMeetingDate } from '@/utils/boardDate';

/** Nombre d'actions « des autres » affichées avant « Voir les autres ». */
const OTHERS_PREVIEW = 4;
/** Nombre d'actions faites / de réunions passées affichées avant « Voir … ». */
const DONE_PREVIEW = 3;
const PAST_PREVIEW = 3;

/** Réunion passée : close, ou heure dépassée (même non close). Le serveur le dit (`isPast`) ; sinon on le déduit. */
function isPastMeeting(m: BoardMeetingSummary): boolean {
  return m.isPast ?? (m.closed || new Date(m.scheduledAt).getTime() <= Date.now());
}

/**
 * Onglet « Comité directeur » de l'espace Staff (membres du CoDir, administrateurs et entraîneurs, invités aux réunions) :
 * en tête, les actions en cours — celles dont le membre connecté est porteur d'abord, bien en évidence, avec un bouton
 * pour les acquitter —, puis les dernières actions faites (celles des autres aussi), puis la prochaine réunion (et les
 * éventuelles suivantes) ; les réunions passées sont regroupées dans un bloc à part. Une réunion s'ouvre d'un toucher
 * (/board/[id]) : son détail permet d'ajouter ses points et de commenter ceux des autres et, une fois la réunion close,
 * de lire le compte rendu. Toucher une action ouvre la réunion directement sur le point concerné (/board/[id]?item=…).
 */
export function BoardTab() {
  const router = useRouter();
  const [items, setItems] = useState<BoardMeetingSummary[] | null>(null);
  const [actions, setActions] = useState<BoardOpenAction[]>([]);
  const [doneActions, setDoneActions] = useState<BoardOpenAction[]>([]);
  const [showAllOthers, setShowAllOthers] = useState(false);
  const [showAllDone, setShowAllDone] = useState(false);
  const [showAllPast, setShowAllPast] = useState(false);
  const [busyActionId, setBusyActionId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      const res = await boardApi.meetings();
      setItems(res.data);
      setActions(res.actions ?? []);
      setDoneActions(res.doneActions ?? []);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  /** Acquitte une de mes actions (ou la rouvre) puis recharge : elle passe de « à mener » à « faites » (et inversement). */
  async function toggleAction(a: BoardOpenAction) {
    setBusyActionId(a.id);
    setActionError(null);
    try {
      await boardApi.setActionDone(a.id, !a.closed);
      await load();
    } catch (e) {
      setActionError(e instanceof ApiError ? e.message : 'Impossible de mettre l\'action à jour.');
    } finally {
      setBusyActionId(null);
    }
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (items === null) return <FullScreenLoading />;

  const open = actions.filter((a) => !a.closed);
  const mine = open.filter((a) => a.isMine);
  const others = open.filter((a) => !a.isMine);
  const shownOthers = showAllOthers ? others : others.slice(0, OTHERS_PREVIEW);
  const done = doneActions.filter((a) => a.closed);
  const shownDone = showAllDone ? done : done.slice(0, DONE_PREVIEW);

  // Le serveur trie : à venir (la plus proche en tête), puis passées (la plus récente en tête).
  const upcoming = items.filter((m) => !isPastMeeting(m));
  const past = items.filter(isPastMeeting);
  const shownPast = showAllPast ? past : past.slice(0, PAST_PREVIEW);
  const nextId = upcoming[0]?.id ?? null;

  // Ouvre la réunion de l'action, positionnée sur le point (sujet) concerné.
  const goTo = (a: BoardOpenAction) =>
    router.push((`/board/${a.meetingId}` + (a.itemId ? `?item=${a.itemId}` : '')) as never);
  const rowFor = (a: BoardOpenAction) => (
    <BoardActionRow
      key={a.id}
      action={a}
      showContext
      onPress={() => goTo(a)}
      onToggleDone={a.isMine ? () => void toggleAction(a) : undefined}
      busy={busyActionId === a.id}
    />
  );

  const header = (
    <View style={styles.header}>
      {actionError ? <Text style={styles.error}>{actionError}</Text> : null}

      {mine.length > 0 && (
        <View style={styles.mineBox}>
          <View style={styles.sectionHead}>
            <Ionicons name="flag" size={16} color="#92400e" />
            <Text style={styles.mineTitle}>Mes actions à mener ({mine.length})</Text>
          </View>
          {mine.map(rowFor)}
        </View>
      )}

      {others.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>
            {mine.length > 0 ? 'Autres actions en cours' : 'Actions en cours'} ({others.length})
          </Text>
          {shownOthers.map(rowFor)}
          {others.length > OTHERS_PREVIEW && (
            <Pressable onPress={() => setShowAllOthers((v) => !v)}>
              <Text style={styles.more}>
                {showAllOthers
                  ? 'Voir moins'
                  : others.length - OTHERS_PREVIEW === 1 ? "Voir l'autre action" : `Voir les ${others.length - OTHERS_PREVIEW} autres actions`}
              </Text>
            </Pressable>
          )}
        </View>
      )}

      {done.length > 0 && (
        <View style={styles.doneBox}>
          <View style={styles.sectionHead}>
            <Ionicons name="checkmark-circle" size={16} color="#166534" />
            <Text style={styles.doneTitle}>Dernières actions faites</Text>
          </View>
          {shownDone.map(rowFor)}
          {done.length > DONE_PREVIEW && (
            <Pressable onPress={() => setShowAllDone((v) => !v)}>
              <Text style={styles.more}>
                {showAllDone
                  ? 'Voir moins'
                  : done.length - DONE_PREVIEW === 1 ? "Voir l'autre action faite" : `Voir les ${done.length - DONE_PREVIEW} autres actions faites`}
              </Text>
            </Pressable>
          )}
        </View>
      )}

      {items.length > 0 && <Text style={styles.sectionTitle}>{upcoming.length > 1 ? 'Réunions à venir' : 'Prochaine réunion'}</Text>}
    </View>
  );

  const pastBlock = past.length > 0 && (
    <View style={styles.pastBox}>
      <View style={styles.sectionHead}>
        <Ionicons name="time-outline" size={16} color={COLORS.textMuted} />
        <Text style={styles.pastTitle}>Réunions passées ({past.length})</Text>
      </View>
      {shownPast.map((m) => (
        <MeetingCard key={m.id} meeting={m} past onPress={() => router.push(('/board/' + m.id) as never)} />
      ))}
      {past.length > PAST_PREVIEW && (
        <Pressable onPress={() => setShowAllPast((v) => !v)}>
          <Text style={styles.more}>
            {showAllPast
              ? 'Voir moins'
              : past.length - PAST_PREVIEW === 1 ? "Voir l'autre réunion passée" : `Voir les ${past.length - PAST_PREVIEW} autres réunions passées`}
          </Text>
        </Pressable>
      )}
    </View>
  );

  return (
    <View style={styles.root}>
      <FlatList
        data={upcoming}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={styles.list}
        ListHeaderComponent={header}
        ListFooterComponent={pastBlock || null}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        ListEmptyComponent={
          items.length === 0
            ? <EmptyState icon="🏛️" title="Aucune réunion programmée" message="Les réunions du CODIR apparaîtront ici." />
            : <Text style={styles.noUpcoming}>Aucune réunion à venir pour l'instant.</Text>
        }
        renderItem={({ item }) => (
          // Étiquette « Prochaine réunion » seulement s'il y en a plusieurs à venir : sinon le titre de section le dit déjà.
          <MeetingCard meeting={item} next={upcoming.length > 1 && item.id === nextId} onPress={() => router.push(('/board/' + item.id) as never)} />
        )}
      />
    </View>
  );
}

/** Carte d'une réunion : à venir (la prochaine repérée) ou passée (mention « Passée », bloc à part). */
function MeetingCard({ meeting, next = false, past = false, onPress }: {
  meeting: BoardMeetingSummary;
  next?: boolean;
  past?: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, next && styles.cardNext, past && styles.cardPast, pressed && { opacity: 0.8 }]}>
      <View style={{ flex: 1, gap: 3 }}>
        {next && <Text style={styles.nextTag}>Prochaine réunion</Text>}
        {past && <Text style={styles.pastTag}>Passée</Text>}
        <Text style={[styles.title, past && { color: COLORS.textMuted }]}>{meeting.label}</Text>
        <Text style={styles.meta}>{formatMeetingDate(meeting.scheduledAt)}{meeting.location ? ' · ' + meeting.location : ''}</Text>
        <Text style={styles.meta}>
          {meeting.itemCount} point{meeting.itemCount > 1 ? 's' : ''} à l'ordre du jour
          {meeting.closed
            ? ` · compte rendu (${meeting.answeredCount} traité${meeting.answeredCount > 1 ? 's' : ''})`
            : past ? ' · non clôturée' : ''}
        </Text>
      </View>
      <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  list: { padding: SPACING.md, gap: SPACING.sm, maxWidth: 720, width: '100%', alignSelf: 'center' },
  header: { gap: SPACING.sm },
  error: { color: COLORS.error, fontWeight: '600', fontSize: 13 },
  mineBox: { gap: 8, padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#fef3c7', borderWidth: 1, borderColor: COLORS.warning },
  sectionHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  mineTitle: { fontSize: 15, fontWeight: '800', color: '#92400e' },
  section: { gap: 8 },
  sectionTitle: { fontSize: 13, fontWeight: '800', color: COLORS.brandNavy, textTransform: 'uppercase', letterSpacing: 0.6, marginTop: 4 },
  more: { color: COLORS.secondary, fontWeight: '700', fontSize: 13, paddingVertical: 4 },
  doneBox: { gap: 8, padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#f0fdf4', borderWidth: 1, borderColor: '#bbf7d0' },
  doneTitle: { fontSize: 15, fontWeight: '800', color: '#166534' },
  pastBox: { gap: 8, padding: SPACING.md, marginTop: SPACING.md, borderRadius: RADIUS.md, backgroundColor: COLORS.surfaceAlt, borderWidth: 1, borderColor: COLORS.border },
  pastTitle: { fontSize: 15, fontWeight: '800', color: COLORS.textMuted },
  noUpcoming: { color: COLORS.textMuted, fontSize: 13.5, paddingVertical: 4 },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  cardNext: { borderColor: COLORS.secondary, borderWidth: 2 },
  cardPast: { backgroundColor: COLORS.surface },
  nextTag: { alignSelf: 'flex-start', fontSize: 11, fontWeight: '700', color: COLORS.secondary, backgroundColor: COLORS.secondarySoft, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10, overflow: 'hidden' },
  pastTag: { alignSelf: 'flex-start', fontSize: 11, fontWeight: '700', color: COLORS.textMuted, backgroundColor: '#e2e8f0', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10, overflow: 'hidden' },
  title: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 13, color: COLORS.textMuted },
});
