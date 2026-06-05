import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError, auth } from '@/api/client';
import type { UserMessage } from '@/api/types';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Onglet « Contact » : liste de mes messages envoyés au club ou à un
 * entraîneur, avec leur réponse éventuelle. Bouton « Nouveau message »
 * en tête. Précédemment sous /profile/messages — déplacé en tab dédié.
 */
export default function ContactScreen() {
  const router = useRouter();
  const [items, setItems] = useState<UserMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await auth.listMessages();
      setItems(resp.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

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

  return (
    <FlatList
      style={styles.container}
      data={items}
      keyExtractor={(m) => String(m.id)}
      contentContainerStyle={styles.list}
      ListHeaderComponent={
        <Pressable
          style={styles.newButton}
          onPress={() => router.push('/contact/new' as never)}
        >
          <Ionicons name="create-outline" size={20} color="#fff" />
          <Text style={styles.newButtonLabel}>Nouveau message</Text>
        </Pressable>
      }
      ListEmptyComponent={
        <View style={styles.emptyCard}>
          <Ionicons name="chatbubble-ellipses-outline" size={32} color={COLORS.textMuted} />
          <Text style={styles.emptyLabel}>
            Vous n'avez pas encore envoyé de message.{'\n'}
            Contactez le club ou un entraîneur depuis le bouton ci-dessus.
          </Text>
        </View>
      }
      renderItem={({ item }) => <MessageCard m={item} />}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
    />
  );
}

function MessageCard({ m }: { m: UserMessage }) {
  const sent = new Date(m.sentAt);
  return (
    <View style={styles.card}>
      <View style={styles.row}>
        <Text style={styles.toLabel}>
          À <Text style={styles.toTarget}>{m.recipientLabel}</Text>
        </Text>
        <Text style={styles.date}>{formatDateShort(sent)}</Text>
      </View>
      {m.subject && <Text style={styles.subject}>{m.subject}</Text>}
      <Text style={styles.body}>{m.body}</Text>

      {m.hasReply && m.reply && (
        <View style={styles.replyBox}>
          <View style={styles.replyHeader}>
            <Ionicons name="arrow-undo" size={14} color={COLORS.success} />
            <Text style={styles.replyHeaderLabel}>
              Réponse de {m.repliedByLabel ?? 'l\'équipe'}
              {m.repliedAt ? ` · ${formatDateShort(new Date(m.repliedAt))}` : ''}
            </Text>
          </View>
          <Text style={styles.replyBody}>{m.reply}</Text>
        </View>
      )}

      {!m.hasReply && (
        <Text style={styles.pending}>En attente d'une réponse…</Text>
      )}
    </View>
  );
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
    marginBottom: SPACING.md,
  },
  newButtonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
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
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
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
});
