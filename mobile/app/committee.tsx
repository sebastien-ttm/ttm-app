import { Image } from 'expo-image';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { committee as api } from '@/api/resources';
import type { CommitteeMember, CommitteeResponse } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Trombinoscope Comité : Bureau + membres du CoDir + Entraîneurs.
 * Trois sections indépendantes — un entraîneur peut aussi être
 * trésorier, il apparaîtra dans les deux sections (voulu, chaque
 * section « raconte » un aspect différent du club).
 */
export default function CommitteeScreen() {
  const [data, setData] = useState<CommitteeResponse | null>(null);
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
          title: 'Comité Directeur',
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
        ) : data && (data.bureau.length + data.codir.length) === 0 ? (
          <EmptyState
            icon="👥"
            title="Comité Directeur à venir"
            message="Aucun rôle CoDir n'est actuellement renseigné."
          />
        ) : (
          <ScrollView
            contentContainerStyle={styles.content}
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
          >
            {data && data.bureau.length > 0 && (
              <Section title="🏛️ Bureau">
                {data.bureau.map((m) => (
                  <MemberCard key={m.id} member={m} showRole />
                ))}
              </Section>
            )}
            {data && data.codir.length > 0 && (
              <Section title="🗂️ Comité Directeur">
                {data.codir.map((m) => (
                  <MemberCard key={m.id} member={m} showRole />
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

function MemberCard({ member, showRole }: { member: CommitteeMember; showRole?: boolean }) {
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
      {showRole && member.boardRoleLabel ? (
        <Text style={styles.role}>{member.boardRoleLabel}</Text>
      ) : null}
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
    // ~2 cartes par ligne avec un gap ; flexGrow:0 empêche une carte
    // seule sur la dernière ligne de s'étaler jusqu'au maxWidth
    // (elle gardait sinon ~220px vs ~168 des cartes appairées).
    minWidth: CARD_MIN_WIDTH,
    flexGrow: 0,
    flexBasis: '48%',
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
  role: {
    fontSize: 12,
    fontWeight: '600',
    color: COLORS.brandNavy,
    marginTop: 4,
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
