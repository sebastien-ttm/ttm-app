import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, auth } from '@/api/client';
import type { MessageScope, UserMessage } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MessageThread } from '@/components/MessageThread';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { useUnreadMessages } from '@/lib/useUnreadMessages';

/**
 * Détail d'un message que J'AI envoyé : destinataire, la réponse déjà
 * postée (en lecture seule) le cas échéant, puis la suite de la
 * conversation (MessageThread) une fois `canThreadReply` vrai.
 */
export default function SentDetailScreen() {
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [msg, setMsg] = useState<UserMessage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { refresh: refreshUnread } = useUnreadMessages();

  const load = useCallback(async () => {
    if (!id) {
      setError('Identifiant invalide.');
      return;
    }
    try {
      setError(null);
      // Pas d'endpoint GET single — on filtre depuis mes envoyés+archivés.
      const [a, b] = await Promise.all([auth.listMessages(false), auth.listMessages(true)]);
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

  async function submitThreadReply(content: string) {
    const resp = await auth.threadReply(id, content);
    setMsg((prev) => (prev ? { ...prev, thread: [...prev.thread, resp.entry] } : prev));
    void refreshUnread();
    return resp.entry;
  }

  async function toggleArchive() {
    if (!msg) return;
    setBusy(true);
    try {
      if (msg.senderArchivedAt) {
        await auth.unarchiveSentMessage(msg.id);
      } else {
        await auth.archiveSentMessage(msg.id);
      }
      await load();
      void refreshUnread();
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Message envoyé' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !msg) {
    return (
      <>
        <Stack.Screen options={{ title: 'Message envoyé' }} />
        <ErrorState message={error ?? 'Introuvable.'} onRetry={load} />
      </>
    );
  }

  const sent = new Date(msg.sentAt);
  const chip = scopeChipStyle(msg.scope);

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Message envoyé' }} />
      <ScrollView contentContainerStyle={styles.content}>
        <View style={[styles.scopeChip, { backgroundColor: chip.bg }]}>
          <Text style={[styles.scopeChipLabel, { color: chip.fg }]}>{msg.recipientLabel}</Text>
        </View>

        <View style={styles.header}>
          <Ionicons name="paper-plane-outline" size={32} color={COLORS.textMuted} />
          <View style={{ flex: 1 }}>
            <Text style={styles.senderName}>À {msg.recipientLabel}</Text>
            <Text style={styles.date}>Envoyé le {formatDateLong(sent)}</Text>
          </View>
        </View>

        {msg.category !== 'general' && (
          <View style={styles.categoryChip}>
            <Text style={styles.categoryChipLabel}>
              {msg.categoryIcon} {msg.categoryLabel}
            </Text>
          </View>
        )}
        {msg.subject && <Text style={styles.subject}>{msg.subject}</Text>}
        <Text style={styles.body}>{msg.body}</Text>

        {msg.hasReply && msg.reply ? (
          <View style={styles.replyBox}>
            <View style={styles.replyHeader}>
              <Ionicons name="arrow-undo" size={16} color={COLORS.success} />
              <Text style={styles.replyHeaderLabel}>
                {msg.repliedByLabel ? 'Réponse de ' + msg.repliedByLabel : 'Réponse envoyée'}
                {msg.repliedAt ? ' · ' + formatDateShort(new Date(msg.repliedAt)) : ''}
              </Text>
            </View>
            <Text style={styles.replyBody}>{msg.reply}</Text>
          </View>
        ) : (
          <Text style={styles.pending}>En attente d'une réponse…</Text>
        )}

        <MessageThread
          thread={msg.thread}
          currentUserId={user?.id ?? null}
          canReply={msg.canThreadReply}
          onSubmit={submitThreadReply}
        />

        <View style={styles.footer}>
          <Pressable
            onPress={toggleArchive}
            disabled={busy}
            style={({ pressed }) => [styles.archiveBtn, pressed && { opacity: 0.7 }]}
          >
            <Ionicons
              name={msg.senderArchivedAt ? 'arrow-undo-outline' : 'archive-outline'}
              size={18}
              color={COLORS.textMuted}
            />
            <Text style={styles.archiveBtnLabel}>
              {msg.senderArchivedAt ? 'Désarchiver' : 'Archiver'}
            </Text>
          </Pressable>
          <Pressable onPress={goBack} disabled={busy} style={styles.backBtn}>
            <Text style={styles.backBtnLabel}>Retour</Text>
          </Pressable>
        </View>
      </ScrollView>
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
  categoryChip: {
    alignSelf: 'flex-start',
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#fde68a',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    marginBottom: 8,
  },
  categoryChipLabel: { fontSize: 12, fontWeight: '700', color: '#92400e' },
  subject: { fontSize: 18, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.sm },
  body: {
    fontSize: 15, color: COLORS.text, lineHeight: 22,
    backgroundColor: COLORS.surface, padding: SPACING.md, borderRadius: RADIUS.md,
  },
  pending: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic', marginTop: SPACING.sm },
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
