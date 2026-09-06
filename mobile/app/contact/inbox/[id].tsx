import Ionicons from '@expo/vector-icons/Ionicons';
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

import { ApiError, auth } from '@/api/client';
import type { InboxMessage, MessageScope } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Détail d'un message reçu : affiche l'expéditeur, la portée
 * (« pour moi seul » / « tous les entraîneurs » / « le club »), le
 * message, la réponse déjà postée par un collègue (en lecture seule)
 * OU un composer pour répondre si personne n'a répondu.
 *
 * Bouton « Archiver dans ma boîte » (archivage individuel — chaque
 * destinataire d'un message multi-recipients archive indépendamment).
 */
export default function InboxDetailScreen() {
  const router = useRouter();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [msg, setMsg] = useState<InboxMessage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      return;
    }
    try {
      setError(null);
      // Pas d'endpoint GET single — on filtre depuis inbox+archived.
      const [a, b] = await Promise.all([auth.listInbox(false), auth.listInbox(true)]);
      const found = [...a.data, ...b.data].find((m) => m.id === id) ?? null;
      if (!found) {
        setError('Message introuvable ou vous n\'y avez plus accès.');
      }
      setMsg(found);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  async function submitReply() {
    const trimmed = reply.trim();
    if (trimmed === '') return;
    setBusy(true);
    try {
      const resp = await auth.replyInbox(id, trimmed);
      setMsg(resp.message);
      setReply('');
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  async function toggleArchive() {
    if (!msg) return;
    setBusy(true);
    try {
      if (msg.myArchivedAt) {
        await auth.unarchiveInbox(msg.id);
      } else {
        await auth.archiveInbox(msg.id);
      }
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Message reçu' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !msg) {
    return (
      <>
        <Stack.Screen options={{ title: 'Message reçu' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  const sent = new Date(msg.sentAt);
  const chip = scopeChipStyle(msg.scope);

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Message reçu' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={[styles.scopeChip, { backgroundColor: chip.bg }]}>
            <Text style={[styles.scopeChipLabel, { color: chip.fg }]}>{msg.scopeLabel}</Text>
          </View>

          <View style={styles.header}>
            <Ionicons name="person-circle-outline" size={40} color={COLORS.textMuted} />
            <View style={{ flex: 1 }}>
              <Text style={styles.senderName}>{msg.senderLabel}</Text>
              <Text style={styles.date}>Reçu le {formatDateLong(sent)}</Text>
            </View>
          </View>

          {msg.subject && <Text style={styles.subject}>{msg.subject}</Text>}
          <Text style={styles.body}>{msg.body}</Text>

          {msg.hasReply && msg.reply && (
            <View style={styles.replyBox}>
              <View style={styles.replyHeader}>
                <Ionicons name="arrow-undo" size={16} color={COLORS.success} />
                <Text style={styles.replyHeaderLabel}>
                  {msg.repliedByLabel ? 'Réponse de ' + msg.repliedByLabel : "Réponse envoyée"}
                  {msg.repliedAt ? ' · ' + formatDateShort(new Date(msg.repliedAt)) : ''}
                </Text>
              </View>
              <Text style={styles.replyBody}>{msg.reply}</Text>
              {!msg.canReply && msg.repliedByLabel && (
                <Text style={styles.replyLockNote}>
                  Un seul destinataire peut répondre. Vous voyez la réponse en lecture seule.
                </Text>
              )}
            </View>
          )}

          {msg.canReply && (
            <View style={styles.replyForm}>
              <Text style={styles.label}>Votre réponse</Text>
              <TextInput
                value={reply}
                onChangeText={setReply}
                placeholder="Tapez votre réponse ici…"
                placeholderTextColor={COLORS.textSubtle}
                multiline
                maxLength={5000}
                style={[styles.input, styles.textarea]}
                editable={!busy}
              />
              <Text style={styles.counter}>{reply.length} / 5000</Text>
              <Text style={styles.notice}>
                Une seule réponse est possible par message.
                {msg.scope === 'all_trainers' ? ' Vos collègues entraîneurs la verront en lecture seule.' : ''}
                {msg.scope === 'club' ? ' Les autres admins la verront en lecture seule.' : ''}
              </Text>
              <Pressable
                onPress={submitReply}
                disabled={busy || reply.trim() === ''}
                style={[styles.button, (busy || reply.trim() === '') && styles.buttonDisabled]}
              >
                {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Envoyer la réponse</Text>}
              </Pressable>
            </View>
          )}

          <View style={styles.footer}>
            <Pressable
              onPress={toggleArchive}
              disabled={busy}
              style={({ pressed }) => [styles.archiveBtn, pressed && { opacity: 0.7 }]}
            >
              <Ionicons
                name={msg.myArchivedAt ? 'arrow-undo-outline' : 'archive-outline'}
                size={18}
                color={COLORS.textMuted}
              />
              <Text style={styles.archiveBtnLabel}>
                {msg.myArchivedAt ? 'Désarchiver de ma boîte' : 'Archiver dans ma boîte'}
              </Text>
            </Pressable>
            <Pressable onPress={() => router.back()} disabled={busy} style={styles.backBtn}>
              <Text style={styles.backBtnLabel}>Retour</Text>
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function scopeChipStyle(scope: MessageScope): { bg: string; fg: string } {
  if (scope === 'all_trainers') return { bg: '#eff6ff', fg: '#1e40af' };
  if (scope === 'club') return { bg: '#fdf4ff', fg: '#7e22ce' };
  return { bg: '#ecfdf5', fg: '#047857' };
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
}

function formatDateLong(d: Date): string {
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function formatDateShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  scopeChip: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10, paddingVertical: 4,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
  },
  scopeChipLabel: { fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.5 },
  header: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: SPACING.md },
  senderName: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  date: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  subject: { fontSize: 18, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.sm },
  body: {
    fontSize: 15, color: COLORS.text, lineHeight: 22,
    backgroundColor: COLORS.surface, padding: SPACING.md, borderRadius: RADIUS.md,
  },
  replyBox: {
    marginTop: SPACING.md,
    paddingLeft: SPACING.sm,
    borderLeftWidth: 3,
    borderLeftColor: COLORS.success,
    backgroundColor: '#f0fdf4',
    padding: SPACING.md,
    borderRadius: RADIUS.sm,
  },
  replyHeader: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 6 },
  replyHeaderLabel: { fontSize: 13, color: COLORS.success, fontWeight: '700' },
  replyBody: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  replyLockNote: { fontSize: 11, color: COLORS.textMuted, fontStyle: 'italic', marginTop: 6 },
  replyForm: { marginTop: SPACING.lg },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
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
  notice: {
    fontSize: 12, color: COLORS.textMuted, fontStyle: 'italic',
    marginTop: 4, marginBottom: SPACING.sm,
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.xs,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  footer: { marginTop: SPACING.lg, gap: SPACING.xs },
  archiveBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    paddingVertical: 12, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  archiveBtnLabel: { fontSize: 13, color: COLORS.text, fontWeight: '600' },
  backBtn: { alignItems: 'center', paddingVertical: 12 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
