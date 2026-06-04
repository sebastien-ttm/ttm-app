import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
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
import type { Trainer } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

const RECIPIENT_CLUB = -1;

/**
 * Composer : envoyer un nouveau message au club ou à un entraîneur.
 */
export default function ProfileMessagesNewScreen() {
  const router = useRouter();
  const [trainers, setTrainers] = useState<Trainer[]>([]);
  const [loading, setLoading] = useState(true);
  const [recipientId, setRecipientId] = useState<number>(RECIPIENT_CLUB);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadTrainers = useCallback(async () => {
    try {
      const resp = await auth.listTrainers();
      setTrainers(resp.data);
    } catch {
      // Pas critique — l'utilisateur peut toujours écrire au club
      setTrainers([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void loadTrainers(); }, [loadTrainers]);

  async function submit() {
    setError(null);
    const trimmedBody = body.trim();
    if (trimmedBody === '') {
      setError('Le message ne peut pas être vide.');
      return;
    }
    if (trimmedBody.length > 5000) {
      setError('Message trop long (5000 caractères max).');
      return;
    }
    setBusy(true);
    try {
      await auth.sendMessage({
        recipientId: recipientId === RECIPIENT_CLUB ? null : recipientId,
        subject: subject.trim() || undefined,
        body: trimmedBody,
      });
      router.back();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Nouveau message' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.label}>Destinataire</Text>
          {loading ? (
            <ActivityIndicator color={COLORS.primary} style={{ marginVertical: 12 }} />
          ) : (
            <View style={styles.recipientList}>
              <RecipientChoice
                label="Le club (administration)"
                selected={recipientId === RECIPIENT_CLUB}
                onPress={() => setRecipientId(RECIPIENT_CLUB)}
              />
              {trainers.map((t) => (
                <RecipientChoice
                  key={t.id}
                  label={t.fullName}
                  selected={recipientId === t.id}
                  onPress={() => setRecipientId(t.id)}
                />
              ))}
            </View>
          )}

          <Text style={[styles.label, { marginTop: SPACING.lg }]}>Objet (optionnel)</Text>
          <TextInput
            value={subject}
            onChangeText={setSubject}
            placeholder="Ex : Question matériel piscine"
            placeholderTextColor={COLORS.textSubtle}
            maxLength={200}
            style={styles.input}
            editable={!busy}
          />

          <Text style={styles.label}>Votre message</Text>
          <TextInput
            value={body}
            onChangeText={setBody}
            placeholder="Tapez votre message ici…"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={5000}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />
          <Text style={styles.counter}>{body.length} / 5000</Text>

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            style={[styles.button, (busy || body.trim() === '') && styles.buttonDisabled]}
            onPress={submit}
            disabled={busy || body.trim() === ''}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.buttonLabel}>Envoyer</Text>
            )}
          </Pressable>

          <Pressable style={styles.cancel} onPress={() => router.back()} disabled={busy}>
            <Text style={styles.cancelLabel}>Annuler</Text>
          </Pressable>

          <Text style={styles.notice}>
            Vous recevrez la réponse dans cette même section. Une seule réponse est possible
            par message.
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function RecipientChoice({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [
        styles.recipientRow,
        selected && styles.recipientRowSelected,
        pressed && { opacity: 0.7 },
      ]}
    >
      <Ionicons
        name={selected ? 'radio-button-on' : 'radio-button-off'}
        size={20}
        color={selected ? COLORS.primary : COLORS.textMuted}
      />
      <Text style={[styles.recipientLabel, selected && { fontWeight: '700', color: COLORS.text }]}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
  recipientList: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  recipientRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    paddingHorizontal: 14,
    gap: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  recipientRowSelected: { backgroundColor: COLORS.primarySoft },
  recipientLabel: { fontSize: 14, color: COLORS.text },
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
  textarea: { minHeight: 160, textAlignVertical: 'top' },
  counter: { textAlign: 'right', fontSize: 11, color: COLORS.textMuted, marginTop: -8, marginBottom: SPACING.sm },
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
  notice: {
    color: COLORS.textMuted,
    fontSize: 12,
    textAlign: 'center',
    marginTop: SPACING.lg,
    fontStyle: 'italic',
    lineHeight: 16,
  },
});
