import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
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
import type { MarketplaceConversation } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatRelativeFr } from '@/utils/html';

/** Rafraîchissement automatique de la discussion tant que l'écran est affiché. */
const POLL_INTERVAL_MS = 20_000;

/**
 * Discussion entre un acheteur potentiel et le vendeur à propos d'une
 * annonce de la bourse. Chaque message envoyé part aussi par e-mail à
 * l'interlocuteur, qui revient ici pour répondre.
 */
export default function MarketplaceConversationScreen() {
  const router = useRouter();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [conversation, setConversation] = useState<MarketplaceConversation | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const scrollRef = useRef<ScrollView>(null);
  const lastCount = useRef(0);
  const hasLoaded = useRef(false);

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      setLoading(false);
      return;
    }
    try {
      const resp = await marketplaceApi.conversation(id);
      setConversation(resp);
      setError(null);
      hasLoaded.current = true;
    } catch (e) {
      // Un échec de rafraîchissement automatique ne doit pas masquer une
      // discussion déjà affichée : l'erreur ne s'affiche qu'au 1er chargement.
      if (!hasLoaded.current) setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useFocusEffect(useCallback(() => {
    void load();
    const timer = setInterval(() => { void load(); }, POLL_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [load]));

  // Descend en bas à l'arrivée de nouveaux messages.
  const count = conversation?.messages.length ?? 0;
  useEffect(() => {
    if (count !== lastCount.current) {
      lastCount.current = count;
      setTimeout(() => scrollRef.current?.scrollToEnd({ animated: true }), 50);
    }
  }, [count]);

  async function send() {
    const content = draft.trim();
    if (!conversation || content === '' || sending) return;
    setSending(true);
    try {
      const resp = await marketplaceApi.sendMessage(conversation.id, content);
      setConversation((prev) => (prev ? { ...prev, messages: [...prev.messages, resp.message] } : prev));
      setDraft('');
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
      if (Platform.OS === 'web') {
        if (typeof window !== 'undefined') window.alert(msg);
      } else {
        Alert.alert('Erreur', msg);
      }
    } finally {
      setSending(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Discussion' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !conversation) {
    return (
      <>
        <Stack.Screen options={{ title: 'Discussion' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  // Une annonce en pause n'est visible que par son vendeur : pas de lien pour l'acheteur.
  const canOpenListing = !conversation.listingPaused || conversation.iAmSeller;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen
        options={{ title: `Discussion avec ${conversation.otherFirstName} pour l'annonce ${conversation.listingTitle}` }}
      />

      <Pressable
        disabled={!canOpenListing}
        onPress={() => router.push(('/marketplace/' + conversation.listingId) as never)}
        style={styles.listingBar}
      >
        <Ionicons name="pricetag-outline" size={16} color={COLORS.textMuted} />
        <Text style={styles.listingBarLabel} numberOfLines={1}>
          {conversation.iAmSeller
            ? `${conversation.otherFirstName} vous écrit à propos de « ${conversation.listingTitle} »`
            : `À propos de « ${conversation.listingTitle} »`}
        </Text>
        {canOpenListing && <Ionicons name="chevron-forward" size={16} color={COLORS.textMuted} />}
      </Pressable>

      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          ref={scrollRef}
          contentContainerStyle={styles.messages}
          keyboardShouldPersistTaps="handled"
        >
          {conversation.messages.map((m) => (
            <View key={m.id} style={[styles.bubble, m.mine ? styles.bubbleMine : styles.bubbleOther]}>
              <Text style={[styles.bubbleText, m.mine && styles.bubbleTextMine]}>{m.content}</Text>
              <Text style={[styles.bubbleTime, m.mine && styles.bubbleTimeMine]}>
                {formatRelativeFr(m.createdAt)}
              </Text>
            </View>
          ))}
        </ScrollView>

        <View style={styles.composer}>
          <TextInput
            value={draft}
            onChangeText={setDraft}
            placeholder={`Écrire à ${conversation.otherFirstName}…`}
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={2000}
            style={styles.input}
            editable={!sending}
          />
          <Pressable
            onPress={() => void send()}
            disabled={sending || draft.trim() === ''}
            style={[styles.sendBtn, (sending || draft.trim() === '') && { opacity: 0.4 }]}
          >
            {sending ? <ActivityIndicator color="#fff" /> : <Ionicons name="send" size={18} color="#fff" />}
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  listingBar: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    paddingHorizontal: SPACING.md, paddingVertical: 10,
    backgroundColor: COLORS.surface,
    borderBottomWidth: 1, borderBottomColor: COLORS.border,
  },
  listingBarLabel: { flex: 1, fontSize: 13, color: COLORS.textMuted },
  messages: {
    padding: SPACING.md, gap: 8, flexGrow: 1,
    maxWidth: 640, width: '100%', alignSelf: 'center',
  },
  bubble: {
    maxWidth: '82%', paddingHorizontal: 12, paddingVertical: 8,
    borderRadius: RADIUS.md,
  },
  bubbleMine: { alignSelf: 'flex-end', backgroundColor: COLORS.primary },
  bubbleOther: {
    alignSelf: 'flex-start', backgroundColor: COLORS.surface,
    borderWidth: 1, borderColor: COLORS.border,
  },
  bubbleText: { fontSize: 15, color: COLORS.text, lineHeight: 21 },
  bubbleTextMine: { color: '#fff' },
  bubbleTime: { fontSize: 10, color: COLORS.textMuted, marginTop: 3 },
  bubbleTimeMine: { color: 'rgba(255,255,255,0.75)' },
  composer: {
    flexDirection: 'row', alignItems: 'flex-end', gap: 8,
    padding: SPACING.sm,
    backgroundColor: COLORS.surface,
    borderTopWidth: 1, borderTopColor: COLORS.border,
  },
  input: {
    flex: 1, minHeight: 42, maxHeight: 120,
    backgroundColor: COLORS.background, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
    paddingHorizontal: 12, paddingVertical: 10,
    fontSize: 15, color: COLORS.text,
  },
  sendBtn: {
    width: 42, height: 42, borderRadius: 21,
    backgroundColor: COLORS.primary,
    alignItems: 'center', justifyContent: 'center',
  },
});
