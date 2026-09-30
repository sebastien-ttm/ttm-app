import { Stack } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staffPresence as api } from '@/api/resources';
import type { StaffPresenceTemplateSlot } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { SportBadge } from '@/components/SportBadge';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { dayLabel, formatDurationHm } from '@/utils/week';

/**
 * Configuration de « ma semaine type » : pour chaque créneau récurrent
 * du club, l'encadrant/entraîneur indique s'il y est présent en temps
 * normal. Valable tant que la semaine type du club ne change pas (donc
 * de facto pour la saison) — s'applique ensuite à une semaine précise
 * en un clic depuis l'écran « Mes encadrements ».
 */
export default function StaffPresenceTemplateScreen() {
  const { user } = useAuth();
  const [slots, setSlots] = useState<StaffPresenceTemplateSlot[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [togglingId, setTogglingId] = useState<number | null>(null);

  if (user && !user.profiles.includes('encadrant') && !user.profiles.includes('entraineur')) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Ma semaine type' }} />
        <EmptyState
          icon="🔒"
          title="Accès réservé"
          message="Cette page est réservée aux encadrants et entraîneurs du club."
        />
      </SafeAreaView>
    );
  }

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await api.getTemplate();
      setSlots(resp.slots);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const slotsByDay = useMemo(() => {
    const map = new Map<number, StaffPresenceTemplateSlot[]>();
    (slots ?? []).forEach((s) => {
      const arr = map.get(s.dayOfWeek) ?? [];
      arr.push(s);
      map.set(s.dayOfWeek, arr);
    });
    return map;
  }, [slots]);

  const presentCount = (slots ?? []).filter((s) => s.present).length;

  async function toggle(slot: StaffPresenceTemplateSlot) {
    if (!slots) return;
    const next = !slot.present;
    setTogglingId(slot.slotTemplateId);
    // Optimiste : bascule immédiate, resynchronisé/annulé selon la réponse.
    setSlots(slots.map((s) => (s.slotTemplateId === slot.slotTemplateId ? { ...s, present: next } : s)));
    try {
      await api.setTemplateSlot(slot.slotTemplateId, next);
    } catch (e) {
      setSlots(slots);
      setError(e instanceof ApiError ? e.message : 'Erreur mise à jour');
    } finally {
      setTogglingId(null);
    }
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Ma semaine type' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }
  if (error && slots === null) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Ma semaine type' }} />
        <ErrorState message={error} onRetry={load} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Ma semaine type' }} />
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Où êtes-vous présent(e) habituellement ?</Text>
        <Text style={styles.headerSub}>
          {presentCount > 0
            ? `${presentCount} créneau${presentCount > 1 ? 'x' : ''} sélectionné${presentCount > 1 ? 's' : ''}`
            : 'Aucun créneau sélectionné pour le moment'}
          {' · '}Depuis « Mes encadrements », un bouton applique ce modèle à la semaine affichée.
        </Text>
      </View>

      <ScrollView contentContainerStyle={styles.scrollContent}>
        {error && <Text style={styles.errorBanner}>{error}</Text>}

        {(slots ?? []).length === 0 ? (
          <EmptyState icon="📅" title="Aucun créneau" message="La semaine type du club n'a pas encore de créneau actif." />
        ) : (
          [1, 2, 3, 4, 5, 6, 7].map((day) => {
            const daySlots = slotsByDay.get(day) ?? [];
            if (daySlots.length === 0) return null;
            return (
              <View key={day} style={styles.dayBlock}>
                <Text style={styles.dayHeader}>{dayLabel(day)}</Text>
                {daySlots.map((s) => (
                  <Pressable
                    key={s.slotTemplateId}
                    onPress={() => void toggle(s)}
                    disabled={togglingId === s.slotTemplateId}
                    style={[styles.slot, s.present && styles.slotActive]}
                  >
                    <View style={styles.slotTimeCol}>
                      <Text style={styles.slotTime}>{s.startTime}</Text>
                      <Text style={styles.slotDuration}>{formatDurationHm(s.durationMinutes)}</Text>
                    </View>
                    <View style={styles.slotBody}>
                      <Text style={styles.slotTitle}>{s.title}</Text>
                      <View style={styles.slotMeta}>
                        <SportBadge icon={s.sportIcon} label={s.sportLabel} color={s.sportColor} size="sm" />
                      </View>
                      <Text style={styles.slotLocation}>📍 {s.location}</Text>
                    </View>
                    {togglingId === s.slotTemplateId ? (
                      <ActivityIndicator color={COLORS.secondary} />
                    ) : (
                      // pointerEvents="none" : le Switch est purement visuel
                      // ici, seul le Pressable parent gère le tap — sinon un
                      // appui sur le Switch déclenche AUSSI le onPress du
                      // Pressable (bulles sur web), doublant l'appel à
                      // toggle() et provoquant une 2e requête concurrente
                      // (violation de contrainte d'unicité côté serveur).
                      <View pointerEvents="none">
                        <Switch
                          value={s.present}
                          trackColor={{ true: COLORS.secondary }}
                        />
                      </View>
                    )}
                  </Pressable>
                ))}
              </View>
            );
          })
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: {
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.lg,
    paddingVertical: SPACING.sm,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  headerTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  headerSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 4, lineHeight: 17 },
  scrollContent: { padding: SPACING.md, paddingBottom: SPACING.xxl },
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
  slot: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    ...SHADOWS.sm,
  },
  slotActive: { borderColor: COLORS.secondary, backgroundColor: COLORS.secondarySoft },
  slotTimeCol: { minWidth: 56 },
  slotTime: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  slotDuration: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  slotBody: { flex: 1, gap: 4 },
  slotTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  slotMeta: { flexDirection: 'row', gap: 6, alignItems: 'center', marginTop: 2 },
  slotLocation: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
});
