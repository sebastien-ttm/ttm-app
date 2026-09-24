import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
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

import { ApiError } from '@/api/client';
import { marketplace as marketplaceApi } from '@/api/resources';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { pickReducedPhotos, type PickedPhoto } from '@/lib/marketplacePhotos';

const MAX_PHOTOS = 5;

/** Création d'une annonce : titre, texte, jusqu'à 5 photos. */
export default function MarketplaceNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [photos, setPhotos] = useState<PickedPhoto[]>([]);
  const [busy, setBusy] = useState(false);
  /** Réduction des photos en cours (quelques secondes pour 5 photos). */
  const [preparing, setPreparing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function pickPhotos() {
    if (photos.length >= MAX_PHOTOS || preparing) return;
    setPreparing(true);
    try {
      const picked = await pickReducedPhotos(MAX_PHOTOS - photos.length);
      if (picked.length > 0) setPhotos((prev) => [...prev, ...picked].slice(0, MAX_PHOTOS));
    } finally {
      setPreparing(false);
    }
  }

  function removePhoto(index: number) {
    setPhotos((prev) => prev.filter((_, i) => i !== index));
  }

  async function submit() {
    setError(null);
    const trimmedTitle = title.trim();
    const trimmedDescription = description.trim();
    if (trimmedTitle === '') {
      setError('Le titre ne peut pas être vide.');
      return;
    }
    if (trimmedDescription === '') {
      setError('La description ne peut pas être vide.');
      return;
    }
    setBusy(true);
    try {
      const created = await marketplaceApi.create(trimmedTitle, trimmedDescription, photos);
      router.replace(('/marketplace/' + created.id) as never);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Nouvelle annonce' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.label}>Titre</Text>
          <TextInput
            value={title}
            onChangeText={setTitle}
            placeholder="Ex : Combinaison néoprène taille M"
            placeholderTextColor={COLORS.textSubtle}
            maxLength={120}
            style={styles.input}
            editable={!busy}
          />
          <Text style={styles.counter}>{title.length} / 120</Text>

          <Text style={styles.label}>Description</Text>
          <TextInput
            value={description}
            onChangeText={setDescription}
            placeholder="État, taille, prix, lieu de remise en main propre…"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={3000}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />
          <Text style={styles.counter}>{description.length} / 3000</Text>

          <Text style={styles.label}>Photos ({photos.length} / {MAX_PHOTOS})</Text>
          <View style={styles.photoRow}>
            {photos.map((p, i) => (
              <View key={p.uri + i} style={styles.photoThumbWrap}>
                <Image source={{ uri: p.uri }} style={styles.photoThumb} contentFit="cover" />
                <Pressable onPress={() => removePhoto(i)} style={styles.photoRemove} disabled={busy}>
                  <Ionicons name="close" size={14} color="#fff" />
                </Pressable>
              </View>
            ))}
            {photos.length < MAX_PHOTOS && (
              <Pressable onPress={pickPhotos} disabled={busy || preparing} style={styles.photoAdd}>
                {preparing
                  ? <ActivityIndicator color={COLORS.textMuted} />
                  : <Ionicons name="camera-outline" size={24} color={COLORS.textMuted} />}
              </Pressable>
            )}
          </View>

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            onPress={submit}
            disabled={busy || title.trim() === '' || description.trim() === ''}
            style={[styles.button, (busy || title.trim() === '' || description.trim() === '') && styles.buttonDisabled]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Publier l'annonce</Text>}
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
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  textarea: { minHeight: 140, textAlignVertical: 'top' },
  counter: { textAlign: 'right', fontSize: 11, color: COLORS.textMuted, marginTop: 4 },
  photoRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 4 },
  photoThumbWrap: { position: 'relative' },
  photoThumb: { width: 76, height: 76, borderRadius: RADIUS.sm, backgroundColor: COLORS.surface },
  photoRemove: {
    position: 'absolute', top: -6, right: -6,
    backgroundColor: COLORS.error, borderRadius: 10,
    width: 20, height: 20, alignItems: 'center', justifyContent: 'center',
  },
  photoAdd: {
    width: 76, height: 76, borderRadius: RADIUS.sm,
    borderWidth: 1, borderColor: COLORS.border, borderStyle: 'dashed',
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.surface,
  },
  error: {
    color: COLORS.error,
    backgroundColor: '#fee2e2',
    padding: 12,
    borderRadius: RADIUS.sm,
    marginTop: SPACING.md,
    fontSize: 13, fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.lg,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
