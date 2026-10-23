import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { SlotGroup, SlotGroups, TrainingSlot } from '@/api/types';
import { MemberAvatar } from '@/components/MemberAvatar';
import { PaceBadge } from '@/components/groups/GroupFields';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/** Sports pour lesquels des groupes sont proposés (valeurs de SportKey). */
const GROUP_SPORTS = ['velo', 'course'];

function isSlotGroups(body: unknown): body is SlotGroups {
  return typeof body === 'object' && body !== null && Array.isArray((body as SlotGroups).groups);
}

/**
 * Détail d'un créneau de vélo ou de course à pied : les groupes de la séance, à choisir avant son début. Chaque groupe
 * indique son allure (1 à 5), sa vitesse, ses encadrants et ses places ; on n'en choisit qu'un par séance (en choisir
 * un autre libère le premier). Rien n'est affiché s'il n'y a pas de groupe pour cette séance.
 */
export function SlotGroupsCard({ slot }: { slot: TrainingSlot }) {
  const router = useRouter();
  const [data, setData] = useState<SlotGroups | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const eligible = GROUP_SPORTS.includes(slot.sport) && !slot.isCancelled;
  const slotKey = `${slot.date}|${slot.id ?? ''}|${slot.templateId ?? ''}`;

  // À l'ouverture et au retour sur le créneau (après avoir consulté un groupe) : relecture, pour des places à jour.
  useFocusEffect(useCallback(() => {
    if (!eligible) return;
    let cancelled = false;
    trainingGroups.forSlot(slot)
      .then((res) => { if (!cancelled) setData(res); })
      .catch(() => { /* sans les groupes, le créneau s'affiche normalement */ });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eligible, slotKey]));

  async function act(group: SlotGroup, join: boolean) {
    if (busyId !== null) return;
    setBusyId(group.id);
    setError(null);
    try {
      setData(join ? await trainingGroups.join(group.id) : await trainingGroups.leaveAsMember(group.id));
    } catch (e) {
      if (e instanceof ApiError) {
        setError(e.message);
        // Le refus (complet, séance commencée) porte l'état à jour : l'écran se resynchronise.
        if (isSlotGroups(e.body)) setData(e.body);
      } else {
        setError('Action impossible pour le moment. Réessayez.');
      }
    } finally {
      setBusyId(null);
    }
  }

  if (!eligible || data === null || data.groups.length === 0) return null;

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>Groupes ({data.groups.length})</Text>
      <Text style={styles.intro}>
        {data.closed
          ? 'La séance a commencé : le choix du groupe est clos.'
          : data.canChoose
            ? 'Choisissez votre groupe avant la séance : un seul groupe par séance, dans la limite des places.'
            : 'Les groupes de cette séance.'}
      </Text>

      {data.groups.map((g) => {
        const other = data.myGroupId !== null && data.myGroupId !== g.id;
        const busy = busyId === g.id;
        return (
          <View key={g.id} style={[styles.group, g.isMember && styles.groupMine]}>
            <View style={styles.groupHead}>
              <Text style={styles.groupName} numberOfLines={2}>{g.name}</Text>
              {g.isMember && (
                <View style={styles.mineChip}>
                  <Ionicons name="checkmark" size={12} color="#fff" />
                  <Text style={styles.mineLabel}>Votre groupe</Text>
                </View>
              )}
            </View>
            <PaceBadge level={g.paceLevel} speed={g.speed} />
            {g.coaches.length > 0 && (
              <View style={styles.coaches}>
                {g.coaches.map((c) => {
                  const [prenom = '', ...rest] = c.fullName.split(' ');
                  return (
                    <View key={c.id} style={styles.coach}>
                      <MemberAvatar prenom={prenom} nom={rest.join(' ')} avatarUrl={c.avatarUrl} size={24} />
                      <Text style={styles.coachName} numberOfLines={1}>{c.fullName}</Text>
                    </View>
                  );
                })}
              </View>
            )}
            {g.info ? <Text style={styles.info} numberOfLines={3}>{g.info}</Text> : null}
            <Text style={[styles.places, g.full && !g.isMember && styles.placesFull]}>
              {g.memberCount}{g.capacity !== null ? `/${g.capacity}` : ''} adhérent{g.memberCount > 1 ? 's' : ''}
              {g.placesLeft !== null ? ` · ${g.placesLeft === 0 ? 'complet' : `${g.placesLeft} place${g.placesLeft > 1 ? 's' : ''} libre${g.placesLeft > 1 ? 's' : ''}`}` : ''}
            </Text>

            <View style={styles.actions}>
              {(g.isMember || g.isCoach) && (
                <Pressable
                  onPress={() => router.push(('/training-group/' + g.id) as never)}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.btn, styles.btnGhost, pressed && { opacity: 0.8 }]}
                >
                  <Text style={styles.btnGhostLabel}>Ouvrir le groupe</Text>
                </Pressable>
              )}
              {g.isMember && !data.closed && (
                <Pressable
                  onPress={() => void act(g, false)}
                  disabled={busy}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.btn, styles.btnDanger, (pressed || busy) && { opacity: 0.8 }]}
                >
                  {busy ? <ActivityIndicator size="small" color={COLORS.error} /> : <Text style={styles.btnDangerLabel}>Quitter</Text>}
                </Pressable>
              )}
              {!g.isMember && !g.isCoach && data.canChoose && !data.closed && (
                <Pressable
                  onPress={() => void act(g, true)}
                  disabled={busy || g.full}
                  accessibilityRole="button"
                  accessibilityLabel={other ? `Changer pour le groupe ${g.name}` : `Choisir le groupe ${g.name}`}
                  style={({ pressed }) => [styles.btn, styles.btnPrimary, (g.full || busy) && { opacity: 0.45 }, pressed && { opacity: 0.8 }]}
                >
                  {busy ? <ActivityIndicator size="small" color="#fff" /> : (
                    <Text style={styles.btnPrimaryLabel}>{g.full ? 'Complet' : other ? 'Changer pour ce groupe' : 'Choisir ce groupe'}</Text>
                  )}
                </Pressable>
              )}
            </View>
          </View>
        );
      })}

      {error && <Text style={styles.error}>{error}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, marginBottom: SPACING.md,
    gap: SPACING.sm, ...SHADOWS.sm,
  },
  cardTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  intro: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  group: {
    gap: 6, padding: SPACING.md, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surfaceAlt,
  },
  groupMine: { borderColor: COLORS.primary, backgroundColor: COLORS.primarySoft },
  groupHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  groupName: { flex: 1, fontSize: 16, fontWeight: '800', color: COLORS.text },
  mineChip: { flexDirection: 'row', alignItems: 'center', gap: 3, paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.success },
  mineLabel: { fontSize: 11, fontWeight: '800', color: '#fff' },
  coaches: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  coach: { flexDirection: 'row', alignItems: 'center', gap: 6, maxWidth: '100%' },
  coachName: { fontSize: 13, color: COLORS.text, flexShrink: 1 },
  info: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  places: { fontSize: 13, fontWeight: '600', color: COLORS.secondaryDark },
  placesFull: { color: COLORS.error },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 2 },
  btn: { paddingHorizontal: 14, paddingVertical: 10, borderRadius: RADIUS.md, alignItems: 'center', justifyContent: 'center', minHeight: 40 },
  btnPrimary: { backgroundColor: COLORS.primary },
  btnPrimaryLabel: { color: '#fff', fontWeight: '800', fontSize: 14 },
  btnGhost: { borderWidth: 1.5, borderColor: COLORS.primary, backgroundColor: COLORS.surface },
  btnGhostLabel: { color: COLORS.primary, fontWeight: '700', fontSize: 14 },
  btnDanger: { borderWidth: 1.5, borderColor: COLORS.error, backgroundColor: COLORS.surface },
  btnDangerLabel: { color: COLORS.error, fontWeight: '700', fontSize: 14 },
  error: { color: COLORS.error, backgroundColor: '#fee2e2', padding: 10, borderRadius: RADIUS.sm, fontSize: 13, fontWeight: '500' },
});
