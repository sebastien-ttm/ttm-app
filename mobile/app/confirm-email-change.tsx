import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, auth } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { COLORS, RADIUS, SPACING } from '@/config';

type Phase =
  | { kind: 'loading' }
  | { kind: 'invalid'; message: string }
  | { kind: 'ready'; currentEmail: string; newEmail: string }
  | { kind: 'done'; newEmail: string };

/**
 * Cible du lien reçu par e-mail (adresse ACTUELLE) pour confirmer un
 * changement d'adresse. Route publique (voir AuthGate) : le lien est
 * souvent ouvert sans session, voire sur un autre appareil. Le clic sur
 * le lien ne change rien tout seul — un bouton explicite déclenche la
 * confirmation, ce qui évite qu'un scanner de liens d'e-mail ne la
 * consomme à la place de l'utilisateur.
 */
export default function ConfirmEmailChangeScreen() {
  const router = useRouter();
  const { status, refreshMe } = useAuth();
  const { token: rawToken } = useLocalSearchParams<{ token?: string }>();
  const token = Array.isArray(rawToken) ? rawToken[0] : rawToken;

  const [phase, setPhase] = useState<Phase>({ kind: 'loading' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      setPhase({ kind: 'invalid', message: 'Lien incomplet. Ouvrez-le depuis l\'e-mail reçu.' });
      return;
    }
    let cancelled = false;
    (async () => {
      try {
        const resp = await auth.previewEmailChange(token);
        if (!cancelled) setPhase({ kind: 'ready', currentEmail: resp.currentEmail, newEmail: resp.newEmail });
      } catch (e) {
        if (!cancelled) {
          setPhase({
            kind: 'invalid',
            message: e instanceof ApiError ? e.message : 'Impossible de vérifier ce lien.',
          });
        }
      }
    })();
    return () => { cancelled = true; };
  }, [token]);

  async function confirm() {
    if (!token) return;
    setBusy(true);
    setError(null);
    try {
      const resp = await auth.confirmEmailChange(token);
      setPhase({ kind: 'done', newEmail: resp.newEmail });
      // Session ouverte sur cet appareil : rafraîchit l'adresse affichée.
      if (status === 'authenticated') {
        void refreshMe().catch(() => {});
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue');
    } finally {
      setBusy(false);
    }
  }

  function leave() {
    router.replace((status === 'authenticated' ? '/(tabs)/profile' : '/(auth)/login') as never);
  }

  return (
    <SafeAreaView style={styles.container}>
      <Stack.Screen options={{ title: 'Changement d\'adresse e-mail', headerShown: false }} />
      <ScrollView contentContainerStyle={styles.content}>
        <View style={styles.card}>
          {phase.kind === 'loading' && <ActivityIndicator color={COLORS.primary} />}

          {phase.kind === 'invalid' && (
            <>
              <Text style={styles.title}>Lien invalide</Text>
              <Text style={styles.text}>{phase.message}</Text>
              <Pressable style={styles.button} onPress={leave}>
                <Text style={styles.buttonLabel}>Retour à l'application</Text>
              </Pressable>
            </>
          )}

          {phase.kind === 'ready' && (
            <>
              <Text style={styles.title}>Confirmer le changement d'adresse e-mail</Text>
              <View style={styles.summary}>
                <Text style={styles.summaryLine}>Adresse actuelle : <Text style={styles.bold}>{phase.currentEmail}</Text></Text>
                <Text style={styles.summaryLine}>Nouvelle adresse : <Text style={styles.bold}>{phase.newEmail}</Text></Text>
              </View>
              <Text style={styles.text}>
                Une fois confirmé, vous vous connecterez avec la nouvelle adresse et tous les e-mails
                du club y seront envoyés.
              </Text>
              {error && <Text style={styles.error}>{error}</Text>}
              <Pressable
                style={[styles.button, busy && styles.buttonDisabled]}
                onPress={confirm}
                disabled={busy}
              >
                {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Confirmer le changement</Text>}
              </Pressable>
              <Pressable style={styles.cancel} onPress={leave} disabled={busy}>
                <Text style={styles.cancelLabel}>Ce n'est pas moi / Annuler</Text>
              </Pressable>
            </>
          )}

          {phase.kind === 'done' && (
            <>
              <Text style={[styles.title, { color: '#166534' }]}>Adresse e-mail modifiée ✓</Text>
              <Text style={styles.text}>
                Votre adresse est désormais <Text style={styles.bold}>{phase.newEmail}</Text>.
                {status === 'authenticated'
                  ? ''
                  : ' Connectez-vous avec cette nouvelle adresse.'}
              </Text>
              <Pressable style={styles.button} onPress={leave}>
                <Text style={styles.buttonLabel}>
                  {status === 'authenticated' ? 'Retour à mon profil' : 'Me connecter'}
                </Text>
              </Pressable>
            </>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.brandNavy },
  content: { flexGrow: 1, justifyContent: 'center', padding: SPACING.xl, maxWidth: 480, width: '100%', alignSelf: 'center' },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.xl,
    padding: SPACING.xl,
  },
  title: { fontSize: 18, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.md },
  text: { fontSize: 14, color: COLORS.textMuted, lineHeight: 21, marginBottom: SPACING.md },
  summary: {
    backgroundColor: COLORS.background,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    gap: 6,
  },
  summaryLine: { fontSize: 14, color: COLORS.text },
  bold: { fontWeight: '700' },
  error: {
    color: COLORS.error,
    backgroundColor: COLORS.primarySoft,
    padding: 12,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
    fontSize: 13,
    fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.xs,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  cancel: { alignItems: 'center', paddingVertical: 14 },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
