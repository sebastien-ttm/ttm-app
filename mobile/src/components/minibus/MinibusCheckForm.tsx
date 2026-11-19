import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { useState } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { minibus as minibusApi } from '@/api/resources';
import type { MinibusBookingDetail, MinibusCheck, MinibusCheckType } from '@/api/types';
import { CheckRow } from '@/components/minibus/CheckRow';
import { COLORS, RADIUS, SPACING } from '@/config';
import { pickReducedPhotos, takeReducedPhoto, type PickedPhoto } from '@/lib/marketplacePhotos';

/** Photos par état des lieux (le même plafond que le serveur : MinibusPhotoService::MAX_PHOTOS_PER_CHECK). */
export const MINIBUS_MAX_PHOTOS = 12;
/** Plus grand côté des photos envoyées : assez fin pour distinguer une rayure. */
const PHOTO_SIDE = 1600;

const GUIDE: Record<MinibusCheckType, string> = {
  departure:
    'Avant de prendre la route, faites un tour complet du minibus et de la remorque et photographiez-les : avant, arrière, côtés, intérieur, remorque. Photographiez aussi les dégâts déjà présents.',
  return:
    'Au retour : refaites le plein du minibus, nettoyez-le et videz-le de tout détritus, puis photographiez-le : extérieur, intérieur, remorque.',
};

/**
 * État des lieux du minibus, rempli par l'emprunteur : photos (appareil photo ou galerie), kilométrage, au retour le plein
 * et le nettoyage, comment cela s'est passé et problèmes éventuels. Il remplace le formulaire Google des fiches du minibus.
 * Renvoyé sur un état des lieux existant, il le complète : les nouvelles photos s'ajoutent, le texte est remplacé.
 */
export function MinibusCheckForm({ bookingId, type, existing, onSaved, onCancel }: {
  bookingId: number;
  type: MinibusCheckType;
  existing: MinibusCheck | null;
  onSaved: (booking: MinibusBookingDetail) => void;
  onCancel?: () => void;
}) {
  const [photos, setPhotos] = useState<PickedPhoto[]>([]);
  const [mileage, setMileage] = useState(existing?.mileage != null ? String(existing.mileage) : '');
  const [comment, setComment] = useState(existing?.comment ?? '');
  const [hasProblem, setHasProblem] = useState(existing?.hasProblem ?? false);
  const [problem, setProblem] = useState(existing?.problemDescription ?? '');
  const [refueled, setRefueled] = useState(existing?.refueled ?? false);
  const [cleaned, setCleaned] = useState(existing?.cleaned ?? false);
  const [preparing, setPreparing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const already = existing?.photos.length ?? 0;
  const room = MINIBUS_MAX_PHOTOS - already - photos.length;
  const totalPhotos = already + photos.length;

  async function guard(task: () => Promise<void>) {
    if (preparing || busy) return;
    setPreparing(true);
    try {
      await task();
    } finally {
      setPreparing(false);
    }
  }

  const takePhoto = () => guard(async () => {
    if (room <= 0) return;
    const photo = await takeReducedPhoto(PHOTO_SIDE);
    if (photo) setPhotos((prev) => [...prev, photo]);
  });

  const pickPhotos = () => guard(async () => {
    if (room <= 0) return;
    const picked = await pickReducedPhotos(room, PHOTO_SIDE);
    if (picked.length > 0) setPhotos((prev) => [...prev, ...picked].slice(0, MINIBUS_MAX_PHOTOS - already));
  });

  async function submit() {
    setError(null);
    if (totalPhotos < 1) {
      setError('Ajoutez au moins une photo.');
      return;
    }
    if (hasProblem && problem.trim() === '') {
      setError('Décrivez le problème rencontré.');
      return;
    }
    if (mileage.trim() !== '' && !/^\d{1,7}$/.test(mileage.trim())) {
      setError('Kilométrage : indiquez un nombre entier.');
      return;
    }
    setBusy(true);
    try {
      const updated = await minibusApi.submitCheck(
        bookingId,
        type,
        { comment: comment.trim(), mileage, hasProblem, problemDescription: problem.trim(), refueled, cleaned },
        photos,
      );
      onSaved(updated);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  const isReturn = type === 'return';
  return (
    <View style={styles.form}>
      <Text style={styles.guide}>{GUIDE[type]}</Text>

      <Text style={styles.label}>Photos ({totalPhotos} / {MINIBUS_MAX_PHOTOS})</Text>
      <View style={styles.photoRow}>
        {photos.map((p, i) => (
          <View key={p.uri + i} style={styles.thumbWrap}>
            <Image source={{ uri: p.uri }} style={styles.thumb} contentFit="cover" />
            <Pressable onPress={() => setPhotos((prev) => prev.filter((_, j) => j !== i))} disabled={busy} style={styles.thumbRemove} accessibilityLabel="Retirer la photo">
              <Ionicons name="close" size={14} color="#fff" />
            </Pressable>
          </View>
        ))}
      </View>
      <View style={styles.photoButtons}>
        <Pressable onPress={takePhoto} disabled={busy || preparing || room <= 0} style={[styles.photoBtn, (busy || preparing || room <= 0) && { opacity: 0.5 }]}>
          {preparing ? <ActivityIndicator color={COLORS.secondary} /> : <Ionicons name="camera" size={18} color={COLORS.secondary} />}
          <Text style={styles.photoBtnLabel}>{Platform.OS === 'web' ? 'Prendre une photo' : 'Appareil photo'}</Text>
        </Pressable>
        <Pressable onPress={pickPhotos} disabled={busy || preparing || room <= 0} style={[styles.photoBtn, (busy || preparing || room <= 0) && { opacity: 0.5 }]}>
          <Ionicons name="images" size={18} color={COLORS.secondary} />
          <Text style={styles.photoBtnLabel}>Galerie</Text>
        </Pressable>
      </View>

      <Text style={styles.label}>Kilométrage au compteur (facultatif)</Text>
      <TextInput
        value={mileage}
        onChangeText={setMileage}
        placeholder="Ex : 48210"
        placeholderTextColor={COLORS.textSubtle}
        keyboardType="number-pad"
        maxLength={7}
        style={[styles.input, { maxWidth: 200 }]}
        editable={!busy}
      />

      {isReturn && (
        <View style={{ gap: 8, marginTop: SPACING.md }}>
          <CheckRow label="J'ai refait le plein du minibus" checked={refueled} onChange={setRefueled} disabled={busy} />
          <CheckRow label="J'ai nettoyé le minibus et l'ai vidé de tout détritus" checked={cleaned} onChange={setCleaned} disabled={busy} />
        </View>
      )}

      <Text style={styles.label}>Comment cela s'est passé ?</Text>
      <TextInput
        value={comment}
        onChangeText={setComment}
        placeholder={isReturn ? 'Déroulement de l\'emprunt, remarques pour le prochain conducteur…' : 'Remarques sur l\'état du minibus au départ…'}
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={3000}
        style={[styles.input, styles.textarea]}
        editable={!busy}
      />

      <View style={{ marginTop: SPACING.md }}>
        <CheckRow label="J'ai un problème à signaler" hint="Dégât, panne, incident, objet oublié…" checked={hasProblem} onChange={setHasProblem} disabled={busy} />
      </View>
      {hasProblem && (
        <TextInput
          value={problem}
          onChangeText={setProblem}
          placeholder="Décrivez le problème (où, quand, gravité)"
          placeholderTextColor={COLORS.textSubtle}
          multiline
          maxLength={3000}
          style={[styles.input, styles.textarea, { marginTop: 8 }]}
          editable={!busy}
        />
      )}

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable onPress={submit} disabled={busy || preparing} style={[styles.submit, (busy || preparing) && { opacity: 0.5 }]}>
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>{existing ? 'Enregistrer les modifications' : 'Envoyer l\'état des lieux'}</Text>}
      </Pressable>
      {onCancel && (
        <Pressable onPress={onCancel} disabled={busy} style={styles.cancel}>
          <Text style={styles.cancelLabel}>Annuler</Text>
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  form: { gap: 2 },
  guide: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.md },
  photoRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  thumbWrap: { position: 'relative' },
  thumb: { width: 76, height: 76, borderRadius: RADIUS.sm, backgroundColor: COLORS.surface },
  thumbRemove: {
    position: 'absolute', top: -6, right: -6, backgroundColor: COLORS.error, borderRadius: 10,
    width: 20, height: 20, alignItems: 'center', justifyContent: 'center',
  },
  photoButtons: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8 },
  photoBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 12, paddingVertical: 9,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.secondary, backgroundColor: COLORS.surface,
  },
  photoBtnLabel: { color: COLORS.secondary, fontWeight: '700', fontSize: 13 },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 90, textAlignVertical: 'top' },
  error: {
    color: COLORS.error, backgroundColor: '#fee2e2', padding: 12, borderRadius: RADIUS.sm,
    marginTop: SPACING.md, fontSize: 13, fontWeight: '500',
  },
  submit: { backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 14, alignItems: 'center', marginTop: SPACING.lg },
  submitLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  cancel: { alignItems: 'center', paddingVertical: 12 },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
