import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { notices as noticesApi } from '@/api/resources';
import type { AdminNotice } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Écran modal d'affichage d'un message ponctuel admin avec bouton
 * d'acquittement (« J'ai compris »). Bloquant : pas de close en swipe
 * ni de retour arrière — la validation est requise.
 *
 * Après acquittement, on re-poll /pending : s'il reste des notices
 * en attente, on route vers la suivante (chaîne FIFO). Sinon retour à
 * l'appli.
 */
export default function NoticeScreen() {
  const router = useRouter();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [notice, setNotice] = useState<AdminNotice | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!id) { setError('Identifiant invalide.'); setLoading(false); return; }
    try {
      setError(null);
      // Pas de GET single — on filtre depuis /pending.
      const resp = await noticesApi.pending();
      const found = resp.data.find((n) => n.id === id) ?? null;
      if (!found) {
        // Déjà acquitté par une autre session ou expiré depuis :
        // on remonte à la liste des pending pour enchaîner (ou sortir).
        await goNextOrExit();
        return;
      }
      setNotice(found);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  async function goNextOrExit() {
    try {
      const resp = await noticesApi.pending();
      if (resp.data.length > 0) {
        router.replace(('/notice/' + resp.data[0].id) as never);
      } else {
        router.replace('/(tabs)' as never);
      }
    } catch {
      router.replace('/(tabs)' as never);
    }
  }

  async function acknowledge() {
    if (!notice || busy) return;
    setBusy(true);
    try {
      await noticesApi.acknowledge(notice.id);
      await goNextOrExit();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Impossible d\'enregistrer votre acquittement.');
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Information' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !notice) {
    return (
      <>
        <Stack.Screen options={{ title: 'Information' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['top', 'bottom']}>
      <Stack.Screen options={{ title: 'Information' }} />
      <View style={styles.hero}>
        <Ionicons name="megaphone" size={28} color="#fff" />
        <Text style={styles.heroLabel}>Message du club</Text>
      </View>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title}>{notice.title}</Text>
        <RichContent html={notice.content} style={styles.body} />
      </ScrollView>
      <View style={styles.footer}>
        <Pressable
          onPress={acknowledge}
          disabled={busy}
          style={({ pressed }) => [styles.ackBtn, pressed && { opacity: 0.85 }, busy && { opacity: 0.6 }]}
        >
          {busy ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <>
              <Ionicons name="checkmark-circle" size={20} color="#fff" />
              <Text style={styles.ackBtnLabel}>{notice.acknowledgeLabel}</Text>
            </>
          )}
        </Pressable>
        <Text style={styles.footerHint}>
          Ce message ne s'affichera plus une fois acquitté.
        </Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  hero: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: COLORS.brandNavy,
    paddingHorizontal: SPACING.md,
    paddingVertical: 14,
  },
  heroLabel: { color: '#fff', fontWeight: '700', fontSize: 14, letterSpacing: 0.5, textTransform: 'uppercase' },
  content: { padding: SPACING.lg, paddingBottom: SPACING.xxl, maxWidth: 640, width: '100%', alignSelf: 'center' },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.md, lineHeight: 28 },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 22 },
  footer: {
    borderTopWidth: 1,
    borderTopColor: COLORS.border,
    padding: SPACING.md,
    backgroundColor: COLORS.surface,
    gap: 6,
  },
  ackBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 14,
  },
  ackBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 16 },
  footerHint: {
    color: COLORS.textMuted, fontSize: 11, textAlign: 'center', fontStyle: 'italic',
  },
});
