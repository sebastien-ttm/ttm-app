import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { photos as photosApi } from '@/api/resources';
import type { PhotoAlbum, PhotoImage } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { pickReducedPhotos } from '@/lib/marketplacePhotos';
import { usePhotoUrls } from '@/lib/photoUrls';

const COLUMNS = 3;
const GAP = 3;
/** Photos choisies en une fois (envoyées une par une). */
const MAX_PICK = 30;
/** Plus grand côté envoyé : bonne qualité « souvenir », ~0,5-1 Mo par photo. */
const UPLOAD_MAX_SIDE = 2048;

/**
 * Un album de Photos du club : grille des photos (plus récentes
 * d'abord, chargement par pages), ajout de photos avec accord des
 * personnes photographiées et progression, visionneuse plein écran
 * (photos entières, flèches ‹ ›, suppression de ses propres photos).
 * `?add=1` (juste après la création) ouvre directement l'ajout.
 */
export default function PhotoAlbumScreen() {
  const { id: rawId, add } = useLocalSearchParams<{ id: string; add?: string }>();
  const id = Number(rawId);
  const { width } = useWindowDimensions();
  const { url, reload: reloadToken } = usePhotoUrls();

  const [album, setAlbum] = useState<PhotoAlbum | null>(null);
  const [images, setImages] = useState<PhotoImage[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [addOpen, setAddOpen] = useState(add === '1');
  const [consent, setConsent] = useState(false);
  const [progress, setProgress] = useState<{ done: number; total: number; failed: number } | null>(null);
  const [viewerIndex, setViewerIndex] = useState<number | null>(null);

  const loadFirst = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      setLoading(false);
      return;
    }
    try {
      setError(null);
      const resp = await photosApi.album(id, 0);
      setAlbum(resp.album);
      setImages(resp.images);
      setTotal(resp.total);
      setPage(0);
      await reloadToken();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id, reloadToken]);

  useEffect(() => { void loadFirst(); }, [loadFirst]);

  async function loadMore() {
    if (loadingMore || images.length >= total) return;
    setLoadingMore(true);
    try {
      const resp = await photosApi.album(id, page + 1);
      setImages((prev) => [...prev, ...resp.images.filter((i) => !prev.some((p) => p.id === i.id))]);
      setPage(page + 1);
    } catch {
      /* on réessaiera au prochain défilement */
    } finally {
      setLoadingMore(false);
    }
  }

  async function pickAndUpload() {
    if (!consent || progress) return;
    const picked = await pickReducedPhotos(MAX_PICK, UPLOAD_MAX_SIDE);
    if (picked.length === 0) return;

    let failed = 0;
    let lastError: string | null = null;
    setProgress({ done: 0, total: picked.length, failed: 0 });
    for (const [i, photo] of picked.entries()) {
      try {
        await photosApi.upload(id, photo);
      } catch (e) {
        failed++;
        lastError = e instanceof ApiError ? e.message : 'Erreur réseau';
      }
      setProgress({ done: i + 1, total: picked.length, failed });
    }
    setProgress(null);
    setAddOpen(false);
    setConsent(false);
    await loadFirst();
    if (failed > 0) {
      showMessage(
        `${picked.length - failed} photo(s) ajoutée(s), ${failed} en échec.`,
        lastError ?? undefined,
      );
    }
  }

  async function removeImage(image: PhotoImage) {
    const confirmed = await confirmAsync('Supprimer cette photo ?', 'Elle sera retirée de la galerie du club.');
    if (!confirmed) return;
    try {
      await photosApi.remove(image.id);
      setImages((prev) => prev.filter((i) => i.id !== image.id));
      setTotal((t) => Math.max(0, t - 1));
      setViewerIndex(null);
    } catch (e) {
      showMessage('Suppression impossible', e instanceof ApiError ? e.message : undefined);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Album' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !album) {
    return (
      <>
        <Stack.Screen options={{ title: 'Album' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={loadFirst} />
      </>
    );
  }

  const gridWidth = Math.min(width, 900);
  const tile = Math.floor((gridWidth - GAP * (COLUMNS + 1)) / COLUMNS);

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: album.name }} />

      <FlatList
        data={images}
        keyExtractor={(i) => String(i.id)}
        numColumns={COLUMNS}
        style={{ width: gridWidth, alignSelf: 'center' }}
        contentContainerStyle={{ padding: GAP, gap: GAP }}
        columnWrapperStyle={{ gap: GAP }}
        onEndReached={() => void loadMore()}
        onEndReachedThreshold={0.5}
        ListHeaderComponent={
          <View style={styles.header}>
            <Text style={styles.title}>{album.name}</Text>
            <Text style={styles.meta}>{total} photo{total > 1 ? 's' : ''}</Text>
            {album.comment ? <Text style={styles.comment}>{album.comment}</Text> : null}

            {!addOpen ? (
              <Pressable style={styles.addButton} onPress={() => setAddOpen(true)}>
                <Ionicons name="camera" size={20} color="#fff" />
                <Text style={styles.addButtonLabel}>Ajouter mes photos</Text>
              </Pressable>
            ) : (
              <View style={styles.addPanel}>
                {progress ? (
                  <View style={{ gap: 8 }}>
                    <Text style={styles.addPanelTitle}>
                      Envoi en cours… {progress.done} / {progress.total}
                    </Text>
                    <View style={styles.progressTrack}>
                      <View style={[styles.progressBar, { width: `${(progress.done / progress.total) * 100}%` }]} />
                    </View>
                    <Text style={styles.addHint}>Gardez l'appli ouverte jusqu'à la fin de l'envoi.</Text>
                  </View>
                ) : (
                  <>
                    <Pressable
                      onPress={() => setConsent((c) => !c)}
                      style={styles.consentRow}
                      accessibilityRole="checkbox"
                      accessibilityState={{ checked: consent }}
                    >
                      <Ionicons
                        name={consent ? 'checkbox' : 'square-outline'}
                        size={22}
                        color={consent ? COLORS.primary : COLORS.textMuted}
                      />
                      <Text style={styles.consentLabel}>
                        Les personnes reconnaissables sur mes photos sont d'accord pour qu'elles soient
                        partagées avec les adhérents du club (pour un mineur : son représentant légal).
                      </Text>
                    </Pressable>
                    <Pressable
                      onPress={() => void pickAndUpload()}
                      disabled={!consent}
                      style={[styles.addButton, !consent && { opacity: 0.4 }]}
                    >
                      <Ionicons name="images" size={20} color="#fff" />
                      <Text style={styles.addButtonLabel}>Choisir les photos (jusqu'à {MAX_PICK})</Text>
                    </Pressable>
                    <Pressable onPress={() => { setAddOpen(false); setConsent(false); }} style={styles.cancelBtn}>
                      <Text style={styles.cancelLabel}>Annuler</Text>
                    </Pressable>
                  </>
                )}
              </View>
            )}
          </View>
        }
        renderItem={({ item, index }) => {
          const src = url(item.id, 'grid');
          return (
            <Pressable onPress={() => setViewerIndex(index)} style={{ width: tile, height: tile }}>
              {src ? (
                <Image source={{ uri: src }} style={styles.tile} contentFit="cover" transition={120} recyclingKey={String(item.id)} />
              ) : (
                <View style={[styles.tile, { backgroundColor: COLORS.border }]} />
              )}
            </Pressable>
          );
        }}
        ListEmptyComponent={
          <View style={styles.emptyCard}>
            <Ionicons name="images-outline" size={32} color={COLORS.textMuted} />
            <Text style={styles.emptyLabel}>Aucune photo dans cet album pour l'instant.</Text>
          </View>
        }
        ListFooterComponent={loadingMore ? <ActivityIndicator style={{ margin: SPACING.md }} color={COLORS.primary} /> : null}
      />

      <Viewer
        images={images}
        index={viewerIndex}
        onIndexChange={setViewerIndex}
        onClose={() => setViewerIndex(null)}
        onDelete={(img) => void removeImage(img)}
        url={url}
      />
    </SafeAreaView>
  );
}

/**
 * Visionneuse plein écran : photo entière (ni rognée ni déformée),
 * glissement horizontal ou flèches ‹ ›, compteur, auteur, suppression
 * si autorisée.
 */
function Viewer({ images, index, onIndexChange, onClose, onDelete, url }: {
  images: PhotoImage[];
  index: number | null;
  onIndexChange: (i: number) => void;
  onClose: () => void;
  onDelete: (img: PhotoImage) => void;
  url: (id: number, variant: 'grid' | 'full') => string | null;
}) {
  const { width, height } = useWindowDimensions();
  const listRef = useRef<FlatList<PhotoImage>>(null);
  const current = index !== null ? images[index] : null;

  function goTo(i: number) {
    const clamped = Math.max(0, Math.min(images.length - 1, i));
    listRef.current?.scrollToIndex({ index: clamped, animated: true });
    onIndexChange(clamped);
  }

  return (
    <Modal visible={index !== null} animationType="fade" onRequestClose={onClose} transparent={false}>
      <View style={styles.viewer}>
        {index !== null && (
          <FlatList
            ref={listRef}
            data={images}
            horizontal
            pagingEnabled
            showsHorizontalScrollIndicator={false}
            initialScrollIndex={index}
            getItemLayout={(_, i) => ({ length: width, offset: width * i, index: i })}
            keyExtractor={(i) => String(i.id)}
            windowSize={3}
            initialNumToRender={1}
            maxToRenderPerBatch={2}
            onScroll={(e) => {
              const i = Math.round(e.nativeEvent.contentOffset.x / width);
              if (i !== index && i >= 0 && i < images.length) onIndexChange(i);
            }}
            scrollEventThrottle={32}
            renderItem={({ item }) => {
              const src = url(item.id, 'full');
              return (
                <View style={{ width, height, justifyContent: 'center' }}>
                  {src ? <Image source={{ uri: src }} style={{ width, height }} contentFit="contain" transition={150} /> : null}
                </View>
              );
            }}
          />
        )}

        <SafeAreaView style={styles.viewerTop} edges={['top']} pointerEvents="box-none">
          <Pressable onPress={onClose} style={styles.viewerBtn} accessibilityLabel="Fermer" hitSlop={8}>
            <Ionicons name="close" size={26} color="#fff" />
          </Pressable>
          {index !== null && (
            <Text style={styles.viewerCounter}>{index + 1} / {images.length}</Text>
          )}
          {current?.canDelete ? (
            <Pressable onPress={() => onDelete(current)} style={styles.viewerBtn} accessibilityLabel="Supprimer la photo" hitSlop={8}>
              <Ionicons name="trash-outline" size={22} color="#fff" />
            </Pressable>
          ) : (
            <View style={{ width: 44 }} />
          )}
        </SafeAreaView>

        {index !== null && index > 0 && (
          <Pressable onPress={() => goTo(index - 1)} style={[styles.arrow, { left: SPACING.sm }]} accessibilityLabel="Photo précédente">
            <Ionicons name="chevron-back" size={28} color="#fff" />
          </Pressable>
        )}
        {index !== null && index < images.length - 1 && (
          <Pressable onPress={() => goTo(index + 1)} style={[styles.arrow, { right: SPACING.sm }]} accessibilityLabel="Photo suivante">
            <Ionicons name="chevron-forward" size={28} color="#fff" />
          </Pressable>
        )}

        {current?.authorName && (
          <SafeAreaView style={styles.viewerBottom} edges={['bottom']} pointerEvents="none">
            <Text style={styles.viewerAuthor}>📸 {current.authorName}</Text>
          </SafeAreaView>
        )}
      </View>
    </Modal>
  );
}

function showMessage(title: string, message?: string) {
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(message ? `${title}\n${message}` : title);
  } else {
    Alert.alert(title, message);
  }
}

function confirmAsync(title: string, message: string): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(typeof window !== 'undefined' ? window.confirm(title + '\n' + message) : false);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
      { text: 'Supprimer', style: 'destructive', onPress: () => resolve(true) },
    ]);
  });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  header: { padding: SPACING.md - GAP, paddingBottom: SPACING.sm, gap: 4 },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 13, color: COLORS.textMuted },
  comment: { fontSize: 14, color: COLORS.text, lineHeight: 20, marginTop: 4 },
  addButton: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.primary, paddingVertical: 12, borderRadius: RADIUS.md,
    marginTop: SPACING.sm,
  },
  addButtonLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
  addPanel: {
    marginTop: SPACING.sm, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
  },
  addPanelTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  addHint: { fontSize: 12, color: COLORS.textMuted },
  progressTrack: { height: 8, borderRadius: 4, backgroundColor: COLORS.border, overflow: 'hidden' },
  progressBar: { height: 8, backgroundColor: COLORS.primary },
  consentRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  consentLabel: { flex: 1, fontSize: 13, color: COLORS.text, lineHeight: 19 },
  cancelBtn: { alignItems: 'center', paddingTop: SPACING.sm },
  cancelLabel: { color: COLORS.textMuted, fontSize: 13, fontWeight: '500' },
  tile: { width: '100%', height: '100%', backgroundColor: COLORS.surface },
  emptyCard: {
    alignItems: 'center', gap: 8, padding: SPACING.xl, margin: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 14, textAlign: 'center' },
  viewer: { flex: 1, backgroundColor: '#000' },
  viewerTop: {
    position: 'absolute', top: 0, left: 0, right: 0,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingHorizontal: SPACING.sm,
  },
  viewerBtn: {
    width: 44, height: 44, borderRadius: 22,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.45)', margin: SPACING.sm,
  },
  viewerCounter: { color: '#fff', fontSize: 14, fontWeight: '600' },
  arrow: {
    position: 'absolute', top: '50%', marginTop: -24,
    width: 48, height: 48, borderRadius: 24,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.45)',
  },
  viewerBottom: { position: 'absolute', bottom: 0, left: 0, right: 0, alignItems: 'center' },
  viewerAuthor: {
    color: '#fff', fontSize: 13, fontWeight: '600',
    backgroundColor: 'rgba(0,0,0,0.45)', paddingHorizontal: 12, paddingVertical: 5,
    borderRadius: 12, marginBottom: SPACING.md,
  },
});
