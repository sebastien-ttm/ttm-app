import { Link, useRouter } from 'expo-router';
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
import { APP_NAME, COLORS } from '@/config';

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
            <Text style={styles.brandTitle}>{APP_NAME}</Text>
            <Text style={styles.brandSubtitle}>Espace adhérents</Text>
          </View>

          <View style={styles.tabs}>
            <Pressable
              style={[styles.tab, mode === 'magic-link' && styles.tabActive]}
              onPress={() => setMode('magic-link')}
            >
              <Text style={[styles.tabLabel, mode === 'magic-link' && styles.tabLabelActive]}>Lien e-mail</Text>
            </Pressable>
            <Pressable
              style={[styles.tab, mode === 'password' && styles.tabActive]}
              onPress={() => setMode('password')}
            >
              <Text style={[styles.tabLabel, mode === 'password' && styles.tabLabelActive]}>Mot de passe</Text>
            </Pressable>
          </View>

          <Text style={styles.label}>Adresse e-mail</Text>
          <TextInput
            value={email}
            onChangeText={setEmail}
            placeholder="vous@example.fr"
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
                secureTextEntry
                style={styles.input}
                editable={!busy}
              />
            </>
          )}

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable style={[styles.button, busy && styles.buttonDisabled]} onPress={submit} disabled={busy}>
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
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.black },
  scroll: { flexGrow: 1, justifyContent: 'center', padding: 24 },
  brand: { alignItems: 'center', marginBottom: 32 },
  brandTitle: { color: '#fff', fontSize: 22, fontWeight: '700', textAlign: 'center' },
  brandSubtitle: { color: '#bbb', fontSize: 14, marginTop: 6 },
  tabs: { flexDirection: 'row', backgroundColor: '#2a2a2a', borderRadius: 8, padding: 4, marginBottom: 24 },
  tab: { flex: 1, paddingVertical: 10, borderRadius: 6, alignItems: 'center' },
  tabActive: { backgroundColor: COLORS.primary },
  tabLabel: { color: '#999', fontWeight: '600', fontSize: 14 },
  tabLabelActive: { color: '#fff' },
  label: { color: '#fff', fontWeight: '600', marginBottom: 6, fontSize: 14 },
  input: {
    backgroundColor: '#fff',
    borderRadius: 8,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    marginBottom: 16,
    color: COLORS.text,
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: 8,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 16 },
  error: {
    color: '#ff8a80',
    backgroundColor: '#3b1010',
    padding: 12,
    borderRadius: 6,
    marginBottom: 12,
  },
  help: { color: '#999', fontSize: 13, marginTop: 20, textAlign: 'center', lineHeight: 19 },
});
