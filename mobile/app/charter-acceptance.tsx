import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  NativeScrollEvent,
  NativeSyntheticEvent,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthContext';
import { RichContent } from '@/components/RichContent';
import { APP_NAME, COLORS } from '@/config';

const SCROLL_THRESHOLD_PX = 12;

export default function CharterAcceptanceScreen() {
  const { pendingCharter, acknowledgeCharter, signOut } = useAuth();
  const [hasReadAll, setHasReadAll] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function onScroll(e: NativeSyntheticEvent<NativeScrollEvent>) {
    const { layoutMeasurement, contentOffset, contentSize } = e.nativeEvent;
    const reachedBottom =
      layoutMeasurement.height + contentOffset.y >= contentSize.height - SCROLL_THRESHOLD_PX;
    if (reachedBottom && !hasReadAll) {
      setHasReadAll(true);
    }
  }

  function onContentSizeChange(_w: number, h: number) {
    // If the entire content fits without scrolling, accept immediately.
    // We can't know the layout height here, so we use a heuristic: if the
    // text is short, the scrollEvent may never fire onScrollEndDrag.
    // Best guess: web window height.
    if (Platform.OS === 'web' && typeof window !== 'undefined' && h <= window.innerHeight) {
      setHasReadAll(true);
    }
  }

  async function onAccept() {
    if (!hasReadAll || busy) return;
    setBusy(true);
    setError(null);
    try {
      await acknowledgeCharter();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors de l\'acceptation');
    } finally {
      setBusy(false);
    }
  }

  async function onDecline() {
    const doSignOut = async () => {
      try {
        await signOut();
      } catch {
        /* ignore */
      }
    };
    if (Platform.OS === 'web') {
      // Alert.alert on web is unreliable, use confirm()
      const ok = typeof window !== 'undefined' && window.confirm(
        'Refuser la charte vous déconnecte de l\'application. Voulez-vous continuer ?',
      );
      if (ok) await doSignOut();
      return;
    }
    Alert.alert(
      'Refuser la charte ?',
      'Vous serez déconnecté et ne pourrez plus utiliser l\'application tant que vous n\'aurez pas accepté.',
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Refuser et me déconnecter', style: 'destructive', onPress: doSignOut },
      ],
    );
  }

  if (!pendingCharter) {
    return (
      <SafeAreaView style={styles.container}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.brand}>{APP_NAME}</Text>
        <Text style={styles.title}>{pendingCharter.title}</Text>
        <Text style={styles.version}>Version {pendingCharter.version}</Text>
      </View>

      <ScrollView
        style={styles.scroll}
        contentContainerStyle={styles.scrollContent}
        onScroll={onScroll}
        onContentSizeChange={onContentSizeChange}
        scrollEventThrottle={16}
      >
        <RichContent html={pendingCharter.content} />
      </ScrollView>

      {error && <Text style={styles.errorBanner}>{error}</Text>}

      <View style={styles.footer}>
        <Text style={[styles.hint, hasReadAll && styles.hintDone]}>
          {hasReadAll
            ? '✓ Vous avez lu la charte intégralement.'
            : 'Faites défiler le texte jusqu\'en bas pour activer le bouton.'}
        </Text>

        <View style={styles.actions}>
          <Pressable style={styles.declineBtn} onPress={onDecline} disabled={busy}>
            <Text style={styles.declineLabel}>Refuser et me déconnecter</Text>
          </Pressable>
          <Pressable
            style={[styles.acceptBtn, (!hasReadAll || busy) && styles.acceptBtnDisabled]}
            onPress={onAccept}
            disabled={!hasReadAll || busy}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.acceptLabel}>J'accepte la charte</Text>
            )}
          </Pressable>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  header: {
    backgroundColor: COLORS.primary,
    padding: 18,
  },
  brand: { color: 'rgba(255,255,255,0.85)', fontSize: 13, fontWeight: '600' },
  title: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 4 },
  version: { color: 'rgba(255,255,255,0.85)', fontSize: 13, marginTop: 2 },
  scroll: { flex: 1, backgroundColor: COLORS.surface },
  scrollContent: { padding: 18, paddingBottom: 36 },
  errorBanner: {
    backgroundColor: '#FEE',
    color: COLORS.error,
    padding: 12,
    fontSize: 13,
    textAlign: 'center',
  },
  footer: {
    padding: 16,
    backgroundColor: COLORS.surface,
    borderTopWidth: 1,
    borderTopColor: COLORS.border,
  },
  hint: { fontSize: 13, color: COLORS.textMuted, marginBottom: 10, textAlign: 'center' },
  hintDone: { color: COLORS.success, fontWeight: '600' },
  actions: { flexDirection: 'row', gap: 10, alignItems: 'stretch' },
  declineBtn: {
    flex: 1,
    paddingVertical: 12,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  declineLabel: { color: COLORS.textMuted, fontWeight: '600', fontSize: 13 },
  acceptBtn: {
    flex: 2,
    backgroundColor: COLORS.primary,
    paddingVertical: 12,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  acceptBtnDisabled: { backgroundColor: '#ccc' },
  acceptLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
});
