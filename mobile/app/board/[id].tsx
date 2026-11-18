import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { board as boardApi } from '@/api/resources';
import type { BoardAgendaItem, BoardComment, BoardItemType, BoardMeetingDetail, BoardOption } from '@/api/types';
import { BoardActionRow } from '@/components/BoardActionRow';
import { LinkedText } from '@/components/LinkedText';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatMeetingDate } from '@/utils/boardDate';
import { poleTone } from '@/utils/boardPoles';

const TYPE_COLORS: Record<BoardItemType, { bg: string; fg: string }> = {
  information: { bg: '#DBEAFE', fg: '#1e40af' },
  sujet: { bg: '#FEF3C7', fg: '#92400e' },
  question: { bg: '#FCE7F3', fg: '#9d174d' },
};

/**
 * Réunion du CoDir : ordre du jour classé par pôle. Tant que la réunion n'est pas close, chaque membre ajoute
 * ses points (pôle, Information / Sujet / Question, texte) et peut modifier ou supprimer les siens tant qu'ils
 * n'ont pas été traités. Sur n'importe quel point (le sien compris), on peut ajouter autant de commentaires
 * (Information, Sujet) ou de réponses (Question) que l'on veut, répondre à un commentaire (le fil s'affiche en
 * retrait) et modifier les siens (mention « modifié »). « Qui s'exprime ? » s'affiche en nom complet. Les réponses, décisions et actions (porteurs, échéance, état — en évidence quand c'est le
 * membre connecté qui les porte) saisies en séance s'affichent sous chaque point.
 *
 * Chaque pôle (Partenaires, Organisations…) a sa couleur (utils/boardPoles). Les textes peuvent contenir des liens
 * (<a href="…">, [libellé](…) ou adresse collée) rendus cliquables — ex. un fichier Google Drive. « Modifier » remonte
 * en haut de l'écran, où se trouve le formulaire.
 */
export default function BoardMeetingScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const scrollRef = useRef<ScrollView>(null);
  const [meeting, setMeeting] = useState<BoardMeetingDetail | null>(null);
  const [poles, setPoles] = useState<BoardOption[]>([]);
  const [types, setTypes] = useState<BoardOption[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const [pole, setPole] = useState<string | null>(null);
  const [type, setType] = useState<BoardItemType>('sujet');
  const [text, setText] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  // Commentaire / réponse en cours de saisie : sous un point (parentId null), sous un commentaire auquel on répond, ou
  // modification de son propre commentaire (editId).
  const [composer, setComposer] = useState<{ itemId: number; parentId: number | null; editId: number | null } | null>(null);
  const [commentText, setCommentText] = useState('');

  const load = useCallback(async () => {
    try {
      setError(null);
      const [detail, options] = await Promise.all([boardApi.meeting(Number(id)), boardApi.meetings()]);
      setMeeting(detail);
      setPoles(options.poles);
      setTypes(options.types);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, [id]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  function resetForm() {
    setPole(null);
    setType('sujet');
    setText('');
    setEditingId(null);
  }

  function startEdit(item: BoardAgendaItem) {
    setEditingId(item.id);
    setPole(item.pole);
    setType(item.type);
    setText(item.text);
    // Le formulaire est en haut de l'écran, le point modifié peut être loin en dessous : on remonte pour voir les champs.
    scrollRef.current?.scrollTo({ y: 0, animated: true });
  }

  async function submit() {
    if (!meeting) return;
    if (!pole) return showMessage('Choisissez un pôle.');
    if (text.trim() === '') return showMessage('Écrivez votre point.');
    setBusy(true);
    try {
      const input = { pole, type, text: text.trim() };
      setMeeting(editingId ? await boardApi.updateItem(editingId, input) : await boardApi.addItem(meeting.id, input));
      resetForm();
    } catch (e) {
      showMessage(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  async function remove(item: BoardAgendaItem) {
    if (!(await confirmAsync('Supprimer ce point ?', item.text))) return;
    setBusy(true);
    try {
      setMeeting(await boardApi.removeItem(item.id));
      if (editingId === item.id) resetForm();
    } catch (e) {
      showMessage(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  /** Ouvre la saisie ; passer d'une modification à autre chose (ou l'inverse) repart d'un texte propre. */
  function openComposer(next: { itemId: number; parentId: number | null; editId?: number | null }, initialText = '') {
    if (next.editId != null || composer?.editId != null) setCommentText(initialText);
    setComposer({ itemId: next.itemId, parentId: next.parentId, editId: next.editId ?? null });
  }

  async function sendComment(item: BoardAgendaItem) {
    const message = commentText.trim();
    if (message === '') return showMessage(item.type === 'question' ? 'Écrivez votre réponse.' : 'Écrivez votre commentaire.');
    setBusy(true);
    try {
      const target = composer?.itemId === item.id ? composer : null;
      setMeeting(
        target?.editId
          ? await boardApi.updateComment(target.editId, message)
          : await boardApi.addComment(item.id, message, target?.parentId ?? null),
      );
      setComposer(null);
      setCommentText('');
    } catch (e) {
      showMessage(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  async function removeComment(comment: BoardComment) {
    if (!(await confirmAsync('Supprimer ce message ?', comment.text))) return;
    setBusy(true);
    try {
      setMeeting(await boardApi.removeComment(comment.id));
    } catch (e) {
      showMessage(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  /**
   * Zone de saisie : un commentaire sous un point, une réponse sous le commentaire de `replyTo`, ou — avec `editing` —
   * la modification de son propre commentaire (texte déjà rempli).
   */
  function renderComposer(item: BoardAgendaItem, replyTo: BoardComment | null, editing = false) {
    const placeholder = editing
      ? 'Votre message…'
      : replyTo
        ? `Votre réponse à ${replyTo.authorName}…`
        : item.type === 'question' ? 'Votre réponse…' : 'Votre commentaire…';
    return (
      <View style={styles.composer}>
        <TextInput
          value={commentText}
          onChangeText={setCommentText}
          placeholder={placeholder}
          placeholderTextColor={COLORS.textSubtle}
          multiline
          autoFocus
          maxLength={1000}
          style={styles.composerInput}
        />
        <View style={styles.formActions}>
          <Pressable onPress={() => void sendComment(item)} disabled={busy} style={[styles.submit, styles.submitSmall, busy && { opacity: 0.6 }]}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>{editing ? 'Enregistrer' : 'Envoyer'}</Text>}
          </Pressable>
          <Pressable onPress={() => { setComposer(null); setCommentText(''); }}>
            <Text style={styles.cancel}>Annuler</Text>
          </Pressable>
        </View>
      </View>
    );
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!meeting) return <FullScreenLoading />;

  // Regroupement par pôle (les points arrivent déjà triés dans l'ordre du compte rendu).
  const groups: { label: string; pole: string; items: BoardAgendaItem[] }[] = [];
  for (const raw of meeting.items) {
    // Tolère un serveur pas encore à jour (champs commentaires / actions absents).
    const item: BoardAgendaItem = { ...raw, canComment: raw.canComment ?? false, comments: raw.comments ?? [], actions: raw.actions ?? [] };
    const last = groups[groups.length - 1];
    if (last && last.label === item.poleLabel) last.items.push(item);
    else groups.push({ label: item.poleLabel, pole: item.pole, items: [item] });
  }

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: meeting.label }} />
      <ScrollView
        ref={scrollRef}
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.header}>
          <Text style={styles.headerTitle}>{meeting.label}</Text>
          <Text style={styles.headerMeta}>{formatMeetingDate(meeting.scheduledAt)}{meeting.location ? ' · ' + meeting.location : ''}</Text>
          <Text style={styles.headerMeta}>
            {meeting.closed
              ? `Réunion close · compte rendu · ${meeting.answeredCount}/${meeting.itemCount} points traités`
              : `${meeting.itemCount} point${meeting.itemCount > 1 ? 's' : ''} à l'ordre du jour`}
          </Text>
          {meeting.notes ? <LinkedText text={meeting.notes} style={styles.notes} linkStyle={styles.onDarkLink} /> : null}
        </View>

        {meeting.canAddItems && (
          <View style={styles.form}>
            <Text style={styles.formTitle}>{editingId ? 'Modifier mon point' : 'Ajouter un point à l\'ordre du jour'}</Text>
            <Text style={styles.label}>Pôle</Text>
            <View style={styles.chips}>
              {poles.map((p) => {
                const tone = poleTone(p.value);
                const active = pole === p.value;
                return (
                  <Pressable
                    key={p.value}
                    onPress={() => setPole(p.value)}
                    accessibilityRole="button"
                    accessibilityState={{ selected: active }}
                    style={[styles.chip, { backgroundColor: active ? tone.accent : tone.soft, borderColor: tone.accent }]}
                  >
                    <Text style={[styles.chipLabel, { color: active ? '#fff' : tone.ink }]}>{p.label}</Text>
                  </Pressable>
                );
              })}
            </View>
            <Text style={styles.label}>Type</Text>
            <View style={styles.segment}>
              {types.map((t) => (
                <Pressable
                  key={t.value}
                  onPress={() => setType(t.value as BoardItemType)}
                  style={[styles.segmentBtn, type === t.value && styles.segmentBtnActive]}
                >
                  <Text style={[styles.segmentLabel, type === t.value && styles.chipLabelActive]}>{t.label}</Text>
                </Pressable>
              ))}
            </View>
            <TextInput
              value={text}
              onChangeText={setText}
              placeholder="Votre information, sujet ou question…"
              placeholderTextColor={COLORS.textSubtle}
              multiline
              maxLength={2000}
              style={styles.input}
            />
            <Text style={styles.linkHint}>
              Un lien (fichier Google Drive, site…) : collez l'adresse, ou écrivez {'<a href="https://…">mon document</a>'} — il sera cliquable.
            </Text>
            <View style={styles.formActions}>
              <Pressable onPress={() => void submit()} disabled={busy} style={[styles.submit, busy && { opacity: 0.6 }]}>
                {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>{editingId ? 'Enregistrer' : 'Ajouter'}</Text>}
              </Pressable>
              {editingId ? (
                <Pressable onPress={resetForm}><Text style={styles.cancel}>Annuler</Text></Pressable>
              ) : null}
            </View>
          </View>
        )}

        {groups.length === 0 && <Text style={styles.empty}>Aucun point à l'ordre du jour pour l'instant.</Text>}

        {groups.map((g) => (
          <View key={g.label} style={styles.group}>
            <View style={[styles.groupTag, { backgroundColor: poleTone(g.pole).accent }]}>
              <Text style={styles.groupTitle}>{g.label}</Text>
            </View>
            {g.items.map((item) => (
              <View key={item.id} style={[styles.item, { borderLeftWidth: 4, borderLeftColor: poleTone(item.pole).accent }]}>
                <View style={styles.itemHead}>
                  <View style={[styles.typeTag, { backgroundColor: TYPE_COLORS[item.type].bg }]}>
                    <Text style={[styles.typeTagLabel, { color: TYPE_COLORS[item.type].fg }]}>{item.typeLabel}</Text>
                  </View>
                  <Text style={styles.speaker}>{item.speaker ?? '—'}{(item.speakerIsMe ?? item.isMine) ? ' (moi)' : ''}</Text>
                </View>
                <LinkedText text={item.text} style={styles.itemText} />

                {item.comments.length > 0 && (
                  <View style={styles.comments}>
                    <Text style={styles.commentsTitle}>
                      {item.type === 'question' ? 'Réponses' : 'Commentaires'} ({item.comments.length})
                    </Text>
                    {/* Fil : chaque commentaire est suivi de ses réponses, en retrait (depth 0 à 2). */}
                    {item.comments.map((c) => {
                      const depth = Math.min(Math.max(c.depth ?? 0, 0), 2);
                      const mine = composer?.itemId === item.id ? composer : null;
                      const editing = mine?.editId === c.id;
                      const replying = !editing && mine?.editId == null && mine?.parentId === c.id;
                      return (
                        <View key={c.id} style={[styles.comment, depth > 0 && { marginLeft: depth * 14, paddingLeft: 8, borderLeftWidth: 2, borderLeftColor: COLORS.border }]}>
                          <Text style={styles.commentText}>
                            <Text style={styles.commentAuthor}>{c.authorName}{c.isMine ? ' (moi)' : ''}</Text>
                            {c.replyToName ? <Text style={styles.commentReplyTo}> ↪ {c.replyToName}</Text> : null}
                            <Text style={styles.commentAuthor}> : </Text>
                            {/* En modification, le champ de saisie remplace le texte. */}
                            {editing ? null : <LinkedText text={c.text} />}
                            {!editing && c.edited ? <Text style={styles.commentReplyTo}> (modifié)</Text> : null}
                          </Text>
                          {editing ? (
                            renderComposer(item, null, true)
                          ) : (
                            <View style={styles.commentActions}>
                              {item.canComment && !replying && (
                                <Pressable onPress={() => openComposer({ itemId: item.id, parentId: c.id })} accessibilityRole="button">
                                  <Text style={styles.link}>Répondre</Text>
                                </Pressable>
                              )}
                              {c.canEdit && (
                                <Pressable onPress={() => openComposer({ itemId: item.id, parentId: c.parentId ?? null, editId: c.id }, c.text)} disabled={busy} accessibilityRole="button">
                                  <Text style={styles.link}>Modifier</Text>
                                </Pressable>
                              )}
                              {c.canDelete && (
                                <Pressable onPress={() => void removeComment(c)} disabled={busy}>
                                  <Text style={[styles.link, { color: COLORS.error }]}>Supprimer</Text>
                                </Pressable>
                              )}
                            </View>
                          )}
                          {replying && renderComposer(item, c)}
                        </View>
                      );
                    })}
                  </View>
                )}

                {item.canComment && (
                  composer?.itemId === item.id && composer.parentId === null && composer.editId === null ? (
                    renderComposer(item, null)
                  ) : (
                    <Pressable onPress={() => openComposer({ itemId: item.id, parentId: null })} accessibilityRole="button">
                      <Text style={styles.link}>{item.type === 'question' ? 'Ajouter une réponse' : 'Ajouter un commentaire'}</Text>
                    </Pressable>
                  )
                )}

                {item.answered && (
                  <View style={styles.answer}>
                    {item.respondent ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Qui répond : </Text>{item.respondent}</Text> : null}
                    {item.answer ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Réponse / décision : </Text><LinkedText text={item.answer} /></Text> : null}
                    {item.actionOwner ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Porteur action : </Text>{item.actionOwner}</Text> : null}
                  </View>
                )}

                {item.actions.length > 0 && (
                  <View style={styles.actions}>
                    <Text style={styles.actionsTitle}>Action{item.actions.length > 1 ? 's' : ''}</Text>
                    {item.actions.map((a) => <BoardActionRow key={a.id} action={a} />)}
                  </View>
                )}
                {item.canEdit && (
                  <View style={styles.itemActions}>
                    <Pressable onPress={() => startEdit(item)}><Text style={styles.link}>Modifier</Text></Pressable>
                    <Pressable onPress={() => void remove(item)}><Text style={[styles.link, { color: COLORS.error }]}>Supprimer</Text></Pressable>
                  </View>
                )}
              </View>
            ))}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

function showMessage(msg: string) {
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Comité directeur', msg);
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
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, gap: SPACING.md, maxWidth: 720, width: '100%', alignSelf: 'center', paddingBottom: 40 },
  header: { backgroundColor: COLORS.brandNavy, borderRadius: RADIUS.md, padding: SPACING.lg, gap: 3 },
  headerTitle: { color: '#fff', fontSize: 20, fontWeight: '800' },
  headerMeta: { color: '#dbe4f3', fontSize: 13 },
  notes: { color: '#fff', fontSize: 13, marginTop: 6 },
  form: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, gap: 8, borderWidth: 2, borderColor: COLORS.secondary },
  formTitle: { fontSize: 15, fontWeight: '800', color: COLORS.text },
  label: { fontSize: 11, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginTop: 4 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, borderWidth: 1.5 },
  chipLabel: { fontSize: 12.5, fontWeight: '700' },
  chipLabelActive: { color: '#fff' },
  segment: { flexDirection: 'row', gap: 6 },
  segmentBtn: { flex: 1, paddingVertical: 8, borderRadius: RADIUS.sm, borderWidth: 1, borderColor: COLORS.border, alignItems: 'center', backgroundColor: COLORS.surfaceAlt },
  segmentBtnActive: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  segmentLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  input: { minHeight: 80, borderWidth: 1, borderColor: COLORS.border, borderRadius: RADIUS.sm, padding: 10, fontSize: 14, color: COLORS.text, textAlignVertical: 'top', marginTop: 4 },
  formActions: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  submit: { backgroundColor: COLORS.secondary, borderRadius: RADIUS.md, paddingVertical: 10, paddingHorizontal: 22, alignItems: 'center' },
  submitLabel: { color: '#fff', fontWeight: '800', fontSize: 14 },
  cancel: { color: COLORS.textMuted, fontWeight: '600' },
  linkHint: { fontSize: 11.5, color: COLORS.textMuted, lineHeight: 16 },
  onDarkLink: { color: '#bfdbfe' },
  empty: { color: COLORS.textMuted, textAlign: 'center', marginTop: 10 },
  group: { gap: 8 },
  groupTag: { alignSelf: 'flex-start', paddingHorizontal: 12, paddingVertical: 4, borderRadius: 999, marginTop: 4 },
  groupTitle: { fontSize: 12.5, fontWeight: '800', color: '#fff', textTransform: 'uppercase', letterSpacing: 0.6 },
  item: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, gap: 6, borderWidth: 1, borderColor: COLORS.border },
  itemHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  typeTag: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  typeTagLabel: { fontSize: 11, fontWeight: '700' },
  speaker: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  itemText: { fontSize: 14.5, color: COLORS.text, lineHeight: 20 },
  answer: { backgroundColor: '#f0fdf4', borderRadius: RADIUS.sm, padding: 10, gap: 3, borderLeftWidth: 3, borderLeftColor: COLORS.success },
  answerLine: { fontSize: 13.5, color: COLORS.text },
  answerKey: { fontWeight: '700' },
  comments: { backgroundColor: COLORS.surfaceAlt, borderRadius: RADIUS.sm, padding: 10, gap: 6 },
  commentsTitle: { fontSize: 11, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  comment: { gap: 2 },
  commentText: { fontSize: 13.5, color: COLORS.text, lineHeight: 19 },
  commentAuthor: { fontWeight: '700' },
  commentReplyTo: { fontWeight: '600', color: COLORS.textMuted, fontSize: 12.5 },
  commentActions: { flexDirection: 'row', gap: 16 },
  composer: { gap: 8 },
  composerInput: { minHeight: 64, borderWidth: 1, borderColor: COLORS.border, borderRadius: RADIUS.sm, padding: 10, fontSize: 14, color: COLORS.text, textAlignVertical: 'top' },
  submitSmall: { paddingVertical: 8, paddingHorizontal: 18 },
  actions: { backgroundColor: COLORS.surfaceAlt, borderRadius: RADIUS.sm, padding: 10, gap: 8, borderLeftWidth: 3, borderLeftColor: '#f59e0b' },
  actionsTitle: { fontSize: 11, fontWeight: '700', color: '#92400e', textTransform: 'uppercase', letterSpacing: 0.4 },
  itemActions: { flexDirection: 'row', gap: 16 },
  link: { color: COLORS.secondary, fontWeight: '700', fontSize: 13 },
});
