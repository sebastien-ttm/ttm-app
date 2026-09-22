import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { comments as commentsApi, events as api } from '@/api/resources';
import type { Comment, EventItem } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { CommentThread } from '@/components/CommentThread';
import { EventVoteBar } from '@/components/EventVoteBar';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { ShareButton } from '@/components/ShareButton';
import { RichContent } from '@/components/RichContent';
import { addEventToCalendar } from '@/lib/addToCalendar';
import { useDocumentTitle } from '@/lib/useDocumentTitle';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Prépare la description pour RichContent :
 *  - si elle contient déjà des tags HTML (édition TinyMCE), on la
 *    laisse telle quelle ;
 *  - sinon (descriptions antérieures = texte brut) on convertit
 *    les URLs en <a> cliquables, on remplace les sauts de ligne
 *    par <br> et on enrobe le tout dans un <p> pour que le rendu
 *    HTML respecte les paragraphes.
 */
function toRichHtml(raw: string): string {
  if (/<\w+[^>]*>/.test(raw)) return raw;
  const escaped = raw
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  const linkified = escaped.replace(
    /(\bhttps?:\/\/[^\s<]+)/g,
    (m) => `<a href="${m}" target="_blank" rel="noopener noreferrer">${m}</a>`,
  );
  return '<p>' + linkified.replace(/\r?\n/g, '<br />') + '</p>';
}

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate();
}

function formatLongDate(d: Date): string {
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}
function formatTime(d: Date): string {
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

export default function EventDetailScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome();
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);
  const [event, setEvent] = useState<EventItem | null>(null);
  const [comments, setComments] = useState<Comment[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!id) { setError('Identifiant d\'événement invalide.'); return; }
    try {
      setError(null);
      const [resp, cmts] = await Promise.all([api.get(id), api.comments(id)]);
      setEvent(resp);
      setComments(cmts.data);
    } catch (e) {
      // 403 / 404 : soit l'événement n'existe pas, soit l'audience
      // ne correspond pas au profil du user (le backend renvoie 404
      // dans les deux cas pour ne pas révéler l'existence). On envoie
      // sur l'écran « Contenu non autorisé ».
      if (e instanceof ApiError && (e.status === 403 || e.status === 404)) {
        router.replace({
          pathname: '/access-denied',
          params: { reason: e.status === 403 ? 'forbidden' : 'not-found' },
        } as never);
        return;
      }
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, [id, router]);

  useEffect(() => {
    (async () => {
      await load();
      setLoading(false);
    })();
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  // Titre partagé (web) : nom de l'événement + description.
  useDocumentTitle(event?.title, event?.description ?? null);

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Événement' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !event) {
    return (
      <>
        <Stack.Screen options={{ title: 'Événement' }} />
        <ErrorState message={error ?? 'Événement introuvable.'} onRetry={load} />
      </>
    );
  }

  const headerTitle = event.tags.length > 0 ? event.tags.map((t) => t.name).join(' · ') : 'Événement';

  const start = new Date(event.startsAt);
  const end = event.endsAt ? new Date(event.endsAt) : null;
  const multiDay = end !== null && !sameDay(start, end);

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: headerTitle }} />
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      >
        {event.tags.length > 0 && (
          <View style={styles.typeBadges}>
            {event.tags.map((tag) => (
              <View key={tag.id} style={[styles.typeBadge, { backgroundColor: tag.color }]}>
                <Text style={styles.typeBadgeLabel}>{tag.name}</Text>
              </View>
            ))}
          </View>
        )}

        <Text style={styles.title}>{event.title}</Text>

        <View style={styles.actionsRow}>
          <ShareButton path={`/event/${event.id}`} title={event.title} />
          <Pressable
            onPress={() => addEventToCalendar(event)}
            accessibilityLabel="Ajouter à mon calendrier"
            style={({ pressed }) => [styles.calendarBtn, pressed && { opacity: 0.7 }]}
          >
            <Ionicons name="calendar-outline" size={16} color={COLORS.primary} />
            <Text style={styles.calendarBtnLabel}>Ajouter au calendrier</Text>
          </Pressable>
        </View>

        <View style={styles.metaCard}>
          <View style={styles.metaRow}>
            <Ionicons name="calendar-outline" size={18} color={COLORS.textMuted} style={styles.metaIcon} />
            <View style={{ flex: 1 }}>
              {multiDay && end ? (
                <>
                  <Text style={styles.metaValue}>Du {formatLongDate(start)}</Text>
                  <Text style={styles.metaValue}>au {formatLongDate(end)}</Text>
                </>
              ) : (
                <Text style={styles.metaValue}>{formatLongDate(start)}</Text>
              )}
              {!event.isAllDay && !multiDay && (
                <Text style={styles.metaSub}>
                  {formatTime(start)}
                  {end ? ` – ${formatTime(end)}` : ''}
                </Text>
              )}
              {event.isAllDay && !multiDay && (
                <Text style={styles.metaSub}>Toute la journée</Text>
              )}
            </View>
          </View>

          {event.location && (
            <View style={styles.metaRow}>
              <Ionicons name="location-outline" size={18} color={COLORS.textMuted} style={styles.metaIcon} />
              <Text style={[styles.metaValue, { flex: 1 }]}>{event.location}</Text>
            </View>
          )}
        </View>

        {/* Vote de présence — même comportement que sur la home
            (« Je m'inscris » si externalRegistrationUrl est renseignée). */}
        {event.voteEnabled && <EventVoteBar event={event} size="lg" />}

        {event.carpoolingEnabled && (
          <Pressable
            onPress={() => router.push({ pathname: '/event/[id]/carpool', params: { id: String(event.id) } })}
            style={({ pressed }) => [styles.carpoolBtn, pressed && { opacity: 0.75 }]}
            accessibilityLabel="Ouvrir la page covoiturage"
          >
            <Ionicons name="car" size={20} color="#fff" />
            <Text style={styles.carpoolBtnLabel}>Covoiturage</Text>
            <Ionicons name="chevron-forward" size={18} color="#fff" style={{ marginLeft: 'auto' }} />
          </Pressable>
        )}

        {event.description ? (
          <View style={styles.descCard}>
            <Text style={styles.descTitle}>Descriptif</Text>
            {/* Description éditée via TinyMCE côté admin — liens
                hypertexte, boutons stylés (a.ttm-btn) et retours à la
                ligne sont rendus par RichContent. Fallback plain-text
                (descriptions antérieures au passage rich-text) : on
                enrobe pour préserver les paragraphes. */}
            <RichContent html={toRichHtml(event.description)} />
          </View>
        ) : (
          <View style={styles.descCard}>
            <Text style={styles.descEmpty}>Aucun descriptif complémentaire.</Text>
          </View>
        )}

        <View style={styles.commentsSection}>
          <Text style={styles.descTitle}>Commentaires ({comments.length})</Text>
          <CommentThread
            comments={comments}
            currentUserId={user?.id ?? null}
            onSubmit={async (content, parentId) => {
              const created = await api.addComment(event.id, content, parentId);
              setComments((prev) => [...prev, created]);
              return created;
            }}
            onEdit={async (commentId, content) => {
              const updated = await commentsApi.edit(commentId, content);
              setComments((prev) => prev.map((c) => (c.id === commentId ? updated : c)));
              return updated;
            }}
          />
          <View style={{ marginTop: SPACING.sm }}>
            <NewCommentForm
              onSubmit={async (content) => {
                const created = await api.addComment(event.id, content);
                setComments((prev) => [...prev, created]);
              }}
            />
          </View>
        </View>

        <Pressable onPress={goBack} style={styles.backBtn}>
          <Text style={styles.backBtnLabel}>Retour</Text>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  );
}

function NewCommentForm({ onSubmit }: { onSubmit: (content: string) => Promise<void> }) {
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

  return (
    <View>
      <TextInput
        value={text}
        onChangeText={setText}
        placeholder="Votre commentaire…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        style={styles.commentInput}
        editable={!busy}
        maxLength={2000}
      />
      {err && <Text style={styles.commentErr}>{err}</Text>}
      <Pressable
        onPress={submit}
        disabled={busy || text.trim() === ''}
        style={[styles.calendarBtn, (busy || text.trim() === '') && { opacity: 0.5 }]}
      >
        {busy ? <ActivityIndicator color={COLORS.primary} /> : <Text style={styles.calendarBtnLabel}>Publier</Text>}
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, paddingBottom: SPACING.xxl },
  typeBadges: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 6,
    marginBottom: SPACING.sm,
  },
  typeBadge: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: RADIUS.sm,
  },
  typeBadgeLabel: { color: '#fff', fontSize: 11, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.5 },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.lg },
  metaCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    gap: SPACING.sm,
  },
  metaRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  metaIcon: { marginTop: 2 },
  metaValue: { fontSize: 15, color: COLORS.text, fontWeight: '600' },
  metaSub: { fontSize: 13, color: COLORS.textMuted, marginTop: 2 },
  descCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
  },
  descTitle: {
    fontSize: 12, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: SPACING.sm,
  },
  descEmpty: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic', textAlign: 'center' },
  commentsSection: {
    marginTop: SPACING.md,
    paddingTop: SPACING.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
  },
  commentInput: {
    minHeight: 60,
    padding: 10,
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.sm,
    fontSize: 14,
    color: COLORS.text,
    marginBottom: 8,
    textAlignVertical: 'top',
    backgroundColor: COLORS.surface,
  },
  commentErr: { color: COLORS.error, fontSize: 12, marginBottom: 6 },
  actionsRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: 8,
    marginBottom: SPACING.md,
  },
  calendarBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  calendarBtnLabel: {
    fontSize: 13,
    fontWeight: '600',
    color: COLORS.primary,
  },
  carpoolBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: COLORS.brandNavy,
    paddingHorizontal: SPACING.md,
    paddingVertical: 14,
    borderRadius: RADIUS.md,
    marginBottom: SPACING.md,
  },
  carpoolBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.sm },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
