import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { TrainingSlot, TrainingSlotAttachment } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { SportBadge } from '@/components/SportBadge';
import { API_BASE_URL, COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { dayLabel, formatDurationHm, fromIsoDate } from '@/utils/week';

/**
 * Détail d'un créneau d'entraînement — description longue, pièces
 * jointes, métadonnées complètes. Alimenté par un objet JSON passé
 * en query param depuis la liste de la semaine (pas de refetch —
 * l'objet est déjà en mémoire côté liste).
 *
 * Pour les créneaux virtuels (id=null : projection d'un template
 * de semaine type non-encore matérialisée), on affiche la même vue
 * sans que l'URL doive porter d'ID côté serveur.
 */
export default function TrainingSlotDetailScreen() {
  const router = useRouter();
  const { slot: rawSlot } = useLocalSearchParams<{ slot?: string }>();

  const slot: TrainingSlot | null = useMemo(() => {
    if (typeof rawSlot !== 'string' || rawSlot === '') return null;
    try { return JSON.parse(rawSlot) as TrainingSlot; } catch { return null; }
  }, [rawSlot]);

  if (!slot) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Créneau' }} />
        <View style={styles.errorBox}>
          <Text style={styles.errorLabel}>Créneau introuvable.</Text>
          <Pressable onPress={() => router.back()} style={styles.backLink}>
            <Text style={styles.backLinkLabel}>← Retour</Text>
          </Pressable>
        </View>
      </SafeAreaView>
    );
  }

  const start = new Date(`${slot.date}T${slot.startTime}:00`);
  const isPast = Number.isFinite(start.getTime())
    && (start.getTime() + slot.durationMinutes * 60_000) < Date.now();
  const endTime = new Date(start.getTime() + slot.durationMinutes * 60_000)
    .toTimeString().slice(0, 5);
  const dayDate = fromIsoDate(slot.date);
  const dayName = dayLabel(slot.dayOfWeek);

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: slot.sportLabel }} />
      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.headerCard}>
          <View style={styles.sportRow}>
            <SportBadge icon={slot.sportIcon} label={slot.sportLabel} color={slot.sportColor} size="md" />
            {slot.isOccasional && <Tag color={COLORS.secondary} label="Occasionnel" />}
            {slot.isOverride && !slot.isOccasional && <Tag color="#92400E" bg="#FEF3C7" label="Modifié" />}
            {isPast && <Tag color={COLORS.textMuted} bg={COLORS.background} label="Passé" />}
          </View>

          <Text style={styles.title}>{slot.title}</Text>

          <View style={styles.metaRow}>
            <Ionicons name="calendar-outline" size={18} color={COLORS.textMuted} />
            <Text style={styles.metaValue}>
              {dayName} {dayDate.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}
            </Text>
          </View>
          <View style={styles.metaRow}>
            <Ionicons name="time-outline" size={18} color={COLORS.textMuted} />
            <Text style={styles.metaValue}>
              {slot.startTime} – {endTime} <Text style={styles.metaSub}>({formatDurationHm(slot.durationMinutes)})</Text>
            </Text>
          </View>
          <View style={styles.metaRow}>
            <Ionicons name="location-outline" size={18} color={COLORS.textMuted} />
            <Text style={styles.metaValue}>{slot.location}</Text>
          </View>
        </View>

        {slot.description ? (
          <View style={styles.card}>
            <Text style={styles.cardTitle}>Description</Text>
            <Text style={styles.description}>{slot.description}</Text>
          </View>
        ) : null}

        {slot.attachments.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.cardTitle}>Documents ({slot.attachments.length})</Text>
            {slot.attachments.map((att) => (
              <AttachmentLink key={att.id} attachment={att} />
            ))}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Tag({ label, color, bg }: { label: string; color: string; bg?: string }) {
  return (
    <View style={[styles.tag, { borderColor: color, backgroundColor: bg ?? 'transparent' }]}>
      <Text style={[styles.tagLabel, { color }]}>{label}</Text>
    </View>
  );
}

function AttachmentLink({ attachment }: { attachment: TrainingSlotAttachment }) {
  async function open() {
    const token = await storage.getItem(STORAGE_KEYS.accessToken);
    const url =
      `${API_BASE_URL}/api/training-slots/attachments/${attachment.id}/file`
      + (token ? `?bearer=${encodeURIComponent(token)}` : '');
    await WebBrowser.openBrowserAsync(url);
  }
  return (
    <Pressable onPress={open} style={({ pressed }) => [styles.attachmentChip, pressed && { opacity: 0.85 }]}>
      <Text style={styles.attachmentIcon}>📎</Text>
      <Text style={styles.attachmentName} numberOfLines={1}>{attachment.name}</Text>
      <Text style={styles.attachmentSize}>{attachment.humanSize}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  headerCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.lg,
    marginBottom: SPACING.md,
    gap: SPACING.sm,
    ...SHADOWS.sm,
  },
  sportRow: { flexDirection: 'row', alignItems: 'center', gap: 6, flexWrap: 'wrap' },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginTop: 4 },
  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  metaValue: { fontSize: 15, color: COLORS.text, fontWeight: '500', flex: 1 },
  metaSub: { color: COLORS.textMuted, fontWeight: '400' },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    gap: SPACING.sm,
    ...SHADOWS.sm,
  },
  cardTitle: {
    fontSize: 13, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5,
  },
  description: { fontSize: 15, color: COLORS.text, lineHeight: 22 },
  tag: {
    borderWidth: 1,
    borderRadius: RADIUS.full,
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  tagLabel: { fontSize: 11, fontWeight: '600' },
  attachmentChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: COLORS.secondarySoft,
    borderRadius: RADIUS.sm,
    paddingHorizontal: 10,
    paddingVertical: 8,
  },
  attachmentIcon: { fontSize: 14 },
  attachmentName: { flex: 1, fontSize: 14, color: COLORS.secondaryDark, fontWeight: '500' },
  attachmentSize: { fontSize: 12, color: COLORS.textMuted },
  errorBox: {
    padding: SPACING.xl, alignItems: 'center',
  },
  errorLabel: { color: COLORS.textMuted, fontSize: 15, marginBottom: SPACING.md },
  backLink: { padding: 10 },
  backLinkLabel: { color: COLORS.primary, fontWeight: '600' },
});
