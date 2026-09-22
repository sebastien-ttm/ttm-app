import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { ThreadEntry } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Suite de conversation au-delà du 2e échange verrouillé (body → reply)
 * d'un UserMessage. Affiche chaque tour en bulle chat (les miens à
 * droite, ceux de l'autre partie à gauche) puis, si `canReply`, un
 * composer permanent — sans limite de tours, ouvert aux deux parties.
 */
type Props = {
  thread: ThreadEntry[];
  currentUserId: number | null;
  canReply: boolean;
  onSubmit: (content: string) => Promise<ThreadEntry>;
};

export function MessageThread({ thread, currentUserId, canReply, onSubmit }: Props) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    const trimmed = text.trim();
    if (trimmed === '' || busy) return;
    setBusy(true);
    setErr(null);
    try {
      await onSubmit(trimmed);
      setText('');
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Erreur');
    } finally {
      setBusy(false);
    }
  }

  if (thread.length === 0 && !canReply) {
    return null;
  }

  return (
    <View style={styles.container}>
      {thread.length > 0 && (
        <View style={{ gap: SPACING.sm }}>
          {thread.map((entry) => {
            const isMine = currentUserId !== null && entry.authorId === currentUserId;
            return (
              <View key={entry.id} style={[styles.bubble, isMine ? styles.bubbleMine : styles.bubbleOther]}>
                <View style={styles.bubbleHeader}>
                  <Text style={styles.bubbleAuthor}>{entry.authorLabel}</Text>
                  <Text style={styles.bubbleTime}>{formatDateShort(new Date(entry.createdAt))}</Text>
                </View>
                <Text style={styles.bubbleBody}>{entry.content}</Text>
              </View>
            );
          })}
        </View>
      )}

      {canReply && (
        <View style={styles.form}>
          <TextInput
            value={text}
            onChangeText={setText}
            placeholder="Poursuivre la conversation…"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={5000}
            style={styles.input}
            editable={!busy}
          />
          {err && <Text style={styles.err}>{err}</Text>}
          <Pressable
            onPress={submit}
            disabled={busy || text.trim() === ''}
            style={[styles.submitBtn, (busy || text.trim() === '') && styles.submitBtnDisabled]}
          >
            {busy ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.submitLabel}>Envoyer</Text>}
          </Pressable>
        </View>
      )}
    </View>
  );
}

function formatDateShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { gap: SPACING.sm, marginTop: SPACING.md },
  bubble: {
    borderRadius: RADIUS.md,
    padding: SPACING.sm,
    borderWidth: 1,
  },
  bubbleMine: {
    backgroundColor: '#eff6ff',
    borderColor: '#bfdbfe',
    marginLeft: SPACING.lg,
  },
  bubbleOther: {
    backgroundColor: COLORS.surface,
    borderColor: COLORS.border,
    marginRight: SPACING.lg,
  },
  bubbleHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', gap: 8, marginBottom: 4 },
  bubbleAuthor: { fontSize: 12, fontWeight: '700', color: COLORS.text },
  bubbleTime: { fontSize: 11, color: COLORS.textMuted },
  bubbleBody: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  form: { marginTop: SPACING.xs },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
    minHeight: 80,
    textAlignVertical: 'top',
    marginBottom: 6,
  },
  err: { color: COLORS.error, fontSize: 12, marginBottom: 6 },
  submitBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 12,
    alignItems: 'center',
  },
  submitBtnDisabled: { opacity: 0.4 },
  submitLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
});
