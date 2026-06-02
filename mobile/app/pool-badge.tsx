import { Image } from 'expo-image';
import { Stack, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { poolBadge as api } from '@/api/resources';
import type { PoolBadge } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Affiche le QR code piscines en plein écran (fond blanc pour faciliter
 * la lecture par la borne). Conseille à l'utilisateur d'augmenter la
 * luminosité de son écran.
 */
export default function PoolBadgeScreen() {
  const router = useRouter();
  const [data, setData] = useState<PoolBadge | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await api.current();
      setData(resp.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <View style={styles.root}>
      <Stack.Screen
        options={{
          title: 'Accès piscines',
          headerStyle: { backgroundColor: COLORS.primary },
          headerTintColor: '#fff',
          headerTitleStyle: { fontWeight: '700', color: '#fff' },
        }}
      />
      <SafeAreaView style={{ flex: 1 }} edges={['bottom']}>
        {loading ? (
          <FullScreenLoading />
        ) : error ? (
          <ErrorState message={error} onRetry={load} />
        ) : !data ? (
          <View style={styles.empty}>
            <EmptyState
              icon="🔒"
              title="Aucun badge configuré"
              message="L'administrateur du club n'a pas encore mis en ligne le badge piscines de la saison."
            />
            <Pressable onPress={() => router.back()} style={styles.backBtn}>
              <Text style={styles.backLabel}>← Retour</Text>
            </Pressable>
          </View>
        ) : (
          <ScrollView contentContainerStyle={styles.content}>
            {data.title && <Text style={styles.title}>{data.title}</Text>}
            <View style={styles.qrFrame}>
              <Image
                source={{ uri: data.imageUrl }}
                style={styles.qrImage}
                contentFit="contain"
                accessibilityLabel="QR code piscines"
              />
            </View>
            {data.notes ? (
              <Text style={styles.notes}>{data.notes}</Text>
            ) : (
              <Text style={styles.hint}>
                💡 Augmente la luminosité de ton écran pour faciliter la lecture.
              </Text>
            )}
          </ScrollView>
        )}
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#fff' },
  content: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: SPACING.lg,
  },
  title: {
    fontSize: 18,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: SPACING.lg,
    textAlign: 'center',
  },
  qrFrame: {
    backgroundColor: '#fff',
    padding: SPACING.lg,
    borderRadius: RADIUS.lg,
    borderWidth: 1,
    borderColor: COLORS.border,
    // taille volontairement grande pour faciliter le scan
    aspectRatio: 1,
    width: '100%',
    maxWidth: 380,
  },
  qrImage: { width: '100%', height: '100%' },
  notes: {
    fontSize: 14,
    color: COLORS.text,
    marginTop: SPACING.lg,
    textAlign: 'center',
    paddingHorizontal: SPACING.lg,
    lineHeight: 20,
  },
  hint: {
    fontSize: 13,
    color: COLORS.textMuted,
    marginTop: SPACING.lg,
    textAlign: 'center',
    paddingHorizontal: SPACING.lg,
  },
  empty: { flex: 1, justifyContent: 'center' },
  backBtn: { alignSelf: 'center', paddingVertical: SPACING.lg },
  backLabel: { color: COLORS.secondary, fontSize: 14, fontWeight: '600' },
});
