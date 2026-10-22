import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { trips } from '@/api/resources';
import type { TripSummary } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { tripDatesLabel, tripPhase, tripStatusLabel } from '@/utils/trip';

/**
 * Accueil : mes déplacements (publiés, où je suis participant) à venir, en cours ou terminés depuis peu. Rien n'est
 * affiché sans déplacement. Un appui ouvre la vue dédiée : infos pratiques, événements, articles réservés, participants.
 */
export function MyTripsCard() {
  const router = useRouter();
  const [items, setItems] = useState<TripSummary[]>([]);

  const load = useCallback(() => {
    trips.mine()
      .then((r) => setItems(r.data))
      .catch(() => { /* pas de déplacement affiché si on ne peut pas vérifier */ });
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));
  useRefreshOnResume(load);

  if (items.length === 0) return null;

  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>✈️ {items.length > 1 ? 'Mes déplacements' : 'Mon déplacement'}</Text>
      {items.map((t) => {
        const phase = tripPhase(t);
        return (
          <Pressable
            key={t.id}
            onPress={() => router.push(('/trip/' + t.id) as never)}
            accessibilityRole="button"
            accessibilityLabel={`Déplacement ${t.title}`}
            style={({ pressed }) => [styles.card, phase === 'past' && styles.cardPast, pressed && { opacity: 0.75 }]}
          >
            <View style={[styles.iconWrap, phase === 'past' && styles.iconWrapPast]}>
              {t.icon ? <Text style={styles.emoji}>{t.icon}</Text> : <Ionicons name="airplane" size={22} color="#fff" />}
            </View>
            <View style={{ flex: 1, gap: 2 }}>
              <Text style={styles.title} numberOfLines={2}>{t.title}</Text>
              <Text style={styles.sub} numberOfLines={2}>
                {tripDatesLabel(t)}{t.location ? ` · ${t.location}` : ''}
              </Text>
              <Text style={[styles.status, phase === 'past' && styles.statusPast]}>{tripStatusLabel(t)}</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  section: { gap: SPACING.sm, marginTop: SPACING.md, paddingHorizontal: SPACING.md },
  sectionTitle: { fontSize: 13, fontWeight: '800', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderLeftWidth: 4, borderLeftColor: COLORS.secondary,
    borderWidth: 1, borderColor: COLORS.border,
  },
  cardPast: { borderLeftColor: COLORS.border },
  iconWrap: { width: 42, height: 42, borderRadius: 21, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center' },
  iconWrapPast: { backgroundColor: COLORS.textMuted },
  emoji: { fontSize: 22 },
  title: { fontSize: 16, fontWeight: '800', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted },
  status: { fontSize: 12, color: COLORS.secondaryDark, fontWeight: '700' },
  statusPast: { color: COLORS.textMuted },
});
