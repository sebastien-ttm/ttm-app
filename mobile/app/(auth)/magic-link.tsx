import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';

/**
 * Handles magic link callback :
 *  - Native : opened via deep link `ttm://auth/magic-link?token=xxx`
 *  - Web    : opened via URL `http://localhost:8081/auth/magic-link?token=xxx`
 *
 * Either way, expo-router parses the `?token=...` into `useLocalSearchParams()`.
 */
export default function MagicLinkVerify() {
  const params = useLocalSearchParams<{ token?: string }>();
  const router = useRouter();
  const { consumeMagicLink } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const consumedRef = useRef(false);

  useEffect(() => {
    const token = params.token?.toString();
    if (!token || consumedRef.current) return;
    consumedRef.current = true;
    (async () => {
      try {
        await consumeMagicLink(token);
        // _layout will redirect to /(tabs)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Lien invalide');
      }
    })();
  }, [params.token, consumeMagicLink]);

  if (error) {
    return (
      <SafeAreaView style={styles.container}>
        <View style={styles.card}>
          <Text style={styles.icon}>⚠️</Text>
          <Text style={styles.title}>Lien invalide ou expiré</Text>
          <Text style={styles.body}>{error}</Text>
          <Pressable style={styles.button} onPress={() => router.replace('/(auth)/login')}>
            <Text style={styles.buttonLabel}>Demander un nouveau lien</Text>
          </Pressable>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <View style={styles.card}>
        <ActivityIndicator size="large" color={COLORS.primary} />
        <Text style={styles.title}>Connexion en cours…</Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.black, justifyContent: 'center', padding: 24 },
  card: { backgroundColor: '#fff', borderRadius: 12, padding: 32, alignItems: 'center' },
  icon: { fontSize: 48, marginBottom: 12 },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text, marginVertical: 12, textAlign: 'center' },
  body: { fontSize: 15, color: COLORS.text, textAlign: 'center', lineHeight: 22 },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: 8,
    paddingHorizontal: 20,
    paddingVertical: 12,
    marginTop: 20,
  },
  buttonLabel: { color: '#fff', fontWeight: '700' },
});
