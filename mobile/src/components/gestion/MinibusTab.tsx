import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { minibus as minibusApi } from '@/api/resources';
import type { MinibusBooking } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MinibusBookingCard } from '@/components/minibus/MinibusBookingCard';
import { MinibusCalendar } from '@/components/minibus/MinibusCalendar';
import { COLORS, RADIUS, SPACING } from '@/config';
import { coversDay, isLiveBooking, isoOfDate, stepToDo, upcomingBookings } from '@/utils/minibus';

/** Réservations « des autres » et emprunts personnels affichés avant « Voir … ». */
const OTHERS_PREVIEW = 4;
const MINE_PREVIEW = 4;

/**
 * Sous-partie « Emprunt minibus » du Comité directeur (membres du CoDir) : le calendrier du minibus du club, le bouton
 * pour demander une réservation, mes emprunts (avec ce qu'il reste à faire : charte, états des lieux) et les prochaines
 * réservations des autres. Le gestionnaire du minibus voit en tête les demandes à valider. Toucher une demande ouvre son
 * détail (/minibus/[id]) : décision du gestionnaire, charte d'utilisation, états des lieux de départ et de retour.
 */
export function MinibusTab({ onPendingChange }: { onPendingChange?: (count: number) => void }) {
  const router = useRouter();
  const [bookings, setBookings] = useState<MinibusBooking[] | null>(null);
  const [canManage, setCanManage] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [month, setMonth] = useState(() => new Date());
  const [selected, setSelected] = useState<string | null>(null);
  const [showAllMine, setShowAllMine] = useState(false);
  const [showAllOthers, setShowAllOthers] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      const res = await minibusApi.list();
      setBookings(res.data);
      setCanManage(res.canManage);
      onPendingChange?.(res.pendingCount ?? 0);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, [onPendingChange]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const todayIso = isoOfDate(new Date());
  const open = (id: number) => router.push(('/minibus/' + id) as never);

  const { toApprove, mine, others, dayBookings } = useMemo(() => {
    const all = bookings ?? [];
    const live = all.filter(isLiveBooking);
    return {
      // Demandes en attente, la plus proche d'abord (le gestionnaire les traite dans l'ordre des dates).
      toApprove: upcomingBookings(live.filter((b) => b.status === 'pending'), todayIso),
      // Mes emprunts : ceux qui demandent une action d'abord, puis à venir, puis l'historique récent (refusées, passées).
      mine: all.filter((b) => b.isMine).sort((a, b) => {
        const todoA = stepToDo(a.step) ? 0 : 1;
        const todoB = stepToDo(b.step) ? 0 : 1;
        const pastA = a.endDate < todayIso || !isLiveBooking(a) ? 1 : 0;
        const pastB = b.endDate < todayIso || !isLiveBooking(b) ? 1 : 0;
        return todoA - todoB || pastA - pastB || (pastA ? b.startDate.localeCompare(a.startDate) : a.startDate.localeCompare(b.startDate));
      }),
      others: upcomingBookings(live.filter((b) => !b.isMine), todayIso),
      dayBookings: selected ? live.filter((b) => coversDay(b, selected)) : [],
    };
  }, [bookings, selected, todayIso]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (bookings === null) return <FullScreenLoading />;

  const shownMine = showAllMine ? mine : mine.slice(0, MINE_PREVIEW);
  const shownOthers = showAllOthers ? others : others.slice(0, OTHERS_PREVIEW);
  const request = (date?: string) => router.push((date ? `/minibus/new?date=${date}` : '/minibus/new') as never);

  return (
    <ScrollView
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
    >
      <Pressable onPress={() => request(selected && selected >= todayIso ? selected : undefined)} style={styles.requestBtn} accessibilityRole="button">
        <Ionicons name="add-circle" size={20} color="#fff" />
        <Text style={styles.requestLabel}>Demander le minibus</Text>
      </Pressable>

      {canManage && toApprove.length > 0 && (
        <View style={styles.approveBox}>
          <View style={styles.sectionHead}>
            <Ionicons name="hourglass" size={16} color="#92400e" />
            <Text style={styles.approveTitle}>Demandes à valider ({toApprove.length})</Text>
          </View>
          <Text style={styles.approveHint}>Vous êtes le gestionnaire du minibus : ouvrez une demande pour la valider ou la refuser.</Text>
          {toApprove.map((b) => <MinibusBookingCard key={b.id} booking={b} onPress={() => open(b.id)} />)}
        </View>
      )}

      {mine.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Mes emprunts</Text>
          {shownMine.map((b) => <MinibusBookingCard key={b.id} booking={b} showRequester={false} onPress={() => open(b.id)} />)}
          {mine.length > MINE_PREVIEW && (
            <Pressable onPress={() => setShowAllMine((v) => !v)}>
              <Text style={styles.more}>{showAllMine ? 'Voir moins' : `Voir les ${mine.length - MINE_PREVIEW} autres`}</Text>
            </Pressable>
          )}
        </View>
      )}

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Calendrier</Text>
        <MinibusCalendar bookings={bookings} month={month} selected={selected} onChangeMonth={setMonth} onSelect={setSelected} />
        {selected && (
          <View style={styles.day}>
            <Text style={styles.dayTitle}>
              {new Date(selected + 'T00:00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}
            </Text>
            {dayBookings.length === 0 ? (
              <Text style={styles.free}>Le minibus est libre ce jour-là.</Text>
            ) : (
              dayBookings.map((b) => <MinibusBookingCard key={b.id} booking={b} onPress={() => open(b.id)} />)
            )}
          </View>
        )}
      </View>

      {others.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Prochaines réservations</Text>
          {shownOthers.map((b) => <MinibusBookingCard key={b.id} booking={b} onPress={() => open(b.id)} />)}
          {others.length > OTHERS_PREVIEW && (
            <Pressable onPress={() => setShowAllOthers((v) => !v)}>
              <Text style={styles.more}>{showAllOthers ? 'Voir moins' : `Voir les ${others.length - OTHERS_PREVIEW} autres`}</Text>
            </Pressable>
          )}
        </View>
      )}

      {mine.length === 0 && others.length === 0 && (
        <Text style={styles.empty}>Aucune réservation à venir : le minibus est libre. Touchez « Demander le minibus » pour en faire une.</Text>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, gap: SPACING.md, paddingBottom: SPACING.xxl, maxWidth: 720, width: '100%', alignSelf: 'center' },
  requestBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 13,
  },
  requestLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
  section: { gap: 8 },
  sectionTitle: { fontSize: 13, fontWeight: '800', color: COLORS.brandNavy, textTransform: 'uppercase', letterSpacing: 0.6 },
  sectionHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  approveBox: { gap: 8, padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#fef3c7', borderWidth: 1, borderColor: COLORS.warning },
  approveTitle: { fontSize: 15, fontWeight: '800', color: '#92400e' },
  approveHint: { fontSize: 12.5, color: '#92400e' },
  more: { color: COLORS.secondary, fontWeight: '700', fontSize: 13, paddingVertical: 4 },
  day: { gap: 8, marginTop: 4 },
  dayTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text, textTransform: 'capitalize' },
  free: { fontSize: 13.5, color: COLORS.textMuted },
  empty: { fontSize: 13.5, color: COLORS.textMuted, textAlign: 'center', paddingVertical: SPACING.md },
});
