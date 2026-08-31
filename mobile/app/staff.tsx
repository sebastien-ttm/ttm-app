import { Image } from 'expo-image';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staff as api } from '@/api/resources';
import type { CommitteeMember, StaffResponse } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Trombinoscope Staff sportif : Entraîneurs + Encadrants. Structure
 * identique à /committee (mêmes cartes, mêmes styles) mais deux
 * sections uniquement. Un adhérent avec les deux profils apparait
 * dans les deux (chaque section « raconte » un rôle distinct).
 */
export default function StaffScreen() {
  const [data, setData] = useState<StaffResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await api.get();
      setData(resp);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    (async () => {
      await load();
      setLoading(false);
    })();
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  return (
    <View style={styles.root}>
      <Stack.Screen
        options={{
          title: 'Staff sportif',
          headerStyle: { backgroundColor: COLORS.brandNavy },
          headerTintColor: '#fff',
          headerTitleStyle: { fontWeight: '700', color: '#fff' },
        }}
      />
      <SafeAreaView style={{ flex: 1 }} edges={['bottom']}>
        {loading ? (
          <FullScreenLoading />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : data && (data.coaches.length + data.encadrants.length) === 0 ? (
          <EmptyState
            icon="🧑‍🏫"
            title="Staff à venir"
            message="Aucun entraîneur ni encadrant n'est actuellement renseigné."
          />
        ) : (
          <ScrollView
            contentContainerStyle={styles.content}
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
          >
            {data && data.coaches.length > 0 && (
              <Section title="🧑‍🏫 Entraîneurs">
                {data.coaches.map((m) => (
                  <MemberCard key={`c-${m.id}`} member={m} />
                ))}
              </Section>
            )}
            {data && data.encadrants.length > 0 && (
              <Section title="🤝 Encadrants">
                {data.encadrants.map((m) => (
                  <MemberCard key={`e-${m.id}`} member={m} />
                ))}
              </Section>
            )}
          </ScrollView>
        )}
      </SafeAreaView>
    </View>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      <View style={styles.grid}>{children}</View>
    </View>
  );
}

function MemberCard({ member }: { member: CommitteeMember }) {
  const initials = (member.prenom?.[0] ?? '?') + (member.nom?.[0] ?? '');
  return (
    <View style={styles.card}>
      {member.avatarUrl ? (
        <Image source={{ uri: member.avatarUrl }} style={styles.avatar} contentFit="cover" />
      ) : (
        <View style={[styles.avatar, styles.avatarPlaceholder]}>
          <Text style={styles.avatarInitials}>{initials.toUpperCase()}</Text>
        </View>
      )}
      <Text style={styles.name} numberOfLines={2}>
        {member.fullName}
      </Text>
      {member.clubFunction ? (
        <Text style={styles.func} numberOfLines={3}>
          {member.clubFunction}
        </Text>
      ) : null}
    </View>
  );
}

const CARD_MIN_WIDTH = 140;

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
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: SPACING.sm,
  },
  card: {
    minWidth: CARD_MIN_WIDTH,
    flexGrow: 1,
    flexBasis: CARD_MIN_WIDTH,
    maxWidth: 220,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    alignItems: 'center',
    ...SHADOWS.sm,
  },
  avatar: {
    width: 84,
    height: 84,
    borderRadius: 42,
    marginBottom: SPACING.sm,
    backgroundColor: COLORS.border,
  },
  avatarPlaceholder: { alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.brandNavy },
  avatarInitials: { color: '#fff', fontSize: 24, fontWeight: '700' },
  name: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.text,
    textAlign: 'center',
  },
  func: {
    fontSize: 12,
    color: COLORS.textMuted,
    marginTop: 4,
    textAlign: 'center',
    fontStyle: 'italic',
  },
});
