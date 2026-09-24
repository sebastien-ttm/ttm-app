import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
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
import type { MarketplaceListing } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';

const MAX_PHOTOS = 5;

type PickedPhoto = { uri: string; mimeType: string; name: string };

/**
 * Édition d'une annonce : titre/texte (enregistrés au clic sur
 * « Enregistrer »), photos existantes (suppression immédiate au tap),
 * nouvelles photos (mises en file, uploadées à l'enregistrement).
 */
export default function MarketplaceEditScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [listing, setListing] = useState<MarketplaceListing | null>(null);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [newPhotos, setNewPhotos] = useState<PickedPhoto[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [removingPhotoId, setRemovingPhotoId] = useState<number | null>(null);

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      setLoading(false);
      return;
    }
    try {
      setError(null);
      const resp = await marketplaceApi.get(id);
      setListing(resp);
      setTitle(resp.title);
      setDescription(resp.description);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  const totalPhotos = (listing?.photos.length ?? 0) + newPhotos.length;

  async function pickPhotos() {
    if (totalPhotos >= MAX_PHOTOS) return;
    if (Platform.OS !== 'web') {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('Permission refusée', 'Autorise l\'accès aux photos dans les réglages.');
        return;
      }
    }
    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      allowsMultipleSelection: true,
      selectionLimit: MAX_PHOTOS - totalPhotos,
      quality: 0.8,
    });
    if (result.canceled || result.assets.length === 0) return;
    const picked: PickedPhoto[] = result.assets.map((a, i) => {
      const mime = a.mimeType ?? 'image/jpeg';
      const ext = mime.split('/')[1] ?? 'jpg';
      return { uri: a.uri, mimeType: mime, name: `photo-${Date.now()}-${i}.${ext}` };
    });
    setNewPhotos((prev) => [...prev, ...picked].slice(0, MAX_PHOTOS - (listing?.photos.length ?? 0)));
  }

  function removeNewPhoto(index: number) {
    setNewPhotos((prev) => prev.filter((_, i) => i !== index));
  }

  async function removeExistingPhoto(photoId: number) {
    if (!listing) return;
    setRemovingPhotoId(photoId);
    try {
      const updated = await marketplaceApi.removePhoto(listing.id, photoId);
      setListing(updated);
    } catch (e) {
      showError(e);
    } finally {
      setRemovingPhotoId(null);
    }
  }

  async function submit() {
    if (!listing) return;
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
      await marketplaceApi.update(listing.id, { title: trimmedTitle, description: trimmedDescription });
      if (newPhotos.length > 0) {
        await marketplaceApi.addPhotos(listing.id, newPhotos);
      }
      router.replace(('/marketplace/' + listing.id) as never);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Modifier l\'annonce' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error && !listing) {
    return (
      <>
        <Stack.Screen options={{ title: 'Modifier l\'annonce' }} />
        <ErrorState message={error} onRetry={load} />
      </>
    );
  }
  if (!listing) return null;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Modifier l\'annonce' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.label}>Titre</Text>
          <TextInput
            value={title}
            onChangeText={setTitle}
            maxLength={120}
            style={styles.input}
            editable={!busy}
          />
          <Text style={styles.counter}>{title.length} / 120</Text>

          <Text style={styles.label}>Description</Text>
          <TextInput
            value={description}
            onChangeText={setDescription}
            multiline
            maxLength={3000}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />
          <Text style={styles.counter}>{description.length} / 3000</Text>

          <Text style={styles.label}>Photos ({totalPhotos} / {MAX_PHOTOS})</Text>
          <View style={styles.photoRow}>
            {listing.photos.map((p) => (
              <View key={p.id} style={styles.photoThumbWrap}>
                <Image source={{ uri: p.url }} style={styles.photoThumb} contentFit="cover" />
                <Pressable
                  onPress={() => void removeExistingPhoto(p.id)}
                  style={styles.photoRemove}
                  disabled={busy || removingPhotoId === p.id}
                >
                  {removingPhotoId === p.id
                    ? <ActivityIndicator size="small" color="#fff" />
                    : <Ionicons name="close" size={14} color="#fff" />}
                </Pressable>
              </View>
            ))}
            {newPhotos.map((p, i) => (
              <View key={p.uri + i} style={styles.photoThumbWrap}>
                <Image source={{ uri: p.uri }} style={styles.photoThumb} contentFit="cover" />
                <View style={styles.photoNewBadge}>
                  <Text style={styles.photoNewBadgeLabel}>Nouvelle</Text>
                </View>
                <Pressable onPress={() => removeNewPhoto(i)} style={styles.photoRemove} disabled={busy}>
                  <Ionicons name="close" size={14} color="#fff" />
                </Pressable>
              </View>
            ))}
            {totalPhotos < MAX_PHOTOS && (
              <Pressable onPress={pickPhotos} disabled={busy} style={styles.photoAdd}>
                <Ionicons name="camera-outline" size={24} color={COLORS.textMuted} />
              </Pressable>
            )}
          </View>

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            onPress={submit}
            disabled={busy || title.trim() === '' || description.trim() === ''}
            style={[styles.button, (busy || title.trim() === '' || description.trim() === '') && styles.buttonDisabled]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Enregistrer</Text>}
          </Pressable>
          <Pressable onPress={goBack} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Annuler</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
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
  photoNewBadge: {
    position: 'absolute', bottom: 2, left: 2, right: 2,
    backgroundColor: 'rgba(0,0,0,0.6)', borderRadius: 4, paddingVertical: 1,
  },
  photoNewBadgeLabel: { color: '#fff', fontSize: 9, fontWeight: '700', textAlign: 'center' },
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
