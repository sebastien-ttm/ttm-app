import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import { trainingSchedule as scheduleApi } from '@/api/resources';
import type { TrainingPlan, TrainingSlot, TrainingSlotAttachment, WeeklySchedule } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { SportBadge } from '@/components/SportBadge';
import { WeekNavigator } from '@/components/WeekNavigator';
import { API_BASE_URL, COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { addDays, dayLabel, fromIsoDate, getMonday, shortDayLabel, toIsoDate } from '@/utils/week';
import { formatDate } from '@/utils/html';

export default function TrainingScreen() {
  const router = useRouter();
  const [weekStart, setWeekStart] = useState<Date>(() => getMonday(new Date()));
  const [data, setData] = useState<WeeklySchedule | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (mondayIso: string) => {
    try {
      setError(null);
      const resp = await scheduleApi.week(mondayIso);
      setData(resp);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
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
    return () => {
      cancelled = true;
    };
  }, [weekStart, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(toIsoDate(weekStart));
    setRefreshing(false);
  }, [weekStart, load]);

  // Groupement par jour de la semaine. Les créneaux annulés ne sont
  // pas affichés côté adhérent — ils n'ont plus lieu d'être visibles.
  const slotsByDay = useMemo(() => {
    const map = new Map<number, TrainingSlot[]>();
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
    <View style={styles.root}>
      <WeekNavigator weekStart={weekStart} onChange={setWeekStart} disablePast />

      {loading ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={() => load(toIsoDate(weekStart))} />
      ) : (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={
            <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />
          }
        >
          {/* Plans (PDF) de la semaine */}
          {(data?.plans ?? []).length > 0 && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>📄 Plans de la semaine</Text>
              {data!.plans.map((p) => (
                <PlanRow key={p.id} plan={p} onOpen={() => router.push(`/training-plan/${p.id}` as never)} />
              ))}
            </View>
          )}

          {/* Créneaux jour par jour */}
          {(data?.slots ?? []).length === 0 ? (
            <EmptyState
              icon="📅"
              title="Aucun créneau cette semaine"
              message="Les entraîneurs n'ont pas (encore) défini de créneau pour cette semaine."
            />
          ) : (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>📅 Créneaux d'entraînement</Text>
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
            </View>
          )}
        </ScrollView>
      )}
    </View>
  );
}

function SlotRow({ slot }: { slot: TrainingSlot }) {
  return (
    <View style={styles.slot}>
      <View style={styles.slotTimeCol}>
        <Text style={styles.slotTime}>
          {slot.startTime}
        </Text>
        <Text style={styles.slotDuration}>{slot.durationMinutes} min</Text>
      </View>
      <View style={styles.slotBody}>
        <View style={styles.slotTitleRow}>
          <Text style={styles.slotTitle} numberOfLines={2}>
            {slot.title}
          </Text>
        </View>
        <View style={styles.slotMeta}>
          <SportBadge icon={slot.sportIcon} label={slot.sportLabel} color={slot.sportColor} size="sm" />
          {slot.isOccasional && <Tag color={COLORS.secondary} label="Occasionnel" />}
          {slot.isOverride && !slot.isOccasional && <Tag color="#92400E" bg="#FEF3C7" label="Modifié" />}
        </View>
        <Text style={styles.slotLocation}>📍 {slot.location}</Text>
        {slot.description ? (
          <Text style={styles.slotDescription} numberOfLines={3}>
            {slot.description}
          </Text>
        ) : null}
        {slot.attachments.length > 0 && (
          <View style={styles.attachments}>
            {slot.attachments.map((att) => (
              <AttachmentLink key={att.id} attachment={att} />
            ))}
          </View>
        )}
      </View>
    </View>
  );
}

function AttachmentLink({ attachment }: { attachment: TrainingSlotAttachment }) {
  async function open() {
    const token = await storage.getItem(STORAGE_KEYS.accessToken);
    const url =
      `${API_BASE_URL}/api/training-slots/attachments/${attachment.id}/file` +
      (token ? `?bearer=${encodeURIComponent(token)}` : '');
    await WebBrowser.openBrowserAsync(url);
  }
  return (
    <Pressable onPress={open} style={({ pressed }) => [styles.attachmentChip, pressed && styles.pressed]}>
      <Text style={styles.attachmentIcon}>📎</Text>
      <Text style={styles.attachmentName} numberOfLines={1}>{attachment.name}</Text>
      <Text style={styles.attachmentSize}>{attachment.humanSize}</Text>
    </Pressable>
  );
}

function Tag({ label, color, bg }: { label: string; color: string; bg?: string }) {
  return (
    <View style={[styles.tag, { borderColor: color, backgroundColor: bg ?? 'transparent' }]}>
      <Text style={[styles.tagLabel, { color }]}>{label}</Text>
    </View>
  );
}

function PlanRow({ plan, onOpen }: { plan: TrainingPlan; onOpen: () => void }) {
  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.planRow, pressed && styles.pressed]}>
      <View style={styles.planIcon}>
        <Text style={{ fontSize: 22 }}>📄</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.planTitle}>{plan.displayTitle}</Text>
        {plan.description ? (
          <Text style={styles.planDesc} numberOfLines={2}>
            {plan.description}
          </Text>
        ) : null}
        <Text style={styles.planMeta}>
          Par {plan.postedBy.fullName} · {formatDate(plan.postedAt)}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  section: { marginBottom: SPACING.lg },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.textMuted,
    marginBottom: SPACING.sm,
    marginLeft: 4,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
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
  slot: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  slotCancelled: { opacity: 0.7 },
  cancelledText: { textDecorationLine: 'line-through' },
  slotTimeCol: {
    minWidth: 60,
    alignItems: 'flex-start',
    paddingTop: 2,
  },
  slotTime: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  slotDuration: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  slotBody: { flex: 1, gap: 4 },
  slotTitleRow: { flexDirection: 'row', alignItems: 'flex-start' },
  slotTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text, flex: 1 },
  slotMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center', marginTop: 2 },
  slotLocation: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  slotDescription: { fontSize: 13, color: COLORS.text, marginTop: 4, lineHeight: 18 },
  attachments: {
    flexDirection: 'column',
    gap: 4,
    marginTop: 8,
  },
  attachmentChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: COLORS.secondarySoft,
    borderRadius: RADIUS.sm,
    paddingHorizontal: 8,
    paddingVertical: 5,
  },
  attachmentIcon: { fontSize: 14 },
  attachmentName: { flex: 1, fontSize: 13, color: COLORS.secondaryDark, fontWeight: '500' },
  attachmentSize: { fontSize: 11, color: COLORS.textMuted },
  tag: {
    borderWidth: 1,
    borderRadius: RADIUS.full,
    paddingHorizontal: 8,
    paddingVertical: 1,
  },
  tagLabel: { fontSize: 11, fontWeight: '600' },
  planRow: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  pressed: { opacity: 0.85 },
  planIcon: {
    width: 44,
    height: 44,
    borderRadius: RADIUS.md,
    backgroundColor: COLORS.secondarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  planTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  planDesc: { fontSize: 13, color: COLORS.text, marginTop: 4, lineHeight: 18 },
  planMeta: { fontSize: 11, color: COLORS.textMuted, marginTop: 6 },
});
