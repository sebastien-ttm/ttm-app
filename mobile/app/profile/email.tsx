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

          {/* Adresse partagée avec d'autres profils : ce que le changement va faire, avant de le demander. */}
          {user?.emailLink?.role === 'dependent' && (
            <View style={styles.notice}>
              <Text style={styles.noticeTitle}>Adresse partagée avec {user.emailLink.primaryName}</Text>
              <Text style={styles.noticeText}>
                Ce profil utilise pour l'instant l'adresse e-mail de {user.emailLink.primaryName}. Si vous la
                changez, il aura sa propre adresse de connexion : il ne sera plus rattaché à ce compte par l'adresse
                partagée.
              </Text>
              {/* La bascule de profil ne tient plus qu'à la relation parent / enfant déclarée avec le club. */}
              {user.emailLink.familyLink === true && (
                <Text style={[styles.noticeText, styles.noticeOk]}>
                  ✅ Un lien parent / enfant est déclaré avec {user.emailLink.primaryName} : la bascule de profil entre
                  les deux comptes restera possible.
                </Text>
              )}
              {user.emailLink.familyLink === false && (
                <Text style={[styles.noticeText, styles.noticeWarn]}>
                  ⚠️ Aucun lien parent / enfant n'est déclaré avec {user.emailLink.primaryName} : après le changement,
                  la bascule de profil entre les deux comptes ne sera plus possible tant que ce lien n'aura pas été
                  créé. {user.emailLink.primaryName} peut le créer depuis son profil (« Mes enfants », avec le numéro
                  de licence), ou le club depuis le backend.
                </Text>
              )}
              <Text style={styles.noticeText}>
                Le lien de confirmation part vers l'adresse actuelle (partagée), qu'il faudra donc ouvrir pour valider.
              </Text>
            </View>
          )}
          {user?.emailLink?.role === 'primary' && (
            <View style={styles.notice}>
              <Text style={styles.noticeTitle}>Adresse partagée avec d'autres profils</Text>
              <Text style={styles.noticeText}>
                Cette adresse est aussi celle de : {user.emailLink.dependentNames.join(', ')}. Si vous la changez,
                ces profils changent d'adresse avec vous (ils restent rattachés à votre compte). Pour donner une
                adresse différente à l'un d'eux, ouvrez son profil depuis le sélecteur de profil et modifiez l'adresse
                depuis là.
              </Text>
            </View>
          )}

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
  notice: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.secondary,
    borderWidth: 1,
    borderColor: COLORS.border,
    padding: SPACING.md,
    gap: 4,
    marginBottom: SPACING.xl,
  },
  noticeTitle: { fontSize: 14, fontWeight: '800', color: COLORS.text },
  noticeText: { fontSize: 13, color: COLORS.textMuted, lineHeight: 19 },
  noticeOk: { color: '#166534', fontWeight: '600' },
  noticeWarn: { color: '#b45309', fontWeight: '600' },
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
