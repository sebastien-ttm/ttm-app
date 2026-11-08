import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { board as boardApi } from '@/api/resources';
import type { BoardAgendaItem, BoardItemType, BoardMeetingDetail, BoardOption } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatMeetingDate } from '@/utils/boardDate';

const TYPE_COLORS: Record<BoardItemType, { bg: string; fg: string }> = {
  information: { bg: '#DBEAFE', fg: '#1e40af' },
  sujet: { bg: '#FEF3C7', fg: '#92400e' },
  question: { bg: '#FCE7F3', fg: '#9d174d' },
};

/**
 * Réunion du CoDir : ordre du jour classé par pôle. Tant que la réunion n'est pas close, chaque membre ajoute
 * ses points (pôle, Information / Sujet / Question, texte) et peut modifier ou supprimer les siens tant qu'ils
 * n'ont pas été traités. Les réponses, décisions et porteurs d'action saisis en séance s'affichent sous chaque point.
 */
export default function BoardMeetingScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
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

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!meeting) return <FullScreenLoading />;

  // Regroupement par pôle (les points arrivent déjà triés dans l'ordre du compte rendu).
  const groups: { label: string; items: BoardAgendaItem[] }[] = [];
  for (const item of meeting.items) {
    const last = groups[groups.length - 1];
    if (last && last.label === item.poleLabel) last.items.push(item);
    else groups.push({ label: item.poleLabel, items: [item] });
  }

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: meeting.label }} />
      <ScrollView
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
          {meeting.notes ? <Text style={styles.notes}>{meeting.notes}</Text> : null}
        </View>

        {meeting.canAddItems && (
          <View style={styles.form}>
            <Text style={styles.formTitle}>{editingId ? 'Modifier mon point' : 'Ajouter un point à l\'ordre du jour'}</Text>
            <Text style={styles.label}>Pôle</Text>
            <View style={styles.chips}>
              {poles.map((p) => (
                <Pressable key={p.value} onPress={() => setPole(p.value)} style={[styles.chip, pole === p.value && styles.chipActive]}>
                  <Text style={[styles.chipLabel, pole === p.value && styles.chipLabelActive]}>{p.label}</Text>
                </Pressable>
              ))}
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
            <Text style={styles.groupTitle}>{g.label}</Text>
            {g.items.map((item) => (
              <View key={item.id} style={styles.item}>
                <View style={styles.itemHead}>
                  <View style={[styles.typeTag, { backgroundColor: TYPE_COLORS[item.type].bg }]}>
                    <Text style={[styles.typeTagLabel, { color: TYPE_COLORS[item.type].fg }]}>{item.typeLabel}</Text>
                  </View>
                  <Text style={styles.speaker}>{item.speaker ?? '—'}{item.isMine ? ' (moi)' : ''}</Text>
                </View>
                <Text style={styles.itemText}>{item.text}</Text>
                {item.answered && (
                  <View style={styles.answer}>
                    {item.respondent ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Qui répond : </Text>{item.respondent}</Text> : null}
                    {item.answer ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Réponse / décision : </Text>{item.answer}</Text> : null}
                    {item.actionOwner ? <Text style={styles.answerLine}><Text style={styles.answerKey}>Porteur action : </Text>{item.actionOwner}</Text> : null}
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
  chip: { paddingHorizontal: 10, paddingVertical: 6, borderRadius: 999, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surfaceAlt },
  chipActive: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  chipLabel: { fontSize: 12.5, fontWeight: '600', color: COLORS.text },
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
  empty: { color: COLORS.textMuted, textAlign: 'center', marginTop: 10 },
  group: { gap: 8 },
  groupTitle: { fontSize: 13, fontWeight: '800', color: COLORS.brandNavy, textTransform: 'uppercase', letterSpacing: 0.6, marginTop: 4 },
  item: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, gap: 6, borderWidth: 1, borderColor: COLORS.border },
  itemHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  typeTag: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  typeTagLabel: { fontSize: 11, fontWeight: '700' },
  speaker: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  itemText: { fontSize: 14.5, color: COLORS.text, lineHeight: 20 },
  answer: { backgroundColor: '#f0fdf4', borderRadius: RADIUS.sm, padding: 10, gap: 3, borderLeftWidth: 3, borderLeftColor: COLORS.success },
  answerLine: { fontSize: 13.5, color: COLORS.text },
  answerKey: { fontWeight: '700' },
  itemActions: { flexDirection: 'row', gap: 16 },
  link: { color: COLORS.secondary, fontWeight: '700', fontSize: 13 },
});
