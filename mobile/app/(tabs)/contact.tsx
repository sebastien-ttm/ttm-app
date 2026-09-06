import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Platform,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError, auth } from '@/api/client';
import type { InboxMessage, MessageScope, UserMessage } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

type SectionKey = 'sent' | 'inbox' | 'archived';

/**
 * Onglet « Contact » — messagerie v2 :
 *  - Envoyés   : messages que J'AI envoyés (à un entraîneur, tous, ou
 *                au club). Bouton « Nouveau message » en tête.
 *  - Reçus     : messages dont je suis destinataire (entraîneur/admin
 *                uniquement). Badge de portée (pour moi seul / tous les
 *                entraîneurs / le club). Bouton « Répondre » si aucun
 *                collègue n'a encore répondu.
 *  - Archivés  : mes messages envoyés archivés + mes reçus archivés,
 *                dans une même liste ordonnée par date.
 */
export default function ContactScreen() {
  const router = useRouter();
  const { user } = useAuth();

  // Tout viewer avec accès à une boîte de réception (entraîneur ou admin).
  const hasInbox = useMemo(() => {
    if (!user) return false;
    return user.role === 'admin' || user.profiles.includes('entraineur');
  }, [user]);

  const [section, setSection] = useState<SectionKey>('sent');

  const [sent, setSent] = useState<UserMessage[]>([]);
  const [inbox, setInbox] = useState<InboxMessage[]>([]);
  const [archivedSent, setArchivedSent] = useState<UserMessage[]>([]);
  const [archivedInbox, setArchivedInbox] = useState<InboxMessage[]>([]);

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      // On charge en parallèle ce qui est pertinent pour le viewer.
      const jobs: Promise<unknown>[] = [
        auth.listMessages(false).then((r) => setSent(r.data)),
        auth.listMessages(true).then((r) => setArchivedSent(r.data)),
      ];
      if (hasInbox) {
        jobs.push(auth.listInbox(false).then((r) => setInbox(r.data)));
        jobs.push(auth.listInbox(true).then((r) => setArchivedInbox(r.data)));
      } else {
        setInbox([]);
        setArchivedInbox([]);
      }
      await Promise.all(jobs);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [hasInbox]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  async function archiveSent(m: UserMessage) {
    try {
      await auth.archiveSentMessage(m.id);
      await load();
    } catch (e) {
      showError(e);
    }
  }
  async function unarchiveSent(m: UserMessage) {
    try {
      await auth.unarchiveSentMessage(m.id);
      await load();
    } catch (e) {
      showError(e);
    }
  }
  async function archiveInboxMsg(m: InboxMessage) {
    try {
      await auth.archiveInbox(m.id);
      await load();
    } catch (e) {
      showError(e);
    }
  }
  async function unarchiveInboxMsg(m: InboxMessage) {
    try {
      await auth.unarchiveInbox(m.id);
      await load();
    } catch (e) {
      showError(e);
    }
  }

  if (loading) {
    return (
      <View style={[styles.container, styles.center]}>
        <ActivityIndicator color={COLORS.primary} />
      </View>
    );
  }
  if (error) {
    return (
      <View style={styles.container}>
        <ErrorState message={error} onRetry={() => { setLoading(true); void load(); }} />
      </View>
    );
  }

  // Compose la liste affichée selon la section, avec le composant à rendre.
  type Row =
    | { key: string; kind: 'sent'; msg: UserMessage }
    | { key: string; kind: 'inbox'; msg: InboxMessage };
  const rows: Row[] = (() => {
    if (section === 'sent') return sent.map((m) => ({ key: 's-' + m.id, kind: 'sent' as const, msg: m }));
    if (section === 'inbox') return inbox.map((m) => ({ key: 'i-' + m.id, kind: 'inbox' as const, msg: m }));
    // archivés : fusion + tri desc par date
    const all: Row[] = [
      ...archivedSent.map((m) => ({ key: 's-' + m.id, kind: 'sent' as const, msg: m })),
      ...archivedInbox.map((m) => ({ key: 'i-' + m.id, kind: 'inbox' as const, msg: m })),
    ];
    all.sort((a, b) => new Date(b.msg.sentAt).getTime() - new Date(a.msg.sentAt).getTime());
    return all;
  })();

  return (
    <FlatList
      style={styles.container}
      data={rows}
      keyExtractor={(r) => r.key}
      contentContainerStyle={styles.list}
      ListHeaderComponent={
        <View style={{ gap: SPACING.md }}>
          <Pressable
            style={styles.newButton}
            onPress={() => router.push('/contact/new' as never)}
          >
            <Ionicons name="create-outline" size={20} color="#fff" />
            <Text style={styles.newButtonLabel}>Nouveau message</Text>
          </Pressable>

          <View style={styles.quickRow}>
            <Pressable
              style={({ pressed }) => [styles.quickBtn, pressed && { opacity: 0.7 }]}
              onPress={() => router.push('/contact/quick?kind=feedback' as never)}
            >
              <Text style={styles.quickBtnIcon}>🐞</Text>
              <Text style={styles.quickBtnLabel}>Bug / Idée d'amélioration</Text>
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.quickBtn, pressed && { opacity: 0.7 }]}
              onPress={() => router.push('/contact/quick?kind=help' as never)}
            >
              <Text style={styles.quickBtnIcon}>🤝</Text>
              <Text style={styles.quickBtnLabel}>Je propose mon aide au club</Text>
            </Pressable>
          </View>

          <SectionTabs
            section={section}
            hasInbox={hasInbox}
            counts={{
              sent: sent.length,
              inbox: inbox.length,
              archived: archivedSent.length + archivedInbox.length,
            }}
            onChange={setSection}
          />
        </View>
      }
      ListEmptyComponent={
        <View style={styles.emptyCard}>
          <Ionicons name="chatbubble-ellipses-outline" size={32} color={COLORS.textMuted} />
          <Text style={styles.emptyLabel}>{emptyLabelFor(section)}</Text>
        </View>
      }
      renderItem={({ item }) =>
        item.kind === 'sent' ? (
          <SentCard
            m={item.msg}
            archived={section === 'archived'}
            onArchive={() => void archiveSent(item.msg)}
            onUnarchive={() => void unarchiveSent(item.msg)}
          />
        ) : (
          <InboxCard
            m={item.msg}
            archived={section === 'archived'}
            onOpen={() => router.push(('/contact/inbox/' + item.msg.id) as never)}
            onArchive={() => void archiveInboxMsg(item.msg)}
            onUnarchive={() => void unarchiveInboxMsg(item.msg)}
          />
        )
      }
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
    />
  );
}

function emptyLabelFor(s: SectionKey): string {
  if (s === 'sent') return "Vous n'avez pas encore envoyé de message.";
  if (s === 'inbox') return "Aucun message reçu pour l'instant.";
  return "Aucun message archivé.";
}

function SectionTabs({
  section, hasInbox, counts, onChange,
}: {
  section: SectionKey;
  hasInbox: boolean;
  counts: { sent: number; inbox: number; archived: number };
  onChange: (s: SectionKey) => void;
}) {
  const tabs: { key: SectionKey; label: string; count: number }[] = [
    { key: 'sent', label: 'Envoyés', count: counts.sent },
  ];
  if (hasInbox) tabs.push({ key: 'inbox', label: 'Reçus', count: counts.inbox });
  tabs.push({ key: 'archived', label: 'Archivés', count: counts.archived });

  return (
    <View style={styles.tabs}>
      {tabs.map((t) => {
        const active = section === t.key;
        return (
          <Pressable
            key={t.key}
            onPress={() => onChange(t.key)}
            style={({ pressed }) => [styles.tab, active && styles.tabActive, pressed && { opacity: 0.7 }]}
          >
            <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>
              {t.label}{t.count > 0 ? ' · ' + t.count : ''}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function SentCard({ m, archived, onArchive, onUnarchive }: {
  m: UserMessage; archived: boolean; onArchive: () => void; onUnarchive: () => void;
}) {
  const sent = new Date(m.sentAt);
  return (
    <View style={styles.card}>
      <View style={styles.row}>
        <View style={{ flex: 1 }}>
          <Text style={styles.toLabel}>
            À <Text style={styles.toTarget}>{m.recipientLabel}</Text>
          </Text>
          <ScopeChip scope={m.scope} recipientLabel={m.recipientLabel} />
        </View>
        <Text style={styles.date}>{formatDateShort(sent)}</Text>
      </View>
      {m.subject && (
        <Text style={styles.subject}>
          {m.category !== 'general' ? m.categoryIcon + ' ' : ''}{m.subject}
        </Text>
      )}
      <Text style={styles.body}>{m.body}</Text>

      {m.hasReply && m.reply && (
        <View style={styles.replyBox}>
          <View style={styles.replyHeader}>
            <Ionicons name="arrow-undo" size={14} color={COLORS.success} />
            <Text style={styles.replyHeaderLabel}>
              Réponse de {m.repliedByLabel ?? "l'équipe"}
              {m.repliedAt ? ' · ' + formatDateShort(new Date(m.repliedAt)) : ''}
            </Text>
          </View>
          <Text style={styles.replyBody}>{m.reply}</Text>
        </View>
      )}
      {!m.hasReply && (
        <Text style={styles.pending}>En attente d'une réponse…</Text>
      )}

      <View style={styles.cardActions}>
        {archived ? (
          <ActionBtn icon="arrow-undo-outline" label="Désarchiver" onPress={onUnarchive} />
        ) : (
          <ActionBtn icon="archive-outline" label="Archiver" onPress={onArchive} />
        )}
      </View>
    </View>
  );
}

function InboxCard({ m, archived, onOpen, onArchive, onUnarchive }: {
  m: InboxMessage; archived: boolean; onOpen: () => void;
  onArchive: () => void; onUnarchive: () => void;
}) {
  const sent = new Date(m.sentAt);
  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.card, pressed && { opacity: 0.85 }]}>
      <View style={styles.row}>
        <View style={{ flex: 1 }}>
          <Text style={styles.toLabel}>
            De <Text style={styles.toTarget}>{m.senderLabel}</Text>
          </Text>
          <ScopeChip scope={m.scope} recipientLabel={m.scopeLabel} received />
        </View>
        <Text style={styles.date}>{formatDateShort(sent)}</Text>
      </View>
      {m.subject && (
        <Text style={styles.subject}>
          {m.category !== 'general' ? m.categoryIcon + ' ' : ''}{m.subject}
        </Text>
      )}
      <Text style={styles.body} numberOfLines={3}>{m.body}</Text>

      {m.hasReply && m.reply && (
        <View style={styles.replyBox}>
          <View style={styles.replyHeader}>
            <Ionicons name="arrow-undo" size={14} color={COLORS.success} />
            <Text style={styles.replyHeaderLabel}>
              Répondu par {m.repliedByLabel ?? 'un collègue'}
              {m.repliedAt ? ' · ' + formatDateShort(new Date(m.repliedAt)) : ''}
            </Text>
          </View>
          <Text style={styles.replyBody} numberOfLines={3}>{m.reply}</Text>
        </View>
      )}
      {!m.hasReply && (
        <Text style={styles.pending}>Aucune réponse — touchez pour répondre</Text>
      )}

      <View style={styles.cardActions}>
        {archived ? (
          <ActionBtn icon="arrow-undo-outline" label="Désarchiver" onPress={onUnarchive} />
        ) : (
          <ActionBtn icon="archive-outline" label="Archiver" onPress={onArchive} />
        )}
      </View>
    </Pressable>
  );
}

function ActionBtn({ icon, label, onPress }: {
  icon: React.ComponentProps<typeof Ionicons>['name']; label: string; onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => [styles.actionBtn, pressed && { opacity: 0.6 }]}
    >
      <Ionicons name={icon} size={14} color={COLORS.textMuted} />
      <Text style={styles.actionBtnLabel}>{label}</Text>
    </Pressable>
  );
}

function ScopeChip({ scope, recipientLabel, received }: {
  scope: MessageScope;
  recipientLabel: string;
  received?: boolean;
}) {
  const { text, bg, fg } = scopeChipStyle(scope, recipientLabel, received);
  return (
    <View style={[styles.scopeChip, { backgroundColor: bg }]}>
      <Text style={[styles.scopeChipLabel, { color: fg }]}>{text}</Text>
    </View>
  );
}

function scopeChipStyle(scope: MessageScope, label: string, received?: boolean): { text: string; bg: string; fg: string } {
  if (scope === 'all_trainers') return { text: received ? 'Pour tous les entraîneurs' : 'À tous les entraîneurs', bg: '#eff6ff', fg: '#1e40af' };
  if (scope === 'club') return { text: received ? 'Pour le club (admins)' : 'Au club', bg: '#fdf4ff', fg: '#7e22ce' };
  return { text: received ? 'Pour vous seul' : label, bg: '#ecfdf5', fg: '#047857' };
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
}

function formatDateShort(d: Date): string {
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  center: { alignItems: 'center', justifyContent: 'center' },
  list: { padding: SPACING.md, paddingBottom: SPACING.xl },
  newButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 12,
  },
  newButtonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  quickRow: { flexDirection: 'row', gap: 8 },
  quickBtn: {
    flex: 1,
    backgroundColor: COLORS.surface,
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.md,
    paddingVertical: 10,
    paddingHorizontal: 8,
    alignItems: 'center',
    gap: 4,
  },
  quickBtnIcon: { fontSize: 20 },
  quickBtnLabel: {
    fontSize: 11, fontWeight: '600', color: COLORS.text,
    textAlign: 'center', lineHeight: 14,
  },
  tabs: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: 4,
    gap: 4,
    marginBottom: SPACING.sm,
  },
  tab: {
    flex: 1, paddingVertical: 8, borderRadius: RADIUS.sm, alignItems: 'center',
  },
  tabActive: { backgroundColor: COLORS.primarySoft },
  tabLabel: { fontSize: 12, fontWeight: '600', color: COLORS.textMuted },
  tabLabelActive: { color: COLORS.primaryDark, fontWeight: '700' },
  emptyCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.lg,
    alignItems: 'center',
    gap: 8,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 13, textAlign: 'center', lineHeight: 18 },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.sm,
  },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 },
  toLabel: { fontSize: 12, color: COLORS.textMuted },
  toTarget: { fontWeight: '700', color: COLORS.text },
  date: { fontSize: 11, color: COLORS.textMuted },
  subject: { fontSize: 14, fontWeight: '700', color: COLORS.text, marginTop: 6 },
  body: { fontSize: 14, color: COLORS.text, marginTop: 6, lineHeight: 20 },
  replyBox: {
    marginTop: SPACING.sm,
    paddingLeft: SPACING.sm,
    borderLeftWidth: 3,
    borderLeftColor: COLORS.success,
    backgroundColor: '#f0fdf4',
    padding: SPACING.sm,
    borderRadius: RADIUS.sm,
  },
  replyHeader: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 4 },
  replyHeaderLabel: { fontSize: 12, color: COLORS.success, fontWeight: '700' },
  replyBody: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  pending: { fontSize: 12, color: COLORS.textMuted, fontStyle: 'italic', marginTop: 8 },
  scopeChip: {
    alignSelf: 'flex-start',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 10,
    marginTop: 4,
  },
  scopeChipLabel: { fontSize: 11, fontWeight: '700' },
  cardActions: {
    flexDirection: 'row', justifyContent: 'flex-end', marginTop: SPACING.sm, gap: 8,
  },
  actionBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 4,
    paddingHorizontal: 8, paddingVertical: 6,
    borderRadius: RADIUS.sm,
  },
  actionBtnLabel: { fontSize: 12, color: COLORS.textMuted, fontWeight: '600' },
});
