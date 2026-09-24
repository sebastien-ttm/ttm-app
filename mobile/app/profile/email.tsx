import { Stack } from 'expo-router';
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
import { useAuth } from '@/auth/AuthContext';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/**
 * Changement d'adresse e-mail en libre-service. L'adresse ne change PAS
 * ici : on envoie un lien de confirmation à l'adresse ACTUELLE, et le
 * changement n'est appliqué qu'au clic sur ce lien.
 */
export default function ProfileEmailScreen() {
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const [newEmail, setNewEmail] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sentTo, setSentTo] = useState<string | null>(null);

  async function submit() {
    setError(null);
    const email = newEmail.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError('Saisissez une adresse e-mail valide.');
      return;
    }
    if (email !== confirm.trim().toLowerCase()) {
      setError('Les deux adresses ne correspondent pas.');
      return;
    }
    setBusy(true);
    try {
      const resp = await auth.requestEmailChange(email);
      setSentTo(resp.sentTo);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue');
    } finally {
      setBusy(false);
    }
  }

  if (sentTo) {
    return (
      <SafeAreaView style={styles.container} edges={['bottom']}>
        <Stack.Screen options={{ title: 'Adresse e-mail' }} />
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.sentBox}>
            <Text style={styles.sentTitle}>Vérifiez votre boîte mail</Text>
            <Text style={styles.sentText}>
              Un e-mail vient d'être envoyé à votre adresse actuelle ({sentTo}). Cliquez sur le
              lien qu'il contient pour confirmer le changement vers{' '}
              <Text style={{ fontWeight: '700' }}>{newEmail.trim().toLowerCase()}</Text>.
            </Text>
            <Text style={styles.sentHint}>
              Le lien est valable 2 heures. Tant que vous ne l'avez pas ouvert, votre adresse
              actuelle reste inchangée.
            </Text>
          </View>
          <Pressable style={styles.button} onPress={goBack}>
            <Text style={styles.buttonLabel}>Retour au profil</Text>
          </Pressable>
        </ScrollView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Modifier mon adresse e-mail' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.intro}>
            Par sécurité, un lien de confirmation sera envoyé à votre adresse actuelle
            {user?.email ? ` (${user.email})` : ''}. Le changement ne sera appliqué qu'après
            clic sur ce lien.
          </Text>

          <Text style={styles.label}>Nouvelle adresse e-mail</Text>
          <TextInput
            value={newEmail}
            onChangeText={setNewEmail}
            placeholder="nouvelle@adresse.fr"
            placeholderTextColor={COLORS.textSubtle}
            autoCapitalize="none"
            autoComplete="email"
            keyboardType="email-address"
            inputMode="email"
            style={styles.input}
            editable={!busy}
          />

          <Text style={styles.label}>Confirmer la nouvelle adresse</Text>
          <TextInput
            value={confirm}
            onChangeText={setConfirm}
            placeholder="Retapez la même adresse"
            placeholderTextColor={COLORS.textSubtle}
            autoCapitalize="none"
            keyboardType="email-address"
            inputMode="email"
            style={styles.input}
            editable={!busy}
          />

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            style={[styles.button, (busy || !newEmail || !confirm) && styles.buttonDisabled]}
            onPress={submit}
            disabled={busy || !newEmail || !confirm}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.buttonLabel}>Envoyer le lien de confirmation</Text>
            )}
          </Pressable>

          <Pressable style={styles.cancel} onPress={goBack} disabled={busy}>
            <Text style={styles.cancelLabel}>Annuler</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, maxWidth: 480, width: '100%', alignSelf: 'center' },
  intro: { fontSize: 14, color: COLORS.textMuted, marginBottom: SPACING.xl, lineHeight: 20 },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
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
    marginTop: SPACING.sm,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  cancel: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.xs },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
  sentBox: {
    backgroundColor: '#dcfce7',
    borderRadius: RADIUS.md,
    padding: SPACING.lg,
    marginBottom: SPACING.lg,
  },
  sentTitle: { fontSize: 17, fontWeight: '700', color: '#166534', marginBottom: 8 },
  sentText: { fontSize: 14, color: COLORS.text, lineHeight: 21 },
  sentHint: { fontSize: 12, color: COLORS.textMuted, marginTop: 10, lineHeight: 18 },
});
