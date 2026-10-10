import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { TrainingSlot, TrainingSlotAttachment } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { SportBadge } from '@/components/SportBadge';
import { API_BASE_URL, COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { openAttachment, withBearer } from '@/lib/openAttachment';
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
  const goBack = useGoBackOrHome();
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
          <Pressable onPress={goBack} style={styles.backLink}>
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
            {slot.isCancelled && <Tag color="#991B1B" bg="#FEE2E2" label="Annulé" />}
            {slot.isOccasional && <Tag color={COLORS.secondary} label="Occasionnel" />}
            {slot.isOverride && !slot.isOccasional && <Tag color="#92400E" bg="#FEF3C7" label="Modifié" />}
            {isPast && <Tag color={COLORS.textMuted} bg={COLORS.background} label="Passé" />}
          </View>

          <Text style={[styles.title, slot.isCancelled && styles.titleCancelled]}>{slot.title}</Text>

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

        <Pressable onPress={goBack} style={styles.backBtn}>
          <Text style={styles.backBtnLabel}>Retour</Text>
        </Pressable>
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

/** PDF et images : affichables dans le navigateur intégré. */
function isBrowserViewable(att: TrainingSlotAttachment): boolean {
  const mime = (att.mimeType ?? '').toLowerCase();
  return mime === 'application/pdf' || mime.startsWith('image/') || /\.(pdf|jpe?g|png|gif|webp)$/i.test(att.name);
}

/** Durée pendant laquelle « Téléchargement en cours » reste affiché avant de passer à « téléchargé ». */
const DOWNLOAD_FEEDBACK_MS = 2500;

/** Redemande confirmation avant de retélécharger un fichier déjà téléchargé (évite les doublons). */
function confirmRedownload(name: string): Promise<boolean> {
  const message = `« ${name} » a déjà été téléchargé : vous le trouverez dans les Téléchargements de votre téléphone. Le télécharger à nouveau créerait un doublon.`;
  if (Platform.OS === 'web') {
    return Promise.resolve(window.confirm(`${message}\n\nLe télécharger à nouveau ?`));
  }
  return new Promise((resolve) => {
    Alert.alert(
      'Déjà téléchargé',
      message,
      [
        { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
        { text: 'Télécharger à nouveau', onPress: () => resolve(true) },
      ],
      { cancelable: true, onDismiss: () => resolve(false) },
    );
  });
}

function AttachmentLink({ attachment }: { attachment: TrainingSlotAttachment }) {
  // Les PDF / images s'affichent ; GPX & co sont envoyés en téléchargement par le
  // serveur — sans retour visuel du navigateur, d'où l'état ci-dessous : l'adhérent
  // voit que ça charge, puis où retrouver le fichier, et n'appuie plus deux fois.
  const isDownload = !isBrowserViewable(attachment);
  const [status, setStatus] = useState<'idle' | 'loading' | 'done'>('idle');
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  function notifyError() {
    const msg = `Le fichier « ${attachment.name} » n'a pas pu être ouvert. Réessayez plus tard.`;
    // Alert.alert ne fait rien sur le web.
    if (Platform.OS === 'web') window.alert(msg);
    else Alert.alert('Ouverture impossible', msg);
  }

  function buildUrl(token: string | null): string {
    return withBearer(`${API_BASE_URL}/api/training-slots/attachments/${attachment.id}/file`, token);
  }

  /**
   * Un simple appui, sans « Ouvrir avec… » ni menu de partage : PDF et
   * images s'affichent ; GPX & co sont envoyés en téléchargement par le
   * serveur (Content-Disposition: attachment) et le navigateur les
   * enregistre. Web : nouvel onglet ; appli native : navigateur du
   * système pour les fichiers non affichables (l'intégré ne télécharge pas).
   */
  async function open() {
    if (status === 'loading') return;
    if (isDownload && status === 'done' && !(await confirmRedownload(attachment.name))) return;

    if (isDownload) {
      if (timer.current) clearTimeout(timer.current);
      setStatus('loading');
    }
    try {
      if (Platform.OS !== 'web' && isDownload) {
        await Linking.openURL(buildUrl(await storage.getItem(STORAGE_KEYS.accessToken)));
      } else {
        await openAttachment(buildUrl);
      }
      if (isDownload) {
        timer.current = setTimeout(() => setStatus('done'), DOWNLOAD_FEEDBACK_MS);
      }
    } catch {
      setStatus('idle');
      notifyError();
    }
  }

  return (
    <View style={styles.attachmentWrap}>
      <Pressable
        onPress={open}
        disabled={status === 'loading'}
        style={({ pressed }) => [styles.attachmentChip, (pressed || status === 'loading') && { opacity: 0.85 }]}
      >
        <Text style={styles.attachmentIcon}>{/\.gpx$/i.test(attachment.name) ? '🗺️' : '📎'}</Text>
        <Text style={styles.attachmentName} numberOfLines={1}>{attachment.name}</Text>
        {status === 'loading' ? (
          <ActivityIndicator size="small" color={COLORS.secondaryDark} />
        ) : status === 'done' ? (
          <Ionicons name="checkmark-circle" size={20} color={COLORS.success} />
        ) : (
          <Text style={styles.attachmentSize}>{attachment.humanSize}</Text>
        )}
      </Pressable>
      {status === 'loading' && (
        <Text style={styles.attachmentStatus}>⏳ Téléchargement en cours…</Text>
      )}
      {status === 'done' && (
        <Text style={[styles.attachmentStatus, { color: COLORS.success }]}>
          ✅ Fichier téléchargé : retrouvez-le dans les Téléchargements de votre téléphone.
        </Text>
      )}
    </View>
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
  titleCancelled: { textDecorationLine: 'line-through', color: COLORS.textMuted },
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
  attachmentWrap: { gap: 4 },
  attachmentStatus: { fontSize: 12, color: COLORS.textMuted, paddingHorizontal: 4 },
  attachmentIcon: { fontSize: 14 },
  attachmentName: { flex: 1, fontSize: 14, color: COLORS.secondaryDark, fontWeight: '500' },
  attachmentSize: { fontSize: 12, color: COLORS.textMuted },
  errorBox: {
    padding: SPACING.xl, alignItems: 'center',
  },
  errorLabel: { color: COLORS.textMuted, fontSize: 15, marginBottom: SPACING.md },
  backLink: { padding: 10 },
  backLinkLabel: { color: COLORS.primary, fontWeight: '600' },
  backBtn: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.sm },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
