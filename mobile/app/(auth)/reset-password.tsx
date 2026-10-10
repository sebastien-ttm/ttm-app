import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, auth } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { COLORS, RADIUS, SPACING } from '@/config';

const MIN_LENGTH = 8;

type Phase = 'checking' | 'invalid' | 'form';

/**
 * Choix d'un nouveau mot de passe : écran ouvert par le lien reçu par e-mail
 * (/reset-password?token=…). Le lien est vérifié d'abord pour ne pas faire saisir un mot de
 * passe pour rien ; une fois enregistré, l'adhérent est connecté et envoyé à l'accueil.
 */
export default function ResetPasswordScreen() {
  const { token: rawToken } = useLocalSearchParams<{ token?: string }>();
  const token = (rawToken ?? '').toString();
  const router = useRouter();
  const { completePasswordReset, status } = useAuth();

  const [phase, setPhase] = useState<Phase>('checking');
  const [done, setDone] = useState(false);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    if (token === '') {
      setPhase('invalid');
      return;
    }
    (async () => {
      try {
        const { valid } = await auth.checkPasswordReset(token);
        if (!cancelled) setPhase(valid ? 'form' : 'invalid');
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof ApiError ? e.message : 'Vérification impossible. Vérifiez votre connexion.');
          setPhase('invalid');
        }
      }
    })();
    return () => { cancelled = true; };
  }, [token]);

  // Une fois le mot de passe enregistré, on n'ouvre l'accueil qu'APRÈS que la session soit
  // visible de l'AuthGate : naviguer tout de suite le ferait croire l'adhérent encore
  // déconnecté (renvoi vers /login puis retour sur ce lien, déjà consommé).
  useEffect(() => {
    if (done && status === 'authenticated') router.replace('/(tabs)');
  }, [done, status, router]);

  async function submit() {
    if (password.length < MIN_LENGTH) {
      setError(`Le mot de passe doit faire au moins ${MIN_LENGTH} caractères.`);
      return;
    }
    if (password !== confirm) {
      setError('Les deux mots de passe ne sont pas identiques.');
      return;
    }
    setError(null);
    setBusy(true);
    try {
      await completePasswordReset(token, password);
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Enregistrement impossible. Réessayez.');
      // Un lien devenu invalide (déjà utilisé, expiré) ne se corrige pas en réessayant.
      if (e instanceof ApiError && e.status === 400 && /lien/i.test(e.message)) setPhase('invalid');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.card}>
            {phase === 'checking' && (
              <>
                <ActivityIndicator size="large" color={COLORS.primary} />
                <Text style={[styles.title, { marginTop: 14 }]}>Vérification du lien…</Text>
              </>
            )}

            {phase === 'invalid' && (
              <>
                <Text style={styles.icon}>⚠️</Text>
                <Text style={styles.title}>Lien invalide ou expiré</Text>
                <Text style={styles.body}>
                  {error ?? 'Ce lien a déjà été utilisé ou n\'est plus valable. Les liens de réinitialisation durent 1 heure.'}
                </Text>
                <Pressable
                  onPress={() => router.replace('/(auth)/forgot-password')}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.button, pressed && { opacity: 0.85 }]}
                >
                  <Text style={styles.buttonLabel}>Demander un nouveau lien</Text>
                </Pressable>
              </>
            )}

            {phase === 'form' && (
              <>
                <Text style={styles.icon}>🔐</Text>
                <Text style={styles.title}>Nouveau mot de passe</Text>
                <Text style={styles.body}>Choisissez un mot de passe d'au moins {MIN_LENGTH} caractères.</Text>

                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="Nouveau mot de passe"
                  placeholderTextColor={COLORS.textSubtle}
                  secureTextEntry={!visible}
                  autoCapitalize="none"
                  autoComplete="new-password"
                  textContentType="newPassword"
                  editable={!busy}
                  style={styles.input}
                />
                <TextInput
                  value={confirm}
                  onChangeText={setConfirm}
                  placeholder="Confirmez le mot de passe"
                  placeholderTextColor={COLORS.textSubtle}
                  secureTextEntry={!visible}
                  autoCapitalize="none"
                  autoComplete="new-password"
                  textContentType="newPassword"
                  returnKeyType="done"
                  onSubmitEditing={submit}
                  editable={!busy}
                  style={[styles.input, { marginTop: 10 }]}
                />

                <Pressable onPress={() => setVisible((v) => !v)} accessibilityRole="button" style={styles.toggle}>
                  <Text style={styles.toggleLabel}>{visible ? 'Masquer' : 'Afficher'} les mots de passe</Text>
                </Pressable>

                {error && <Text style={styles.error}>{error}</Text>}

                <Pressable
                  onPress={submit}
                  disabled={busy}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.button, busy && styles.buttonDisabled, pressed && { opacity: 0.85 }]}
                >
                  {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Enregistrer et me connecter</Text>}
                </Pressable>
              </>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.brandNavy },
  scroll: { flexGrow: 1, justifyContent: 'center', padding: SPACING.xl, maxWidth: 480, width: '100%', alignSelf: 'center' },
  card: { backgroundColor: '#fff', borderRadius: RADIUS.xl, padding: SPACING.xl, alignItems: 'center' },
  icon: { fontSize: 44, marginBottom: 8 },
  title: { fontSize: 21, fontWeight: '800', color: COLORS.text, textAlign: 'center', marginBottom: 10 },
  body: { fontSize: 15, color: COLORS.text, textAlign: 'center', lineHeight: 22, marginBottom: 16 },
  input: {
    alignSelf: 'stretch',
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 13,
    fontSize: 16,
    color: COLORS.text,
    backgroundColor: COLORS.surface,
  },
  toggle: { alignSelf: 'flex-end', paddingVertical: 8 },
  toggleLabel: { color: COLORS.secondary, fontSize: 13, fontWeight: '600' },
  error: { alignSelf: 'stretch', color: COLORS.error, fontSize: 14, marginTop: 6, textAlign: 'center' },
  button: {
    alignSelf: 'stretch',
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    marginTop: 14,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonLabel: { color: '#fff', fontSize: 16, fontWeight: '700' },
});
