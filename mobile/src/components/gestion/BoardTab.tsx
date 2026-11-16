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

/**
 * Onglet « Comité directeur » de l'espace Staff (membres du CoDir, administrateurs et entraîneurs, invités aux réunions) :
 * en tête, les actions en cours — celles dont le membre connecté est porteur d'abord et bien en évidence —, puis toutes
 * les réunions, la prochaine en tête. Une réunion s'ouvre d'un toucher (/board/[id]) : son détail permet d'ajouter ses
 * points et de commenter ceux des autres et, une fois la réunion close, de lire le compte rendu (réponses, décisions,
 * actions).
 */
export function BoardTab() {
  const router = useRouter();
  const [items, setItems] = useState<BoardMeetingSummary[] | null>(null);
  const [actions, setActions] = useState<BoardOpenAction[]>([]);
  const [showAllOthers, setShowAllOthers] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      const res = await boardApi.meetings();
      setItems(res.data);
      setActions(res.actions ?? []);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (items === null) return <FullScreenLoading />;

  const open = actions.filter((a) => !a.closed);
  const mine = open.filter((a) => a.isMine);
  const others = open.filter((a) => !a.isMine);
  const shownOthers = showAllOthers ? others : others.slice(0, OTHERS_PREVIEW);
  const goTo = (a: BoardOpenAction) => router.push(('/board/' + a.meetingId) as never);

  const header = (
    <View style={styles.header}>
      {mine.length > 0 && (
        <View style={styles.mineBox}>
          <View style={styles.sectionHead}>
            <Ionicons name="flag" size={16} color="#92400e" />
            <Text style={styles.mineTitle}>Mes actions à mener ({mine.length})</Text>
          </View>
          {mine.map((a) => <BoardActionRow key={a.id} action={a} showContext onPress={() => goTo(a)} />)}
        </View>
      )}

      {others.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>
            {mine.length > 0 ? 'Autres actions en cours' : 'Actions en cours'} ({others.length})
          </Text>
          {shownOthers.map((a) => <BoardActionRow key={a.id} action={a} showContext onPress={() => goTo(a)} />)}
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

      {open.length > 0 && <Text style={styles.sectionTitle}>Réunions</Text>}
    </View>
  );

  return (
    <View style={styles.root}>
      <FlatList
        data={items}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={styles.list}
        ListHeaderComponent={header}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        ListEmptyComponent={<EmptyState icon="🏛️" title="Aucune réunion programmée" message="Les réunions du CODIR apparaîtront ici." />}
        renderItem={({ item }) => (
          <Pressable
            onPress={() => router.push(('/board/' + item.id) as never)}
            style={({ pressed }) => [styles.card, item.isNext && styles.cardNext, pressed && { opacity: 0.8 }]}
          >
            <View style={{ flex: 1, gap: 3 }}>
              {item.isNext && <Text style={styles.nextTag}>Prochaine réunion</Text>}
              <Text style={styles.title}>{item.label}</Text>
              <Text style={styles.meta}>{formatMeetingDate(item.scheduledAt)}{item.location ? ' · ' + item.location : ''}</Text>
              <Text style={styles.meta}>
                {item.itemCount} point{item.itemCount > 1 ? 's' : ''} à l'ordre du jour
                {item.closed ? ` · compte rendu (${item.answeredCount} traité${item.answeredCount > 1 ? 's' : ''})` : ''}
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  list: { padding: SPACING.md, gap: SPACING.sm, maxWidth: 720, width: '100%', alignSelf: 'center' },
  header: { gap: SPACING.sm },
  mineBox: { gap: 8, padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#fef3c7', borderWidth: 1, borderColor: COLORS.warning },
  sectionHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  mineTitle: { fontSize: 15, fontWeight: '800', color: '#92400e' },
  section: { gap: 8 },
  sectionTitle: { fontSize: 13, fontWeight: '800', color: COLORS.brandNavy, textTransform: 'uppercase', letterSpacing: 0.6, marginTop: 4 },
  more: { color: COLORS.secondary, fontWeight: '700', fontSize: 13, paddingVertical: 4 },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  cardNext: { borderColor: COLORS.secondary, borderWidth: 2 },
  nextTag: { alignSelf: 'flex-start', fontSize: 11, fontWeight: '700', color: COLORS.secondary, backgroundColor: COLORS.secondarySoft, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10, overflow: 'hidden' },
  title: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 13, color: COLORS.textMuted },
});
