import { Image } from 'expo-image';
import { Stack, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { poolBadge as api } from '@/api/resources';
import type { PoolBadge } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Affiche le QR code piscines en plein écran (fond blanc pour faciliter
 * la lecture par la borne). Gère 2 formats :
 *  - Image (PNG/JPG/WebP/GIF) : rendue inline avec expo-image.
 *  - PDF : rendu inline via <iframe> sur le web (le navigateur a un
 *    viewer natif), bouton « Ouvrir le PDF » sur native.
 */
export default function PoolBadgeScreen() {
  const router = useRouter();
  const [data, setData] = useState<PoolBadge | null>(null);
  const [loading, setLoading] = useState(true);
  const [opening, setOpening] = useState(false);
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

  async function openPdfExternal() {
    if (!data) return;
    setOpening(true);
    try {
      await WebBrowser.openBrowserAsync(data.imageUrl);
    } finally {
      setOpening(false);
    }
  }

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

            {data.isPdf ? (
              <PdfRenderer url={data.imageUrl} onOpenExternal={openPdfExternal} opening={opening} />
            ) : (
              <View style={styles.qrFrame}>
                <Image
                  source={{ uri: data.imageUrl }}
                  style={styles.qrImage}
                  contentFit="contain"
                  accessibilityLabel="QR code piscines"
                />
              </View>
            )}

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

/**
 * Renderer PDF. Sur web : iframe (viewer natif du navigateur).
 * Sur natif : bouton qui ouvre dans le navigateur in-app.
 */
function PdfRenderer({
  url,
  onOpenExternal,
  opening,
}: {
  url: string;
  onOpenExternal: () => void;
  opening: boolean;
}) {
  if (Platform.OS === 'web') {
    return (
      <View style={styles.pdfFrame}>
        <iframe
          src={url}
          style={{
            width: '100%',
            height: '100%',
            border: 'none',
            borderRadius: RADIUS.lg,
          }}
          title="Badge piscines"
        />
      </View>
    );
  }
  // Natif
  return (
    <View style={styles.pdfNativeBox}>
      <Text style={styles.pdfIcon}>📄</Text>
      <Text style={styles.pdfMsg}>
        Le badge est un PDF. Ouvre-le pour le présenter à l'entrée.
      </Text>
      <Pressable
        onPress={onOpenExternal}
        disabled={opening}
        style={({ pressed }) => [
          styles.openPdfBtn,
          opening && { opacity: 0.6 },
          pressed && { backgroundColor: COLORS.primaryDark },
        ]}
      >
        {opening ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.openPdfLabel}>📄 Ouvrir le PDF</Text>
        )}
      </Pressable>
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
    aspectRatio: 1,
    width: '100%',
    maxWidth: 380,
  },
  qrImage: { width: '100%', height: '100%' },
  pdfFrame: {
    width: '100%',
    maxWidth: 600,
    aspectRatio: 1 / 1.414, // A4 portrait
    borderRadius: RADIUS.lg,
    borderWidth: 1,
    borderColor: COLORS.border,
    overflow: 'hidden',
    backgroundColor: '#fff',
  },
  pdfNativeBox: {
    width: '100%',
    maxWidth: 380,
    padding: SPACING.xl,
    borderRadius: RADIUS.lg,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
    alignItems: 'center',
    ...SHADOWS.sm,
  },
  pdfIcon: { fontSize: 48, marginBottom: SPACING.md },
  pdfMsg: { fontSize: 14, color: COLORS.text, textAlign: 'center', marginBottom: SPACING.lg },
  openPdfBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 12,
    paddingHorizontal: 24,
    alignItems: 'center',
    width: '100%',
  },
  openPdfLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
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
