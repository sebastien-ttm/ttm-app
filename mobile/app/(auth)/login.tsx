import { useRouter } from 'expo-router';
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

import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { APP_NAME, COLORS, RADIUS, SPACING } from '@/config';

type Mode = 'magic-link' | 'password';

export default function LoginScreen() {
  const { loginWithPassword } = useAuth();
  const router = useRouter();
  const [mode, setMode] = useState<Mode>('magic-link');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    if (!email.trim()) {
      setError('Saisissez votre adresse e-mail.');
      return;
    }
    setBusy(true);
    try {
      if (mode === 'password') {
        if (!password) throw new ApiError('Saisissez votre mot de passe.', 0, null);
        await loginWithPassword(email, password);
      } else {
        router.push({ pathname: '/(auth)/magic-link-request', params: { email: email.trim() } });
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erreur inattendue');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.brand}>
            <View style={styles.logoMark}>
              <Text style={styles.logoLetters}>TTM</Text>
              <View style={styles.logoUnderline} />
            </View>
            <Text style={styles.brandTitle}>{APP_NAME}</Text>
            <Text style={styles.brandSubtitle}>Espace adhérents</Text>
          </View>

          <View style={styles.card}>
            <View style={styles.tabs}>
              <Pressable
                style={[styles.tab, mode === 'magic-link' && styles.tabActive]}
                onPress={() => setMode('magic-link')}
              >
                <Text style={[styles.tabLabel, mode === 'magic-link' && styles.tabLabelActive]}>
                  Lien e-mail
                </Text>
              </Pressable>
              <Pressable
                style={[styles.tab, mode === 'password' && styles.tabActive]}
                onPress={() => setMode('password')}
              >
                <Text style={[styles.tabLabel, mode === 'password' && styles.tabLabelActive]}>
                  Mot de passe
                </Text>
              </Pressable>
            </View>

            <Text style={styles.label}>Adresse e-mail</Text>
            <TextInput
              value={email}
              onChangeText={setEmail}
              placeholder="vous@example.fr"
              placeholderTextColor={COLORS.textSubtle}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              inputMode="email"
              style={styles.input}
              editable={!busy}
            />

            {mode === 'password' && (
              <>
                <Text style={styles.label}>Mot de passe</Text>
                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="••••••••"
                  placeholderTextColor={COLORS.textSubtle}
                  secureTextEntry
                  style={styles.input}
                  editable={!busy}
                />
              </>
            )}

            {error && <Text style={styles.error}>{error}</Text>}

            <Pressable
              style={({ pressed }) => [styles.button, busy && styles.buttonDisabled, pressed && styles.buttonPressed]}
              onPress={submit}
              disabled={busy}
            >
              {busy ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <Text style={styles.buttonLabel}>
                  {mode === 'magic-link' ? 'Recevoir un lien' : 'Se connecter'}
                </Text>
              )}
            </Pressable>

            <Text style={styles.help}>
              {mode === 'magic-link'
                ? 'Vous recevrez un e-mail avec un lien de connexion. Pas besoin de mémoriser un mot de passe.'
                : 'Si vous n\'avez pas encore de mot de passe, utilisez d\'abord le lien e-mail.'}
            </Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.black },
  scroll: { flexGrow: 1, justifyContent: 'center', padding: SPACING.xl, maxWidth: 480, width: '100%', alignSelf: 'center' },
  brand: { alignItems: 'center', marginBottom: SPACING.xxl },
  logoMark: {
    width: 72,
    height: 72,
    borderRadius: RADIUS.lg,
    backgroundColor: COLORS.primary,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: SPACING.lg,
    shadowColor: COLORS.primary,
    shadowOpacity: 0.5,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 4 },
    overflow: 'hidden',
  },
  logoLetters: { color: '#fff', fontSize: 22, fontWeight: '800', letterSpacing: 1 },
  logoUnderline: {
    // Bandeau bleu en bas du logo (clin d'œil à la natation / triathlon)
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: 6,
    backgroundColor: COLORS.secondary,
  },
  brandTitle: { color: '#fff', fontSize: 19, fontWeight: '700', textAlign: 'center', letterSpacing: -0.2 },
  brandSubtitle: { color: COLORS.secondary, fontSize: 13, marginTop: 6, letterSpacing: 0.5, fontWeight: '600', textTransform: 'uppercase' },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.xl,
    padding: SPACING.xl,
    shadowColor: '#000',
    shadowOpacity: 0.3,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 8 },
  },
  tabs: {
    flexDirection: 'row',
    backgroundColor: COLORS.surfaceAlt,
    borderRadius: RADIUS.md,
    padding: 4,
    marginBottom: SPACING.lg,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  tab: { flex: 1, paddingVertical: 10, borderRadius: RADIUS.sm, alignItems: 'center' },
  tabActive: { backgroundColor: COLORS.surface, ...{
    shadowColor: '#000', shadowOpacity: 0.08, shadowRadius: 4, shadowOffset: { width: 0, height: 1 },
  } },
  tabLabel: { color: COLORS.textMuted, fontWeight: '600', fontSize: 14 },
  tabLabelActive: { color: COLORS.primary },
  label: { color: COLORS.text, fontWeight: '600', marginBottom: 6, fontSize: 13 },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    marginBottom: SPACING.md,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.sm,
    // @ts-expect-error web-only
    transition: 'background-color 120ms ease',
  },
  buttonDisabled: { opacity: 0.6 },
  buttonPressed: { backgroundColor: COLORS.primaryDark },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  error: {
    color: COLORS.error,
    backgroundColor: COLORS.primarySoft,
    padding: 12,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
    fontSize: 13,
    fontWeight: '500',
    borderWidth: 1,
    borderColor: '#FFC9C9',
  },
  help: { color: COLORS.textMuted, fontSize: 13, marginTop: SPACING.lg, textAlign: 'center', lineHeight: 19 },
});
