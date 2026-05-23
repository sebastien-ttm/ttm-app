import { Stack, useRouter } from 'expo-router';
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

export default function ProfilePasswordScreen() {
  const router = useRouter();
  const { user, refreshMe } = useAuth();
  const [newPassword, setNewPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const alreadyConfigured = user?.hasPassword === true;
  const title = alreadyConfigured ? 'Modifier mon mot de passe' : 'Définir un mot de passe';

  async function submit() {
    setError(null);
    if (newPassword.length < 8) {
      setError('Le mot de passe doit faire au moins 8 caractères.');
      return;
    }
    if (newPassword !== confirm) {
      setError('Les deux mots de passe ne correspondent pas.');
      return;
    }
    setBusy(true);
    try {
      await auth.setPassword(newPassword);
      await refreshMe();
      setSuccess(true);
      setNewPassword('');
      setConfirm('');
      // Auto-retour profil après 1.5s
      setTimeout(() => router.back(), 1500);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.intro}>
            {alreadyConfigured
              ? 'Saisissez un nouveau mot de passe. L\'ancien sera remplacé immédiatement.'
              : 'Vous pourrez ensuite vous connecter avec votre e-mail + mot de passe, en plus du lien e-mail.'}
          </Text>

          <Text style={styles.label}>Nouveau mot de passe</Text>
          <TextInput
            value={newPassword}
            onChangeText={setNewPassword}
            placeholder="Au moins 8 caractères"
            placeholderTextColor={COLORS.textSubtle}
            secureTextEntry
            autoCapitalize="none"
            style={styles.input}
            editable={!busy}
          />

          <Text style={styles.label}>Confirmer</Text>
          <TextInput
            value={confirm}
            onChangeText={setConfirm}
            placeholder="Retapez le même"
            placeholderTextColor={COLORS.textSubtle}
            secureTextEntry
            autoCapitalize="none"
            style={styles.input}
            editable={!busy}
          />

          {error && <Text style={styles.error}>{error}</Text>}
          {success && <Text style={styles.success}>✓ Mot de passe mis à jour.</Text>}

          <Pressable
            style={[styles.button, (busy || !newPassword || !confirm) && styles.buttonDisabled]}
            onPress={submit}
            disabled={busy || !newPassword || !confirm}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.buttonLabel}>Enregistrer</Text>
            )}
          </Pressable>

          <Pressable style={styles.cancel} onPress={() => router.back()} disabled={busy}>
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
  success: {
    color: COLORS.success,
    backgroundColor: '#dcfce7',
    padding: 12,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
    fontSize: 14,
    fontWeight: '600',
    textAlign: 'center',
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
});
