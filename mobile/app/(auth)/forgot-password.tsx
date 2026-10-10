import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
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
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * « Mot de passe oublié » : l'adhérent saisit son adresse e-mail et reçoit un lien pour
 * choisir un nouveau mot de passe (écran reset-password). La réponse est la même que
 * l'adresse corresponde ou non à un compte : on ne révèle pas qui est adhérent.
 */
export default function ForgotPasswordScreen() {
  const params = useLocalSearchParams<{ email?: string }>();
  const router = useRouter();
  const [email, setEmail] = useState((params.email ?? '').toString());
  const [busy, setBusy] = useState(false);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    const value = email.trim();
    if (value === '' || !value.includes('@')) {
      setError('Saisissez une adresse e-mail valide.');
      return;
    }
    setError(null);
    setBusy(true);
    try {
      await auth.requestPasswordReset(value);
      setSentTo(value);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Envoi impossible. Réessayez dans un instant.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.card}>
            {sentTo === null ? (
              <>
                <Text style={styles.icon}>🔑</Text>
                <Text style={styles.title}>Mot de passe oublié ?</Text>
                <Text style={styles.body}>
                  Saisissez l'adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.
                </Text>

                <TextInput
                  value={email}
                  onChangeText={setEmail}
                  placeholder="vous@example.fr"
                  placeholderTextColor={COLORS.textSubtle}
                  autoCapitalize="none"
                  autoComplete="email"
                  autoCorrect={false}
                  keyboardType="email-address"
                  inputMode="email"
                  returnKeyType="send"
                  onSubmitEditing={submit}
                  editable={!busy}
                  style={styles.input}
                />

                {error && <Text style={styles.error}>{error}</Text>}

                <Pressable
                  onPress={submit}
                  disabled={busy}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.button, busy && styles.buttonDisabled, pressed && { opacity: 0.85 }]}
                >
                  {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Recevoir le lien</Text>}
                </Pressable>
              </>
            ) : (
              <>
                <Text style={styles.icon}>📬</Text>
                <Text style={styles.title}>Vérifiez vos e-mails</Text>
                <Text style={styles.body}>
                  Si <Text style={styles.bold}>{sentTo}</Text> correspond à un compte actif, vous allez recevoir un e-mail avec un lien
                  pour choisir un nouveau mot de passe.
                </Text>
                <Text style={styles.hint}>
                  Le lien est valable 1 heure et utilisable une seule fois. Pensez à regarder dans vos courriers indésirables.
                </Text>
              </>
            )}

            <Pressable onPress={() => router.replace('/(auth)/login')} style={styles.backButton}>
              <Text style={styles.backLabel}>← Retour à la connexion</Text>
            </Pressable>
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
  bold: { fontWeight: '700' },
  hint: { fontSize: 13, color: COLORS.textMuted, textAlign: 'center', lineHeight: 19 },
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
  error: { alignSelf: 'stretch', color: COLORS.error, fontSize: 14, marginTop: 10, textAlign: 'center' },
  button: {
    alignSelf: 'stretch',
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    marginTop: 16,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonLabel: { color: '#fff', fontSize: 16, fontWeight: '700' },
  backButton: { marginTop: 20, padding: 10 },
  backLabel: { color: COLORS.secondary, fontWeight: '600', fontSize: 15 },
});
