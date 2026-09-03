import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Linking,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { gouters as api } from '@/api/resources';
import type { GouterSlot } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { canSeeGouter } from '@/utils/profile';
import { formatDate } from '@/utils/html';

export default function GouterScreen() {
  const { user } = useAuth();
  const canSee = canSeeGouter(user);

  const [slots, setSlots] = useState<GouterSlot[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busyDate, setBusyDate] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await api.planning();
      setSlots(resp.slots);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    if (!canSee) return;
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load();
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [canSee, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  async function signup(date: string) {
    setBusyDate(date);
    setFlash(null);
    try {
      await api.signup(date);
      await load();
      // Propose l'ajout au calendrier personnel juste après l'inscription
      // réussie. Approche cross-platform sans dépendance : sur web on
      // télécharge un .ics (le navigateur / l'OS l'ouvre dans le calendrier
      // par défaut) ; sur natif on ouvre le data-URL via Linking, iOS et
      // Android l'associent au calendrier système.
      askAddToCalendar(date);
    } catch (err) {
      setFlash(err instanceof Error ? err.message : 'Erreur');
    } finally {
      setBusyDate(null);
    }
  }

  async function cancel(id: number, date: string) {
    setBusyDate(date);
    setFlash(null);
    try {
      await api.cancel(id);
      await load();
    } catch (err) {
      setFlash(err instanceof Error ? err.message : 'Erreur');
    } finally {
      setBusyDate(null);
    }
  }

  if (!canSee) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Goûters du mercredi' }} />
        <EmptyState
          icon="🍪"
          title="Accès réservé"
          message="Le planning goûter est réservé aux parents et aux jeunes du club."
        />
      </SafeAreaView>
    );
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Goûters du mercredi' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }

  if (error && slots.length === 0) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Goûters du mercredi' }} />
        <ErrorState message={error} onRetry={load} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Goûters du mercredi' }} />

      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />
        }
      >
        <View style={styles.intro}>
          <Text style={styles.introIcon}>🍪</Text>
          <Text style={styles.introText}>
            Positionnez-vous sur un mercredi pour amener le goûter. 2 personnes
            par créneau — inscrivez-vous à l'avance et voyez qui a déjà signé.
          </Text>
        </View>

        {flash && (
          <View style={styles.flash}>
            <Text style={styles.flashText}>{flash}</Text>
          </View>
        )}

        {slots.length === 0 ? (
          <EmptyState
            icon="📅"
            title="Aucun mercredi à afficher"
            message="Il n'y a pas de mercredi programmé dans les semaines à venir."
          />
        ) : (
          slots.map((slot) => (
            <SlotCard
              key={slot.date}
              slot={slot}
              busy={busyDate === slot.date}
              onSignup={() => signup(slot.date)}
              onCancel={(id) => cancel(id, slot.date)}
            />
          ))
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

/**
 * Génère le contenu ICS (RFC 5545) d'un événement toute-la-journée
 * pour le goûter du mercredi. Format canonique compact — supporté par
 * tous les clients calendrier (Apple, Google, Outlook, etc.).
 */
function buildGouterIcs(date: string): string {
  const ymd = date.replace(/-/g, ''); // '2026-11-12' → '20261112'
  // DTEND en all-day = jour suivant (fin exclusive selon RFC 5545)
  const dt = new Date(date + 'T00:00:00');
  dt.setDate(dt.getDate() + 1);
  const ymdEnd = [dt.getFullYear(),
    String(dt.getMonth() + 1).padStart(2, '0'),
    String(dt.getDate()).padStart(2, '0')].join('');
  const now = new Date();
  const stamp = [
    now.getUTCFullYear(),
    String(now.getUTCMonth() + 1).padStart(2, '0'),
    String(now.getUTCDate()).padStart(2, '0'),
    'T',
    String(now.getUTCHours()).padStart(2, '0'),
    String(now.getUTCMinutes()).padStart(2, '0'),
    String(now.getUTCSeconds()).padStart(2, '0'),
    'Z',
  ].join('');
  const uid = `ttm-gouter-${ymd}-${now.getTime()}@ttm`;
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//TTM//gouter//FR',
    'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    `UID:${uid}`,
    `DTSTAMP:${stamp}`,
    `DTSTART;VALUE=DATE:${ymd}`,
    `DTEND;VALUE=DATE:${ymdEnd}`,
    'SUMMARY:Goûter TTM',
    'DESCRIPTION:Vous êtes inscrit pour amener le goûter des jeunes ce mercredi.',
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n');
}

/**
 * Propose (via un dialog natif) d'ajouter la date au calendrier
 * personnel de l'utilisateur. Sur "oui", télécharge / ouvre l'ICS.
 */
function askAddToCalendar(date: string): void {
  const doAdd = () => downloadIcs(date);
  const message = 'Voulez-vous ajouter ce goûter à votre calendrier personnel ?';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined' && window.confirm(message)) {
      doAdd();
    }
    return;
  }
  Alert.alert(
    'Ajouter au calendrier ?',
    message,
    [
      { text: 'Non merci', style: 'cancel' },
      { text: 'Ajouter', onPress: doAdd },
    ],
  );
}

function downloadIcs(date: string): void {
  const ics = buildGouterIcs(date);
  const filename = `gouter-ttm-${date}.ics`;
  if (Platform.OS === 'web' && typeof window !== 'undefined' && typeof document !== 'undefined') {
    // Blob + <a download> — le navigateur télécharge le fichier,
    // l'OS l'ouvre dans le calendrier par défaut au double-clic.
    const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return;
  }
  // Natif : data-URL text/calendar ouvert par Linking — iOS et Android
  // reconnaissent le mime type et proposent le calendrier système.
  const dataUrl = 'data:text/calendar;charset=utf-8,' + encodeURIComponent(ics);
  void Linking.openURL(dataUrl);
}

function SlotCard({
  slot,
  busy,
  onSignup,
  onCancel,
}: {
  slot: GouterSlot;
  busy: boolean;
  onSignup: () => void;
  onCancel: (id: number) => void;
}) {
  const iAmIn = slot.signups.some((s) => s.isMine);
  const remaining = Math.max(0, slot.capacity - slot.signups.length);
  const isPast = new Date(slot.date + 'T00:00:00') < new Date(new Date().toDateString());
  const isCancelled = slot.isCancelled;

  return (
    <View style={[styles.card, isPast && styles.cardPast, isCancelled && styles.cardCancelled]}>
      <View style={styles.cardHeader}>
        <Text style={[styles.cardDate, isCancelled && styles.cardDateCancelled]}>{formatDate(slot.date + 'T12:00:00')}</Text>
        {isCancelled ? (
          <Text style={styles.badgeCancelled}>🚫 Annulé</Text>
        ) : isPast ? (
          <Text style={styles.badgePast}>passé</Text>
        ) : remaining === 0 ? (
          <Text style={styles.badgeFull}>Complet</Text>
        ) : (
          <Text style={styles.badgeAvail}>{remaining} place{remaining > 1 ? 's' : ''}</Text>
        )}
      </View>

      {isCancelled && slot.cancellationReason && (
        <Text style={styles.cancelReason}>« {slot.cancellationReason} »</Text>
      )}

      {!isCancelled && (
        <View style={styles.slotList}>
          {Array.from({ length: slot.capacity }).map((_, idx) => {
            const s = slot.signups[idx];
            if (s) {
              return (
                <View key={s.id} style={[styles.slotRow, s.isMine && styles.slotRowMine]}>
                  <Ionicons name="person" size={16} color={s.isMine ? COLORS.primary : COLORS.textMuted} />
                  <Text style={[styles.slotName, s.isMine && styles.slotNameMine]} numberOfLines={1}>
                    {s.fullName}
                    {s.isMine && ' (vous)'}
                  </Text>
                  {s.byAdmin && <Text style={styles.slotBadge}>ajouté</Text>}
                  {/* Bouton WhatsApp : visible uniquement quand on est
                      soi-même inscrit ET que ce n'est pas notre ligne
                      (whatsappUrl fourni par le backend selon cette règle). */}
                  {s.whatsappUrl && !isPast && (
                    <Pressable
                      onPress={() => void Linking.openURL(s.whatsappUrl!)}
                      hitSlop={8}
                      accessibilityLabel={`Contacter ${s.fullName} sur WhatsApp`}
                    >
                      <Ionicons name="logo-whatsapp" size={22} color="#25D366" />
                    </Pressable>
                  )}
                  {s.isMine && !isPast && (
                    <Pressable onPress={() => onCancel(s.id)} disabled={busy} hitSlop={8}>
                      <Ionicons name="close-circle" size={20} color={COLORS.error} />
                    </Pressable>
                  )}
                </View>
              );
            }
            return (
              <View key={`empty-${idx}`} style={[styles.slotRow, styles.slotRowEmpty]}>
                <Ionicons name="person-outline" size={16} color={COLORS.textSubtle} />
                <Text style={styles.slotEmptyText}>Place disponible</Text>
              </View>
            );
          })}
        </View>
      )}

      {!isCancelled && !isPast && !iAmIn && remaining > 0 && (
        <Pressable
          onPress={onSignup}
          disabled={busy}
          style={({ pressed }) => [styles.signupBtn, (busy || pressed) && { opacity: 0.7 }]}
        >
          {busy ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <>
              <Ionicons name="add-circle" size={18} color="#fff" />
              <Text style={styles.signupBtnLabel}>Je m'inscris</Text>
            </>
          )}
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, gap: SPACING.md, paddingBottom: SPACING.xxl },
  intro: {
    flexDirection: 'row',
    gap: SPACING.md,
    padding: SPACING.md,
    backgroundColor: COLORS.primarySoft,
    borderRadius: RADIUS.md,
    alignItems: 'center',
  },
  introIcon: { fontSize: 26 },
  introText: { flex: 1, fontSize: 13, color: COLORS.text, lineHeight: 18 },
  flash: {
    padding: SPACING.md,
    backgroundColor: '#fee2e2',
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: '#fca5a5',
  },
  flashText: { color: '#991b1b', fontSize: 13, fontWeight: '600' },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    padding: SPACING.md,
    ...SHADOWS.sm,
  },
  cardPast: { opacity: 0.6 },
  cardCancelled: { backgroundColor: '#fef2f2', borderColor: '#fecaca' },
  cardDateCancelled: { color: COLORS.textMuted, textDecorationLine: 'line-through' },
  badgeCancelled: {
    fontSize: 11, fontWeight: '700', color: '#991b1b',
    backgroundColor: '#fecaca', paddingHorizontal: 8, paddingVertical: 3,
    borderRadius: RADIUS.sm,
  },
  cancelReason: {
    fontSize: 12, color: '#7f1d1d', fontStyle: 'italic',
    marginTop: 4, marginBottom: SPACING.xs,
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: SPACING.sm,
  },
  cardDate: { fontSize: 15, fontWeight: '700', color: COLORS.text, textTransform: 'capitalize' },
  badgeFull: {
    fontSize: 11, fontWeight: '700', color: '#92400e',
    backgroundColor: '#fef3c7', paddingHorizontal: 8, paddingVertical: 3,
    borderRadius: RADIUS.sm,
  },
  badgeAvail: {
    fontSize: 11, fontWeight: '700', color: '#166534',
    backgroundColor: '#dcfce7', paddingHorizontal: 8, paddingVertical: 3,
    borderRadius: RADIUS.sm,
  },
  badgePast: {
    fontSize: 11, fontWeight: '700', color: COLORS.textMuted,
    backgroundColor: COLORS.background, paddingHorizontal: 8, paddingVertical: 3,
    borderRadius: RADIUS.sm,
  },
  slotList: { gap: 6 },
  slotRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 10,
    borderRadius: RADIUS.sm,
    backgroundColor: COLORS.surfaceAlt,
  },
  slotRowMine: {
    backgroundColor: COLORS.primarySoft,
    borderWidth: 1,
    borderColor: COLORS.primary,
  },
  slotRowEmpty: {
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: COLORS.borderStrong,
    backgroundColor: 'transparent',
  },
  slotName: { flex: 1, fontSize: 14, color: COLORS.text, fontWeight: '500' },
  slotNameMine: { color: COLORS.primaryDark, fontWeight: '700' },
  slotEmptyText: { flex: 1, fontSize: 13, color: COLORS.textSubtle, fontStyle: 'italic' },
  slotBadge: {
    fontSize: 10, fontWeight: '700',
    color: '#3730a3', backgroundColor: '#e0e7ff',
    paddingHorizontal: 6, paddingVertical: 2, borderRadius: RADIUS.sm,
  },
  signupBtn: {
    marginTop: SPACING.sm,
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 10,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
  },
  signupBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
});
