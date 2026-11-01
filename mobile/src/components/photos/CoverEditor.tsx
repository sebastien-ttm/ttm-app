import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  type GestureResponderEvent,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  useWindowDimensions,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { PhotoImage } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Rapport largeur / hauteur de la vignette d'un album dans la liste (photos/index.tsx : hauteur = 0,75 × largeur). */
const COVER_RATIO = 4 / 3;
const PREVIEW_MAX_HEIGHT = 300;
const THUMB = 64;

/** Part de la photo (largeur, hauteur : 0 à 1) visible dans la vignette quand elle y est affichée en « cover ». */
function visibleShare(imageRatio: number) {
  return { w: Math.min(1, COVER_RATIO / imageRatio), h: Math.min(1, imageRatio / COVER_RATIO) };
}

const clamp = (v: number, min: number, max: number) => Math.max(min, Math.min(max, v));

/**
 * Photo qui représente un album dans la liste « Photos du club » : choix parmi les photos de l'album, puis cadrage — on
 * touche ou on fait glisser la fenêtre sur la photo entière pour choisir la partie qui restera visible dans la vignette.
 * Le cadrage est enregistré en % (50 / 50 = centrée), comme la propriété CSS `object-position`.
 */
export function CoverEditor({ visible, images, total, coverImageId, focusX, focusY, url, onClose, onSave }: {
  visible: boolean;
  /** Photos de l'album déjà chargées (les plus récentes d'abord). */
  images: PhotoImage[];
  total: number;
  coverImageId: number | null;
  focusX: number;
  focusY: number;
  url: (id: number, variant: 'grid' | 'full') => string | null;
  onClose: () => void;
  /** imageId null = laisser Piwigo choisir. Rejette avec un message en cas d'échec. */
  onSave: (imageId: number | null, focusX: number, focusY: number) => Promise<void>;
}) {
  const { width: screenWidth } = useWindowDimensions();
  const [selected, setSelected] = useState<number | null>(coverImageId);
  const [fx, setFx] = useState(focusX);
  const [fy, setFy] = useState(focusY);
  const [dragging, setDragging] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // À chaque ouverture : repartir de la couverture actuelle.
  useEffect(() => {
    if (!visible) return;
    setSelected(coverImageId ?? images[0]?.id ?? null);
    setFx(focusX);
    setFy(focusY);
    setError(null);
    // Seule l'ouverture réinitialise : les photos qui se chargent ensuite ne doivent pas écraser le choix en cours.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  const image = images.find((i) => i.id === selected) ?? null;
  const imageRatio = image?.width && image?.height ? image.width / image.height : 3 / 2;
  const share = visibleShare(imageRatio);

  const contentWidth = Math.min(screenWidth, 520) - SPACING.md * 2;
  const boxW = Math.min(contentWidth, PREVIEW_MAX_HEIGHT * imageRatio);
  const boxH = boxW / imageRatio;

  // Fenêtre de cadrage : la partie de la photo que montre la vignette.
  const winW = share.w * boxW;
  const winH = share.h * boxH;
  const winX = (fx / 100) * (boxW - winW);
  const winY = (fy / 100) * (boxH - winH);

  function select(id: number) {
    if (id === selected) return;
    setSelected(id);
    setFx(50);
    setFy(50);
  }

  /** Centre la fenêtre sur le point touché (dans les limites de la photo). */
  function moveTo(e: GestureResponderEvent) {
    const { locationX, locationY } = e.nativeEvent;
    const slackX = 1 - share.w;
    const slackY = 1 - share.h;
    if (slackX > 0.001) setFx(clamp((((locationX / boxW) - share.w / 2) / slackX) * 100, 0, 100));
    if (slackY > 0.001) setFy(clamp((((locationY / boxH) - share.h / 2) / slackY) * 100, 0, 100));
  }

  async function save(imageId: number | null) {
    setSaving(true);
    setError(null);
    try {
      await onSave(imageId, Math.round(fx), Math.round(fy));
    } catch (e) {
      setError(typeof e === 'string' ? e : e instanceof Error ? e.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  }

  const src = selected !== null ? url(selected, 'grid') : null;
  const noSlack = share.w >= 0.999 && share.h >= 0.999;

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={styles.container} edges={['top', 'bottom']}>
        <View style={styles.header}>
          <Text style={styles.title}>Photo de l'album</Text>
          <Pressable onPress={onClose} hitSlop={10} accessibilityLabel="Fermer">
            <Ionicons name="close" size={26} color={COLORS.text} />
          </Pressable>
        </View>

        <ScrollView
          contentContainerStyle={styles.content}
          scrollEnabled={!dragging}
          showsVerticalScrollIndicator={false}
        >
          {images.length === 0 ? (
            <Text style={styles.hint}>Cet album ne contient pas encore de photo.</Text>
          ) : (
            <>
              <Text style={styles.label}>Cadrage</Text>
              <Text style={styles.hint}>
                {noSlack
                  ? 'Cette photo est affichée en entier dans la vignette.'
                  : 'Touchez ou faites glisser sur la photo : le cadre clair est la partie visible dans la liste des albums.'}
              </Text>

              <View
                style={[styles.box, { width: boxW, height: boxH }, Platform.OS === 'web' ? ({ touchAction: 'none', cursor: 'crosshair' } as object) : null]}
                onStartShouldSetResponder={() => !noSlack}
                onMoveShouldSetResponder={() => !noSlack}
                onResponderTerminationRequest={() => false}
                onResponderGrant={(e) => { setDragging(true); moveTo(e); }}
                onResponderMove={moveTo}
                onResponderRelease={() => setDragging(false)}
                onResponderTerminate={() => setDragging(false)}
              >
                <View style={StyleSheet.absoluteFill} pointerEvents="none">
                  {src ? (
                    <Image source={{ uri: src }} style={StyleSheet.absoluteFill} contentFit="fill" />
                  ) : (
                    <View style={[StyleSheet.absoluteFill, { backgroundColor: COLORS.border }]} />
                  )}
                  {!noSlack && (
                    <>
                      <View style={[styles.dim, { top: 0, left: 0, right: 0, height: winY }]} />
                      <View style={[styles.dim, { top: winY + winH, left: 0, right: 0, bottom: 0 }]} />
                      <View style={[styles.dim, { top: winY, left: 0, width: winX, height: winH }]} />
                      <View style={[styles.dim, { top: winY, left: winX + winW, right: 0, height: winH }]} />
                      <View style={[styles.window, { top: winY, left: winX, width: winW, height: winH }]} />
                    </>
                  )}
                </View>
              </View>

              <Text style={[styles.label, { marginTop: SPACING.md }]}>Aperçu dans la liste</Text>
              <View style={styles.previewCard}>
                {src ? (
                  <Image
                    source={{ uri: src }}
                    style={styles.previewCover}
                    contentFit="cover"
                    contentPosition={{ left: `${Math.round(fx)}%`, top: `${Math.round(fy)}%` }}
                  />
                ) : (
                  <View style={[styles.previewCover, { backgroundColor: COLORS.border }]} />
                )}
              </View>

              <Text style={[styles.label, { marginTop: SPACING.md }]}>Choisir la photo</Text>
              <View style={styles.thumbs}>
                {images.map((img) => {
                  const thumb = url(img.id, 'grid');
                  const active = img.id === selected;
                  return (
                    <Pressable
                      key={img.id}
                      onPress={() => select(img.id)}
                      style={[styles.thumb, active && styles.thumbActive]}
                      accessibilityRole="button"
                      accessibilityState={{ selected: active }}
                    >
                      {thumb ? <Image source={{ uri: thumb }} style={styles.thumbImg} contentFit="cover" recyclingKey={String(img.id)} /> : null}
                    </Pressable>
                  );
                })}
              </View>
              {total > images.length && (
                <Text style={styles.hint}>
                  {images.length} photo{images.length > 1 ? 's' : ''} sur {total} proposées : faites défiler l'album pour en charger d'autres.
                </Text>
              )}
            </>
          )}
        </ScrollView>

        <View style={styles.footer}>
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <Pressable
            onPress={() => void save(selected)}
            disabled={saving || selected === null}
            style={[styles.saveBtn, (saving || selected === null) && { opacity: 0.5 }]}
          >
            {saving ? <ActivityIndicator color="#fff" /> : <Text style={styles.saveLabel}>Enregistrer</Text>}
          </Pressable>
          <Pressable onPress={() => void save(null)} disabled={saving} style={styles.resetBtn}>
            <Text style={styles.resetLabel}>Laisser le choix automatique</Text>
          </Pressable>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  header: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingHorizontal: SPACING.md, paddingVertical: SPACING.sm,
    borderBottomWidth: 1, borderBottomColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  title: { fontSize: 17, fontWeight: '700', color: COLORS.text },
  content: { padding: SPACING.md, width: '100%', maxWidth: 520, alignSelf: 'center', gap: 6 },
  label: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  hint: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17 },
  box: {
    alignSelf: 'center', marginTop: SPACING.xs, overflow: 'hidden',
    borderRadius: RADIUS.sm, backgroundColor: COLORS.border,
  },
  dim: { position: 'absolute', backgroundColor: 'rgba(0,0,0,0.55)' },
  window: { position: 'absolute', borderWidth: 2, borderColor: '#fff' },
  previewCard: {
    width: 160, borderRadius: RADIUS.md, overflow: 'hidden',
    borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  previewCover: { width: '100%', height: 120, backgroundColor: COLORS.background },
  thumbs: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  thumb: {
    width: THUMB, height: THUMB, borderRadius: RADIUS.sm, overflow: 'hidden',
    borderWidth: 3, borderColor: 'transparent', backgroundColor: COLORS.border,
  },
  thumbActive: { borderColor: COLORS.primary },
  thumbImg: { width: '100%', height: '100%' },
  error: { color: COLORS.error, fontSize: 13, textAlign: 'center' },
  footer: {
    padding: SPACING.md, gap: SPACING.sm, width: '100%', maxWidth: 520, alignSelf: 'center',
    borderTopWidth: 1, borderTopColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  saveBtn: {
    alignItems: 'center', justifyContent: 'center', minHeight: 46,
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md,
  },
  saveLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  resetBtn: { alignItems: 'center', paddingVertical: 6 },
  resetLabel: { color: COLORS.textMuted, fontSize: 13, fontWeight: '500' },
});
