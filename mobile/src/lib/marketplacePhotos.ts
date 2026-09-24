import * as ImageManipulator from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { Alert, Platform } from 'react-native';

export type PickedPhoto = { uri: string; mimeType: string; name: string };

/** Plus grand côté des photos envoyées — suffisant pour un écran de téléphone. */
const MAX_SIDE = 1280;
/** Qualité JPEG après réduction (0-1). */
const JPEG_QUALITY = 0.8;

/**
 * Ouvre la galerie (sélection multiple) et renvoie les photos DÉJÀ
 * RÉDUITES : plus grand côté ≤ 1280 px, JPEG qualité 0.8. Une photo de
 * téléphone brute pèse 3-8 Mo ; réduite ici, elle tombe à ~200-400 Ko,
 * ce qui allège l'envoi (5 photos passent sans souci les limites PHP) et
 * l'espace disque du serveur. Le serveur re-vérifie et réduit aussi de
 * son côté (voir ImageResizer::compressToJpeg) — cette étape sert
 * surtout à ne pas envoyer des dizaines de Mo inutiles.
 */
export async function pickReducedPhotos(limit: number): Promise<PickedPhoto[]> {
  if (limit <= 0) return [];
  if (Platform.OS !== 'web') {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permission refusée', 'Autorise l\'accès aux photos dans les réglages.');
      return [];
    }
  }
  const result = await ImagePicker.launchImageLibraryAsync({
    mediaTypes: ImagePicker.MediaTypeOptions.Images,
    allowsMultipleSelection: true,
    selectionLimit: limit,
    // Qualité maximale ici : la compression se fait une seule fois, plus bas.
    quality: 1,
  });
  if (result.canceled || result.assets.length === 0) return [];

  const out: PickedPhoto[] = [];
  const stamp = Date.now();
  for (const [i, asset] of result.assets.slice(0, limit).entries()) {
    out.push(await reduce(asset, `photo-${stamp}-${i}`));
  }
  return out;
}

async function reduce(asset: ImagePicker.ImagePickerAsset, baseName: string): Promise<PickedPhoto> {
  const longest = Math.max(asset.width || 0, asset.height || 0);
  const actions: ImageManipulator.Action[] = longest > MAX_SIDE
    ? [{ resize: asset.width >= asset.height ? { width: MAX_SIDE } : { height: MAX_SIDE } }]
    : [];
  try {
    const r = await ImageManipulator.manipulateAsync(asset.uri, actions, {
      compress: JPEG_QUALITY,
      format: ImageManipulator.SaveFormat.JPEG,
    });
    return { uri: r.uri, mimeType: 'image/jpeg', name: baseName + '.jpg' };
  } catch {
    // Réduction impossible (format exotique…) : on envoie l'original, le
    // serveur se chargera de la réduction.
    const mime = asset.mimeType ?? 'image/jpeg';
    return { uri: asset.uri, mimeType: mime, name: `${baseName}.${mime.split('/')[1] ?? 'jpg'}` };
  }
}
