import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
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
import { bibs as bibsApi } from '@/api/resources';
import type { BibOffer } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { bibPriceLabel } from '@/components/BibForm';
import { formatIsoDate } from '@/components/DateField';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { formatRelativeFr } from '@/utils/html';

/**
 * Détail d'une offre de dossards : course, date, nombre, don / prix,
 * précisions, contact de l'auteur par la messagerie de la bourse — ou,
 * si c'est la mienne, modifier / pause-publier / supprimer.
 */
export default function BibDetailScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [offer, setOffer] = useState<BibOffer | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      setLoading(false);
      return;
    }
    try {
      setError(null);
      setOffer(await bibsApi.get(id));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  const isMine = offer !== null && user !== null && offer.authorId === user.id;

  async function togglePause() {
    if (!offer) return;
    setBusy(true);
    try {
      const updated = offer.paused ? await bibsApi.publish(offer.id) : await bibsApi.pause(offer.id);
      setOffer({ ...updated, myConversationId: offer.myConversationId });
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!offer) return;
    const confirmed = await confirmAsync('Supprimer cette offre ?', 'Cette action est définitive.');
    if (!confirmed) return;
    setBusy(true);
    try {
      await bibsApi.remove(offer.id);
      router.replace('/bibs' as never);
    } catch (e) {
      showError(e);
      setBusy(false);
    }
  }

  async function startConversation() {
    if (!offer) return;
    const content = draft.trim();
    if (content === '') return;
    setSending(true);
    try {
      const conversation = await bibsApi.startConversation(offer.id, content);
      setDraft('');
      setOffer({ ...offer, myConversationId: conversation.id });
      router.push(('/marketplace/conversation/' + conversation.id) as never);
    } catch (e) {
      showError(e);
    } finally {
      setSending(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Dossards' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !offer) {
    return (
      <>
        <Stack.Screen options={{ title: 'Dossards' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  const isDon = offer.exchangeType === 'don';
  const plural = offer.quantity > 1;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Dossard ' + offer.raceName }} />
      <ScrollView contentContainerStyle={{ paddingBottom: SPACING.xl }}>
        <View style={[styles.hero, { backgroundColor: isDon ? '#16a34a' : '#1d4ed8' }]}>
          <Text style={styles.heroEmoji}>🎫</Text>
          <Text style={styles.heroCount}>
            {offer.quantity} dossard{plural ? 's' : ''} disponible{plural ? 's' : ''}
          </Text>
          <Text style={styles.heroExchange}>{isDon ? 'Don' : 'Revente'}</Text>
        </View>

        <View style={styles.body}>
          {offer.paused && (
            <View style={styles.pausedBanner}>
              <Ionicons name="pause-circle" size={16} color="#92400e" />
              <Text style={styles.pausedBannerLabel}>Offre en pause — invisible pour le reste du club.</Text>
            </View>
          )}

          <Text style={styles.title}>{offer.raceName}</Text>
          <View style={styles.dateRow}>
            <Ionicons name="calendar-outline" size={16} color={COLORS.text} />
            <Text style={styles.date}>{formatIsoDate(offer.raceDate)}</Text>
          </View>
          <Text style={styles.author}>
            Proposé par {offer.authorFullName} · {formatRelativeFr(offer.createdAt)}
          </Text>

          <View style={[styles.priceBox, isDon ? styles.priceBoxDon : styles.priceBoxSale]}>
            <Text style={[styles.priceLabel, { color: isDon ? '#15803d' : '#1e40af' }]}>{bibPriceLabel(offer)}</Text>
          </View>

          {offer.description && <Text style={styles.description}>{offer.description}</Text>}

          {!isMine && offer.myConversationId != null && (
            <View style={styles.contactBox}>
              <Text style={styles.contactTitle}>Discussion en cours avec {offer.authorFirstName}</Text>
              <Pressable
                onPress={() => router.push(('/marketplace/conversation/' + offer.myConversationId) as never)}
                style={styles.contactBtn}
              >
                <Ionicons name="chatbubbles-outline" size={18} color="#fff" />
                <Text style={styles.contactBtnLabel}>Voir la discussion</Text>
              </Pressable>
            </View>
          )}

          {!isMine && offer.myConversationId == null && (
            <View style={styles.contactBox}>
              <Text style={styles.contactTitle}>Intéressé(e) ? Écrivez à {offer.authorFirstName}</Text>
              <Text style={styles.contactHint}>
                {offer.authorFirstName} reçoit votre message dans l'application et par e-mail,
                et pourra vous répondre ici.
              </Text>
              <TextInput
                value={draft}
                onChangeText={setDraft}
                placeholder={plural ? 'Bonjour, je suis intéressé(e) par un dossard…' : 'Bonjour, votre dossard m\'intéresse…'}
                placeholderTextColor={COLORS.textSubtle}
                multiline
                maxLength={2000}
                style={styles.contactInput}
                editable={!sending}
              />
              <Pressable
                onPress={() => void startConversation()}
                disabled={sending || draft.trim() === ''}
                style={[styles.contactBtn, (sending || draft.trim() === '') && { opacity: 0.4 }]}
              >
                {sending ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <>
                    <Ionicons name="send" size={16} color="#fff" />
                    <Text style={styles.contactBtnLabel}>Envoyer le message</Text>
                  </>
                )}
              </Pressable>
            </View>
          )}

          {isMine && (
            <View style={styles.ownerActions}>
              <Text style={styles.ownerActionsTitle}>Gérer mon offre</Text>
              <View style={styles.ownerActionsRow}>
                {!offer.past && (
                  <OwnerBtn
                    icon="create-outline"
                    label="Modifier"
                    onPress={() => router.push(('/bibs/' + offer.id + '/edit') as never)}
                    disabled={busy}
                  />
                )}
                {!offer.past && (
                  <OwnerBtn
                    icon={offer.paused ? 'play-outline' : 'pause-outline'}
                    label={offer.paused ? 'Publier' : 'Pause'}
                    onPress={() => void togglePause()}
                    disabled={busy}
                  />
                )}
                <OwnerBtn icon="trash-outline" label="Supprimer" onPress={() => void remove()} disabled={busy} danger />
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
  hero: { height: 150, alignItems: 'center', justifyContent: 'center', gap: 2 },
  heroEmoji: { fontSize: 48 },
  heroCount: { color: '#fff', fontSize: 18, fontWeight: '800' },
  heroExchange: { color: 'rgba(255,255,255,0.9)', fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.6 },
  body: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  pausedBanner: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: '#fef3c7', borderRadius: RADIUS.sm,
    padding: 10, marginBottom: SPACING.md,
  },
  pausedBannerLabel: { color: '#92400e', fontSize: 13, flex: 1 },
  title: { fontSize: 20, fontWeight: '700', color: COLORS.text },
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 6 },
  date: { fontSize: 15, fontWeight: '600', color: COLORS.text },
  author: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  priceBox: { marginTop: SPACING.md, padding: SPACING.md, borderRadius: RADIUS.md, alignItems: 'center' },
  priceBoxDon: { backgroundColor: '#dcfce7' },
  priceBoxSale: { backgroundColor: '#dbeafe' },
  priceLabel: { fontSize: 17, fontWeight: '800' },
  description: { fontSize: 15, color: COLORS.text, lineHeight: 22, marginTop: SPACING.md },
  contactBox: {
    marginTop: SPACING.lg, backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md, padding: SPACING.md,
  },
  contactTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text, marginBottom: 4 },
  contactHint: { fontSize: 12, color: COLORS.textMuted, marginBottom: SPACING.sm },
  contactInput: {
    backgroundColor: COLORS.background, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
    paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text,
    minHeight: 90, textAlignVertical: 'top',
  },
  contactBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md,
    paddingVertical: 13, marginTop: SPACING.sm,
  },
  contactBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
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
