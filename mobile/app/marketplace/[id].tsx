import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Alert, Linking, Platform, Pressable, ScrollView, StyleSheet, Text, View, useWindowDimensions } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { marketplace as marketplaceApi } from '@/api/resources';
import type { MarketplaceListing } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { formatRelativeFr } from '@/utils/html';

/**
 * Détail d'une annonce : galerie photo, description, contact WhatsApp
 * de l'auteur — ou, si c'est la mienne, actions de gestion (modifier /
 * mettre en pause-publier / supprimer) directement sur cette page.
 */
export default function MarketplaceDetailScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const { width } = useWindowDimensions();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [listing, setListing] = useState<MarketplaceListing | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  const isMine = listing !== null && user !== null && listing.authorId === user.id;

  async function togglePause() {
    if (!listing) return;
    setBusy(true);
    try {
      const updated = listing.paused
        ? await marketplaceApi.publish(listing.id)
        : await marketplaceApi.pause(listing.id);
      setListing(updated);
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!listing) return;
    const confirmed = await confirmAsync('Supprimer cette annonce ?', 'Cette action est définitive.');
    if (!confirmed) return;
    setBusy(true);
    try {
      await marketplaceApi.remove(listing.id);
      router.replace('/marketplace' as never);
    } catch (e) {
      showError(e);
      setBusy(false);
    }
  }

  function openWhatsApp() {
    if (!listing?.authorPhone) return;
    const url = toWhatsAppLink(
      listing.authorPhone,
      `Bonjour, je suis intéressé(e) par votre annonce « ${listing.title} » sur l'app TTM.`,
    );
    Linking.openURL(url).catch(() => showError(new Error('Impossible d\'ouvrir WhatsApp.')));
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Annonce' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !listing) {
    return (
      <>
        <Stack.Screen options={{ title: 'Annonce' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: listing.title }} />
      <ScrollView contentContainerStyle={{ paddingBottom: SPACING.xl }}>
        {listing.photos.length > 0 ? (
          <ScrollView horizontal pagingEnabled showsHorizontalScrollIndicator={false} style={{ width }}>
            {listing.photos.map((p) => (
              <Image key={p.id} source={{ uri: p.url }} style={[styles.galleryImage, { width }]} contentFit="cover" />
            ))}
          </ScrollView>
        ) : (
          <View style={[styles.galleryImage, styles.galleryPlaceholder, { width }]}>
            <Ionicons name="image-outline" size={48} color={COLORS.textMuted} />
          </View>
        )}

        <View style={styles.body}>
          {listing.paused && (
            <View style={styles.pausedBanner}>
              <Ionicons name="pause-circle" size={16} color="#92400e" />
              <Text style={styles.pausedBannerLabel}>
                Annonce en pause — invisible pour le reste du club.
              </Text>
            </View>
          )}

          <Text style={styles.title}>{listing.title}</Text>
          <Text style={styles.author}>
            Par {listing.authorFullName} · {formatRelativeFr(listing.createdAt)}
          </Text>
          <Text style={styles.description}>{listing.description}</Text>

          {!isMine && (
            listing.authorPhone ? (
              <Pressable onPress={openWhatsApp} style={styles.whatsappBtn}>
                <Ionicons name="logo-whatsapp" size={20} color="#fff" />
                <Text style={styles.whatsappBtnLabel}>
                  Contacter {listing.authorFirstName} sur WhatsApp
                </Text>
              </Pressable>
            ) : (
              <Text style={styles.noPhone}>
                {listing.authorFirstName} n'a pas renseigné de numéro de téléphone —
                contact WhatsApp indisponible.
              </Text>
            )
          )}

          {isMine && (
            <View style={styles.ownerActions}>
              <Text style={styles.ownerActionsTitle}>Gérer mon annonce</Text>
              <View style={styles.ownerActionsRow}>
                <OwnerBtn
                  icon="create-outline"
                  label="Modifier"
                  onPress={() => router.push(('/marketplace/' + listing.id + '/edit') as never)}
                  disabled={busy}
                />
                <OwnerBtn
                  icon={listing.paused ? 'play-outline' : 'pause-outline'}
                  label={listing.paused ? 'Publier' : 'Pause'}
                  onPress={() => void togglePause()}
                  disabled={busy}
                />
                <OwnerBtn
                  icon="trash-outline"
                  label="Supprimer"
                  onPress={() => void remove()}
                  disabled={busy}
                  danger
                />
              </View>
            </View>
          )}

          <Pressable onPress={goBack} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Retour</Text>
          </Pressable>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function OwnerBtn({ icon, label, onPress, disabled, danger }: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  disabled?: boolean;
  danger?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.ownerBtn, pressed && { opacity: 0.6 }, disabled && { opacity: 0.4 }]}
    >
      <Ionicons name={icon} size={18} color={danger ? COLORS.error : COLORS.text} />
      <Text style={[styles.ownerBtnLabel, danger && { color: COLORS.error }]}>{label}</Text>
    </Pressable>
  );
}

/**
 * Convertit un numéro français local (« 0612345678 ») en lien wa.me.
 * Les numéros déjà en international (+33…) sont laissés tels quels.
 */
function toWhatsAppLink(phone: string, message: string): string {
  const digits = phone.replace(/[^\d+]/g, '');
  const intl = digits.startsWith('+')
    ? digits.slice(1)
    : digits.startsWith('0')
      ? '33' + digits.slice(1)
      : digits;
  return `https://wa.me/${intl}?text=${encodeURIComponent(message)}`;
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : (e instanceof Error ? e.message : 'Erreur inattendue.');
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
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
  galleryImage: { height: 280, backgroundColor: COLORS.surface },
  galleryPlaceholder: { alignItems: 'center', justifyContent: 'center' },
  body: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  pausedBanner: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: '#fef3c7', borderRadius: RADIUS.sm,
    padding: 10, marginBottom: SPACING.md,
  },
  pausedBannerLabel: { color: '#92400e', fontSize: 13, flex: 1 },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text },
  author: { fontSize: 13, color: COLORS.textMuted, marginTop: 4, marginBottom: SPACING.md },
  description: { fontSize: 15, color: COLORS.text, lineHeight: 22 },
  whatsappBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10,
    backgroundColor: '#25D366', borderRadius: RADIUS.md,
    paddingVertical: 14, marginTop: SPACING.lg,
  },
  whatsappBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  noPhone: {
    marginTop: SPACING.lg, fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic',
    backgroundColor: COLORS.surface, padding: 12, borderRadius: RADIUS.sm,
  },
  ownerActions: {
    marginTop: SPACING.lg, backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md, padding: SPACING.md,
  },
  ownerActionsTitle: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm },
  ownerActionsRow: { flexDirection: 'row', justifyContent: 'space-around' },
  ownerBtn: { alignItems: 'center', gap: 4, paddingVertical: 6, paddingHorizontal: 8 },
  ownerBtnLabel: { fontSize: 12, fontWeight: '600', color: COLORS.text },
  backBtn: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.md },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
