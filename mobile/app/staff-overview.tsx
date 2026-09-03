import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staffPresence as api } from '@/api/resources';
import type { AssignedStaff, StaffOverviewSlot } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { SportBadge } from '@/components/SportBadge';
import { WeekNavigator } from '@/components/WeekNavigator';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { dayLabel, getMonday, shortDayLabel, addDays, toIsoDate } from '@/utils/week';

/**
 * Vue d'ensemble hebdomadaire pour le staff sportif : liste tous les
 * créneaux de la semaine avec les entraîneurs et encadrants déjà
 * positionnés. Réservé aux profils Entraîneur / Encadrant (guard
 * côté serveur via ensureStaff).
 */
export default function StaffOverviewScreen() {
  const [weekStart, setWeekStart] = useState<Date>(() => getMonday(new Date()));
  const [data, setData] = useState<{ slots: StaffOverviewSlot[] } | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (mondayIso: string) => {
    try {
      setError(null);
      const resp = await api.overview(mondayIso);
      setData({ slots: resp.slots });
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
      setData(null);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load(toIsoDate(weekStart));
      if (!cancelled) setLoading(false);
    })();
    return () => { cancelled = true; };
  }, [weekStart, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(toIsoDate(weekStart));
    setRefreshing(false);
  }, [weekStart, load]);

  const slotsByDay = useMemo(() => {
    const map = new Map<number, StaffOverviewSlot[]>();
    (data?.slots ?? [])
      .filter((s) => !s.isCancelled)
      .forEach((s) => {
        const arr = map.get(s.dayOfWeek) ?? [];
        arr.push(s);
        map.set(s.dayOfWeek, arr);
      });
    return map;
  }, [data]);

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Staff sur les créneaux' }} />
      <WeekNavigator weekStart={weekStart} onChange={setWeekStart} />

      {loading ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={() => load(toIsoDate(weekStart))} />
      ) : (data?.slots ?? []).length === 0 ? (
        <EmptyState
          icon="📅"
          title="Aucun créneau cette semaine"
          message="Les entraîneurs n'ont pas défini de créneau pour cette semaine."
        />
      ) : (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        >
          {[1, 2, 3, 4, 5, 6, 7].map((day) => {
            const slots = slotsByDay.get(day) ?? [];
            if (slots.length === 0) return null;
            const dayDate = addDays(weekStart, day - 1);
            return (
              <View key={day} style={styles.dayBlock}>
                <Text style={styles.dayHeader}>
                  {dayLabel(day)} <Text style={styles.daySub}>· {shortDayLabel(dayDate)}</Text>
                </Text>
                {slots.map((s, idx) => (
                  <SlotRow key={`${s.id ?? 'v'}-${s.templateId ?? 'o'}-${idx}`} slot={s} />
                ))}
              </View>
            );
          })}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function SlotRow({ slot }: { slot: StaffOverviewSlot }) {
  const coaches = slot.assignedStaff.filter((s) => s.role === 'entraineur');
  const encadrants = slot.assignedStaff.filter((s) => s.role === 'encadrant');
  return (
    <View style={styles.slot}>
      <View style={styles.slotTimeCol}>
        <Text style={styles.slotTime}>{slot.startTime}</Text>
        <Text style={styles.slotDuration}>{slot.durationMinutes} min</Text>
      </View>
      <View style={styles.slotBody}>
        <Text style={styles.slotTitle} numberOfLines={2}>{slot.title}</Text>
        <View style={styles.slotMeta}>
          <SportBadge icon={slot.sportIcon} label={slot.sportLabel} color={slot.sportColor} size="sm" />
        </View>
        <Text style={styles.slotLocation}>📍 {slot.location}</Text>
        <View style={styles.staffRows}>
          <StaffLine label="Entraîneur(s)" people={coaches} accent={COLORS.brandNavy} />
          <StaffLine label="Encadrant(s)" people={encadrants} accent={COLORS.primary} />
        </View>
      </View>
    </View>
  );
}

function StaffLine({ label, people, accent }: { label: string; people: AssignedStaff[]; accent: string }) {
  return (
    <View style={styles.staffLine}>
      <Text style={[styles.staffLabel, { color: accent }]}>{label} :</Text>
      {people.length === 0 ? (
        <Text style={styles.staffEmpty}>—</Text>
      ) : (
        <Text style={styles.staffNames} numberOfLines={2}>
          {people.map((p) => p.fullName).join(', ')}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  dayBlock: { marginBottom: SPACING.md },
  dayHeader: {
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.secondaryDark,
    marginBottom: 6,
    paddingHorizontal: 4,
  },
  daySub: { color: COLORS.textMuted, fontWeight: '500', fontSize: 13 },
  slot: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  slotTimeCol: { minWidth: 60, alignItems: 'flex-start', paddingTop: 2 },
  slotTime: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  slotDuration: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  slotBody: { flex: 1, gap: 4 },
  slotTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  slotMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center', marginTop: 2 },
  slotLocation: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  staffRows: {
    marginTop: SPACING.sm,
    paddingTop: SPACING.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
    gap: 4,
  },
  staffLine: { flexDirection: 'row', gap: 6, alignItems: 'flex-start' },
  staffLabel: { fontSize: 12, fontWeight: '700', minWidth: 90 },
  staffNames: { flex: 1, fontSize: 13, color: COLORS.text, lineHeight: 18 },
  staffEmpty: { flex: 1, fontSize: 13, color: COLORS.textSubtle, fontStyle: 'italic' },
});
