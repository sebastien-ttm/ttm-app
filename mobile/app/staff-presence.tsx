import { Stack, useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staffPresence as api } from '@/api/resources';
import type {
  SportKey,
  StaffPresenceSlot,
  StaffPresenceStatus,
  StaffPresenceWeek,
} from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { SportBadge } from '@/components/SportBadge';
import { WeekNavigator } from '@/components/WeekNavigator';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { addDays, addWeeks, dayLabel, getMonday, shortDayLabel, toIsoDate } from '@/utils/week';

const SPORT_FILTERS: { key: SportKey | 'all'; label: string }[] = [
  { key: 'all', label: 'Tous' },
  { key: 'natation', label: 'Natation' },
  { key: 'velo', label: 'Vélo' },
  { key: 'course', label: 'Course' },
  { key: 'multi', label: 'Multi' },
  { key: 'renfo', label: 'Renfo' },
  { key: 'autre', label: 'Autre' },
];

export default function StaffPresenceScreen() {
  const { user } = useAuth();
  const router = useRouter();

  const [weekStart, setWeekStart] = useState<Date>(() => getMonday(new Date()));
  const [data, setData] = useState<StaffPresenceWeek | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sportFilter, setSportFilter] = useState<SportKey | 'all'>('all');
  const [updatingKey, setUpdatingKey] = useState<string | null>(null);

  // Garde-fou : accès staff uniquement
  if (user && !user.profiles.includes('encadrant') && !user.profiles.includes('entraineur')) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Mes Présences' }} />
        <EmptyState
          icon="🔒"
          title="Accès réservé"
          message="Cette page est réservée aux encadrants et entraîneurs du club."
        />
      </SafeAreaView>
    );
  }

  const load = useCallback(async (iso: string) => {
    try {
      setError(null);
      const resp = await api.week(iso);
      setData(resp);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load(toIsoDate(weekStart));
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [weekStart, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(toIsoDate(weekStart));
    setRefreshing(false);
  }, [weekStart, load]);

  const filteredSlots = useMemo(() => {
    // On masque les créneaux annulés : un encadrant ne peut plus s'y
    // positionner / s'y déclarer présent.
    const slots = (data?.slots ?? []).filter((s) => !s.isCancelled);
    if (sportFilter === 'all') return slots;
    return slots.filter((s) => s.sport === sportFilter);
  }, [data, sportFilter]);

  const slotsByDay = useMemo(() => {
    const map = new Map<number, StaffPresenceSlot[]>();
    filteredSlots.forEach((s) => {
      const arr = map.get(s.dayOfWeek) ?? [];
      arr.push(s);
      map.set(s.dayOfWeek, arr);
    });
    return map;
  }, [filteredSlots]);

  async function setStatus(slot: StaffPresenceSlot, status: StaffPresenceStatus | null) {
    const key = `slot-${slot.id ?? `tpl${slot.templateId}`}`;
    setUpdatingKey(key);
    try {
      if (status === null) {
        // Annuler la présence
        if (slot.myPresence) {
          await api.remove(slot.myPresence.id);
        }
      } else if (slot.id) {
        await api.setForSlot({ slotId: slot.id, status });
      } else if (slot.templateId) {
        await api.setForSlot({
          templateId: slot.templateId,
          week: toIsoDate(weekStart),
          status,
        });
      }
      await load(toIsoDate(weekStart));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise à jour');
    } finally {
      setUpdatingKey(null);
    }
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Mes Présences' }} />
        <WeekNavigator weekStart={weekStart} onChange={setWeekStart} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Mes Présences' }} />
      <View style={styles.header}>
        <Text style={styles.subtitle}>
          Positionnez-vous à l'avance ou confirmez votre présence sur les créneaux.
        </Text>
      </View>

      <WeekNavigator weekStart={weekStart} onChange={setWeekStart} />

      <ScrollView
        contentContainerStyle={styles.scrollContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
      >
        {/* Filtre sport */}
        <View style={styles.filterRow}>
          {SPORT_FILTERS.map((f) => (
            <Pressable
              key={f.key}
              onPress={() => setSportFilter(f.key)}
              style={[styles.filterChip, sportFilter === f.key && styles.filterChipActive]}
            >
              <Text style={[styles.filterLabel, sportFilter === f.key && styles.filterLabelActive]}>
                {f.label}
              </Text>
            </Pressable>
          ))}
        </View>

        {error && <Text style={styles.errorBanner}>{error}</Text>}

        {filteredSlots.length === 0 ? (
          <EmptyState
            icon="📅"
            title="Aucun créneau"
            message={sportFilter === 'all'
              ? "Pas de créneau cette semaine."
              : "Aucun créneau ne correspond au sport sélectionné."}
          />
        ) : (
          [1, 2, 3, 4, 5, 6, 7].map((day) => {
            const slots = slotsByDay.get(day) ?? [];
            if (slots.length === 0) return null;
            const dayDate = addDays(weekStart, day - 1);
            return (
              <View key={day} style={styles.dayBlock}>
                <Text style={styles.dayHeader}>
                  {dayLabel(day)} <Text style={styles.daySub}>· {shortDayLabel(dayDate)}</Text>
                </Text>
                {slots.map((s, idx) => {
                  const key = `slot-${s.id ?? `tpl${s.templateId}`}`;
                  return (
                    <SlotCard
                      key={`${key}-${idx}`}
                      slot={s}
                      busy={updatingKey === key}
                      onSetStatus={(status) => setStatus(s, status)}
                    />
                  );
                })}
              </View>
            );
          })
        )}

        {(data?.customTasks ?? []).length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>📋 Mes tâches hors entraînement</Text>
            {(data?.customTasks ?? []).map((t) => (
              <View key={t.id} style={styles.customCard}>
                <Text style={styles.customTitle}>{t.title}</Text>
                <Text style={styles.customMeta}>
                  {t.date} · {t.startTime} · {t.durationMinutes} min
                </Text>
              </View>
            ))}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function SlotCard({
  slot,
  busy,
  onSetStatus,
}: {
  slot: StaffPresenceSlot;
  busy: boolean;
  onSetStatus: (status: StaffPresenceStatus | null) => void;
}) {
  const presence = slot.myPresence;
  const isPlanned = presence?.status === 'scheduled';
  const isAttended = presence?.status === 'attended';
  const today = toIsoDate(new Date());
  const isPast = slot.date < today;

  return (
    <View style={styles.slot}>
      <View style={styles.slotTimeCol}>
        <Text style={styles.slotTime}>{slot.startTime}</Text>
        <Text style={styles.slotDuration}>{slot.durationMinutes} min</Text>
      </View>
      <View style={styles.slotBody}>
        <Text style={styles.slotTitle}>{slot.title}</Text>
        <View style={styles.slotMeta}>
          <SportBadge icon={slot.sportIcon} label={slot.sportLabel} color={slot.sportColor} size="sm" />
        </View>
        <Text style={styles.slotLocation}>📍 {slot.location}</Text>

        <View style={styles.actions}>
          {busy ? (
            <ActivityIndicator color={COLORS.secondary} />
          ) : (
            <>
              {/* Bouton "Je serai là" / "J'étais là" selon date passé/futur */}
              <Pressable
                onPress={() => onSetStatus(isPast ? 'attended' : 'scheduled')}
                style={[
                  styles.actionBtn,
                  isPlanned && styles.actionBtnPlanned,
                  isAttended && styles.actionBtnAttended,
                ]}
              >
                <Text
                  style={[
                    styles.actionLabel,
                    (isPlanned || isAttended) && styles.actionLabelActive,
                  ]}
                >
                  {isAttended ? '✓ Présent' : isPlanned ? '✓ Réservé' : isPast ? "J'étais là" : 'Je serai là'}
                </Text>
              </Pressable>

              {/* Marquer présent si déjà réservé et passé/jour J */}
              {isPlanned && !isPast && (
                <Pressable onPress={() => onSetStatus('attended')} style={styles.actionBtnSecondary}>
                  <Text style={styles.actionLabelSecondary}>Confirmer présence</Text>
                </Pressable>
              )}

                {/* Annuler */}
                {presence && (
                <Pressable onPress={() => onSetStatus(null)} style={styles.actionBtnDanger}>
                  <Text style={styles.actionLabelDanger}>Annuler</Text>
                </Pressable>
              )}
            </>
          )}
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: {
    backgroundColor: COLORS.surface,
    padding: SPACING.lg,
    paddingBottom: SPACING.md,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  backBtn: { alignSelf: 'flex-start', paddingVertical: 4 },
  backLabel: { color: COLORS.secondary, fontSize: 14, fontWeight: '600' },
  title: { fontSize: 22, fontWeight: '800', color: COLORS.text, marginTop: 4 },
  subtitle: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  scrollContent: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  filterRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: SPACING.md },
  filterChip: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: RADIUS.full,
    backgroundColor: COLORS.surface,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  filterChipActive: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  filterLabel: { fontSize: 13, color: COLORS.text, fontWeight: '600' },
  filterLabelActive: { color: '#fff' },
  errorBanner: {
    backgroundColor: '#FEE',
    color: COLORS.error,
    padding: 12,
    fontSize: 13,
    textAlign: 'center',
    marginBottom: SPACING.md,
    borderRadius: RADIUS.sm,
  },
  dayBlock: { marginBottom: SPACING.md },
  dayHeader: {
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.secondaryDark,
    marginBottom: 6,
    paddingHorizontal: 4,
  },
  daySub: { color: COLORS.textMuted, fontWeight: '500', fontSize: 13 },
  section: { marginTop: SPACING.lg },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.textMuted,
    marginBottom: SPACING.sm,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  customCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    ...SHADOWS.sm,
  },
  customTitle: { fontSize: 15, fontWeight: '600', color: COLORS.text },
  customMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  slot: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  slotCancelled: { opacity: 0.65 },
  cancelledBadge: {
    alignSelf: 'flex-start',
    backgroundColor: '#FEE2E2',
    borderColor: '#991B1B',
    borderWidth: 1,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: RADIUS.full,
    marginTop: 4,
  },
  cancelledLabel: { color: '#991B1B', fontSize: 11, fontWeight: '700' },
  slotTimeCol: { minWidth: 60, paddingTop: 2 },
  slotTime: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  slotDuration: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  slotBody: { flex: 1, gap: 4 },
  slotTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  slotMeta: { flexDirection: 'row', gap: 6, alignItems: 'center', marginTop: 2 },
  slotLocation: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  slotInfo: { fontSize: 12, color: COLORS.warning, marginTop: 4 },
  actions: { flexDirection: 'row', gap: 6, marginTop: 10, flexWrap: 'wrap' },
  actionBtn: {
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
    backgroundColor: COLORS.surfaceAlt,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  actionBtnPlanned: { backgroundColor: COLORS.secondarySoft, borderColor: COLORS.secondary },
  actionBtnAttended: { backgroundColor: '#dcfce7', borderColor: COLORS.success },
  actionLabel: { fontSize: 13, fontWeight: '600', color: COLORS.text },
  actionLabelActive: { color: COLORS.text },
  actionBtnSecondary: {
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
    backgroundColor: COLORS.success,
  },
  actionLabelSecondary: { fontSize: 13, fontWeight: '600', color: '#fff' },
  actionBtnDanger: {
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.error,
  },
  actionLabelDanger: { fontSize: 13, fontWeight: '600', color: COLORS.error },
});
