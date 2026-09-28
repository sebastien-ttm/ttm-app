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
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { photos as photosApi } from '@/api/resources';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

/** Création d'un album, puis ouverture directe de l'ajout de photos. */
export default function PhotoAlbumNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const [name, setName] = useState('');
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    if (name.trim() === '') {
      setError('Le nom de l\'album ne peut pas être vide.');
      return;
    }
    setBusy(true);
    try {
      const album = await photosApi.createAlbum(name.trim(), comment.trim());
      router.replace({ pathname: '/photos/[id]', params: { id: String(album.id), add: '1' } } as never);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Nouvel album' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.label}>Nom de l'album</Text>
          <TextInput
            value={name}
            onChangeText={setName}
            placeholder="Ex : Triathlon de Carcassonne — 5 octobre 2026"
            placeholderTextColor={COLORS.textSubtle}
            maxLength={100}
            style={styles.input}
            editable={!busy}
          />
          <Text style={styles.hint}>Pensez à mettre le nom de la course ou de la sortie et la date.</Text>

          <Text style={styles.label}>Description (facultatif)</Text>
          <TextInput
            value={comment}
            onChangeText={setComment}
            placeholder="Quelques mots sur l'événement…"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={500}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            onPress={submit}
            disabled={busy || name.trim() === ''}
            style={[styles.button, (busy || name.trim() === '') && styles.buttonDisabled]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Créer l'album et ajouter des photos</Text>}
          </Pressable>
          <Pressable onPress={goBack} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Annuler</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.md },
  hint: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text,
    borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 90, textAlignVertical: 'top' },
  error: {
    color: COLORS.error, backgroundColor: '#fee2e2',
    padding: 12, borderRadius: RADIUS.sm, marginTop: SPACING.md,
    fontSize: 13, fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md,
    paddingVertical: 14, alignItems: 'center', marginTop: SPACING.lg,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
