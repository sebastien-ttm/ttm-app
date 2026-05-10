import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { auth } from '@/api/client';
import { COLORS } from '@/config';

type State = 'sending' | 'sent' | 'error';

export default function MagicLinkRequest() {
  const params = useLocalSearchParams<{ email?: string }>();
  const router = useRouter();
  const email = (params.email ?? '').toString();
  const [state, setState] = useState<State>('sending');
  const [error, setError] = useState<string | null>(null);
  const sentRef = useRef(false);

  useEffect(() => {
    if (sentRef.current || !email) return;
    sentRef.current = true;
    (async () => {
      try {
        await auth.requestMagicLink(email);
        setState('sent');
      } catch (err) {
        setState('error');
        setError(err instanceof Error ? err.message : 'Erreur inconnue');
      }
    })();
  }, [email]);

  return (
    <SafeAreaView style={styles.container}>
      <View style={styles.card}>
        {state === 'sending' && (
          <>
            <ActivityIndicator size="large" color={COLORS.primary} />
            <Text style={styles.title}>Envoi en cours…</Text>
          </>
        )}

        {state === 'sent' && (
          <>
            <Text style={styles.icon}>📬</Text>
            <Text style={styles.title}>Vérifiez vos e-mails</Text>
            <Text style={styles.body}>
              Si <Text style={styles.bold}>{email}</Text> correspond à un compte adhérent actif, vous allez
              recevoir un lien de connexion.
            </Text>
            <Text style={styles.hint}>Le lien est valable 15 minutes et utilisable une seule fois.</Text>
          </>
        )}

        {state === 'error' && (
          <>
            <Text style={styles.icon}>⚠️</Text>
            <Text style={styles.title}>Échec de l'envoi</Text>
            <Text style={styles.body}>{error}</Text>
          </>
        )}

        <Pressable style={styles.backButton} onPress={() => router.back()}>
          <Text style={styles.backLabel}>← Retour</Text>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.black, justifyContent: 'center', padding: 24 },
  card: { backgroundColor: '#fff', borderRadius: 12, padding: 32, alignItems: 'center' },
  icon: { fontSize: 48, marginBottom: 12 },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text, marginBottom: 12, textAlign: 'center' },
  body: { fontSize: 15, color: COLORS.text, textAlign: 'center', lineHeight: 22 },
  bold: { fontWeight: '700' },
  hint: { fontSize: 13, color: COLORS.textMuted, marginTop: 12, textAlign: 'center' },
  backButton: { marginTop: 24, padding: 12 },
  backLabel: { color: COLORS.primary, fontWeight: '600', fontSize: 15 },
});
