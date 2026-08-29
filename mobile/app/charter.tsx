import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { charter as charterApi } from '@/api/resources';
import type { Charter, CharterField } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { filterCharterFields } from '@/components/CharterForm';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatDate } from '@/utils/html';

/**
 * Vue en lecture seule de la charte du club. Accessible depuis « Vie du
 * club » à tout moment — permet à l'adhérent de relire ses engagements
 * hors du flux d'acceptation.
 */
export default function CharterReadScreen() {
  const { user } = useAuth();
  const [charter, setCharter] = useState<Charter | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await charterApi.current();
      setCharter(resp.charter);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load();
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [load]);

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Charte du club' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }
  if (error) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Charte du club' }} />
        <ErrorState message={error} onRetry={load} />
      </SafeAreaView>
    );
  }
  if (!charter) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Charte du club' }} />
        <EmptyState
          icon="📜"
          title="Pas de charte publiée"
          message="Aucune charte n'est active pour le moment. Revenez plus tard."
        />
      </SafeAreaView>
    );
  }

  // Filtre par profil (Parent/Jeune / Autre / Tous) ET restreint aux
  // cases à cocher (les engagements formels de la charte).
  const commitmentFields = filterCharterFields(charter.fields ?? [], user)
    .filter((f) => f.type === 'checkbox');

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Charte du club' }} />
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title}>{charter.title}</Text>
        <Text style={styles.meta}>
          Version {charter.version} · publiée le {formatDate(charter.publishedAt)}
        </Text>

        {charter.content ? (
          <View style={styles.section}>
            <RichContent html={charter.content} style={styles.body} />
          </View>
        ) : null}

        {commitmentFields.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Engagements</Text>
            {commitmentFields.map((f) => (
              <CommitmentCard key={f.id} field={f} />
            ))}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function CommitmentCard({ field }: { field: CharterField }) {
  return (
    <View style={styles.commitCard}>
      {field.description ? <Text style={styles.commitDescription}>{field.description}</Text> : null}
      <View style={styles.commitAcceptRow}>
        <Text style={styles.commitCheck}>✓</Text>
        <Text style={styles.commitLabel}>{field.label}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, paddingBottom: SPACING.xxl },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 12, color: COLORS.textMuted, marginTop: 4, marginBottom: SPACING.lg },
  section: { marginTop: SPACING.lg },
  sectionTitle: {
    fontSize: 13, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm,
  },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 22 },
  commitCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    padding: SPACING.md,
    marginBottom: SPACING.sm,
    gap: SPACING.sm,
  },
  commitDescription: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  commitAcceptRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 10,
    paddingTop: 6,
    borderTopWidth: 1,
    borderTopColor: COLORS.border,
  },
  commitCheck: {
    color: COLORS.success, fontSize: 18, fontWeight: '700', lineHeight: 20,
  },
  commitLabel: { flex: 1, fontSize: 14, fontWeight: '600', color: COLORS.text, lineHeight: 20 },
});
