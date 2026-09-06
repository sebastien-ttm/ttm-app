import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
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
import { surveys as surveysApi } from '@/api/resources';
import type { Survey, SurveyAnswers, SurveyQuestion } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Formulaire dynamique de sondage — supporte les 4 types définis
 * côté backend (short_text, long_text, single_choice, multi_choice).
 * L'user peut modifier sa réponse tant que le sondage est ouvert.
 */
export default function SurveyScreen() {
  const router = useRouter();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);

  const [survey, setSurvey] = useState<Survey | null>(null);
  const [answers, setAnswers] = useState<SurveyAnswers>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submittedAt, setSubmittedAt] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!id) { setError('Identifiant invalide.'); setLoading(false); return; }
    try {
      setError(null);
      const resp = await surveysApi.get(id);
      setSurvey(resp);
      setAnswers(resp.myResponse?.answers ?? initialAnswersFor(resp.sections));
      setSubmittedAt(resp.myResponse?.updatedAt ?? resp.myResponse?.submittedAt ?? null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  const missing = useMemo(() => {
    if (!survey) return [];
    return survey.sections.filter((q) => q.required && isEmpty(answers[q.id]));
  }, [survey, answers]);

  async function submit() {
    if (!survey) return;
    setSubmitError(null);
    if (missing.length > 0) {
      setSubmitError('Répondez aux questions obligatoires marquées d\'un *.');
      return;
    }
    setBusy(true);
    try {
      const resp = await surveysApi.submit(survey.id, answers);
      setSurvey(resp);
      setSubmittedAt(resp.myResponse?.updatedAt ?? resp.myResponse?.submittedAt ?? new Date().toISOString());
    } catch (e) {
      if (e instanceof ApiError && e.body && typeof e.body === 'object' && 'details' in (e.body as object)) {
        const list = (e.body as { details?: unknown }).details;
        setSubmitError(Array.isArray(list) ? list.join(' · ') : e.message);
      } else {
        setSubmitError(e instanceof Error ? e.message : 'Erreur inattendue.');
      }
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Sondage' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error || !survey) {
    return (
      <>
        <Stack.Screen options={{ title: 'Sondage' }} />
        <ErrorState message={error ?? 'Sondage introuvable.'} onRetry={load} />
      </>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Sondage' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.title}>{survey.title}</Text>

          {survey.isClosed && (
            <View style={styles.closedBanner}>
              <Ionicons name="lock-closed" size={16} color="#991b1b" />
              <Text style={styles.closedLabel}>
                Ce sondage est fermé — vos réponses ne peuvent plus être modifiées.
              </Text>
            </View>
          )}

          {submittedAt && !survey.isClosed && (
            <View style={styles.savedBanner}>
              <Ionicons name="checkmark-circle" size={16} color="#065f46" />
              <Text style={styles.savedLabel}>
                Réponse enregistrée le {new Date(submittedAt).toLocaleString('fr-FR')}. Vous pouvez la modifier.
              </Text>
            </View>
          )}

          {survey.description && (
            <View style={styles.intro}>
              <RichContent html={survey.description} style={styles.introBody} />
            </View>
          )}

          {survey.sections.map((q, idx) => (
            <QuestionCard
              key={q.id}
              index={idx + 1}
              question={q}
              value={answers[q.id]}
              disabled={busy || survey.isClosed}
              onChange={(v) => setAnswers((prev) => ({ ...prev, [q.id]: v }))}
            />
          ))}

          {submitError && (
            <Text style={styles.error}>{submitError}</Text>
          )}

          {!survey.isClosed && (
            <Pressable
              onPress={submit}
              disabled={busy}
              style={({ pressed }) => [styles.submitBtn, pressed && { opacity: 0.85 }, busy && { opacity: 0.6 }]}
            >
              {busy ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <>
                  <Ionicons name="send" size={18} color="#fff" />
                  <Text style={styles.submitBtnLabel}>
                    {submittedAt ? 'Mettre à jour ma réponse' : 'Envoyer ma réponse'}
                  </Text>
                </>
              )}
            </Pressable>
          )}

          <Pressable onPress={() => router.back()} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Retour</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function QuestionCard({ index, question, value, disabled, onChange }: {
  index: number;
  question: SurveyQuestion;
  value: string | string[] | undefined;
  disabled: boolean;
  onChange: (v: string | string[]) => void;
}) {
  return (
    <View style={styles.qCard}>
      <Text style={styles.qLabel}>
        {index}. {question.label}
        {question.required && <Text style={styles.qRequired}> *</Text>}
      </Text>
      {question.help && <Text style={styles.qHelp}>{question.help}</Text>}

      {question.type === 'short_text' && (
        <TextInput
          value={typeof value === 'string' ? value : ''}
          onChangeText={(t) => onChange(t)}
          placeholder="Votre réponse"
          placeholderTextColor={COLORS.textSubtle}
          maxLength={500}
          editable={!disabled}
          style={styles.input}
        />
      )}

      {question.type === 'long_text' && (
        <TextInput
          value={typeof value === 'string' ? value : ''}
          onChangeText={(t) => onChange(t)}
          placeholder="Votre réponse"
          placeholderTextColor={COLORS.textSubtle}
          maxLength={5000}
          multiline
          editable={!disabled}
          style={[styles.input, styles.textarea]}
        />
      )}

      {question.type === 'single_choice' && (question.options ?? []).map((opt) => {
        const selected = value === opt;
        return (
          <Pressable
            key={opt}
            onPress={() => !disabled && onChange(opt)}
            style={({ pressed }) => [styles.optionRow, selected && styles.optionRowSelected, pressed && { opacity: 0.7 }]}
          >
            <Ionicons
              name={selected ? 'radio-button-on' : 'radio-button-off'}
              size={20}
              color={selected ? COLORS.primary : COLORS.textMuted}
            />
            <Text style={[styles.optionLabel, selected && { fontWeight: '700', color: COLORS.text }]}>{opt}</Text>
          </Pressable>
        );
      })}

      {question.type === 'multi_choice' && (question.options ?? []).map((opt) => {
        const list = Array.isArray(value) ? value : [];
        const selected = list.includes(opt);
        return (
          <Pressable
            key={opt}
            onPress={() => {
              if (disabled) return;
              const next = selected ? list.filter((x) => x !== opt) : [...list, opt];
              onChange(next);
            }}
            style={({ pressed }) => [styles.optionRow, selected && styles.optionRowSelected, pressed && { opacity: 0.7 }]}
          >
            <Ionicons
              name={selected ? 'checkbox' : 'square-outline'}
              size={20}
              color={selected ? COLORS.primary : COLORS.textMuted}
            />
            <Text style={[styles.optionLabel, selected && { fontWeight: '700', color: COLORS.text }]}>{opt}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function isEmpty(v: unknown): boolean {
  if (v === null || v === undefined) return true;
  if (typeof v === 'string' && v.trim() === '') return true;
  if (Array.isArray(v) && v.length === 0) return true;
  return false;
}

function initialAnswersFor(sections: SurveyQuestion[]): SurveyAnswers {
  const out: SurveyAnswers = {};
  for (const q of sections) {
    out[q.id] = q.type === 'multi_choice' ? [] : '';
  }
  return out;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, maxWidth: 640, width: '100%', alignSelf: 'center' },
  title: { fontSize: 22, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.md, lineHeight: 28 },
  closedBanner: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: '#fee2e2', borderRadius: RADIUS.sm,
    padding: 10, marginBottom: SPACING.md,
  },
  closedLabel: { color: '#991b1b', fontSize: 13, flex: 1 },
  savedBanner: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: '#d1fae5', borderRadius: RADIUS.sm,
    padding: 10, marginBottom: SPACING.md,
  },
  savedLabel: { color: '#065f46', fontSize: 13, flex: 1 },
  intro: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, marginBottom: SPACING.md },
  introBody: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  qCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.sm,
    gap: SPACING.sm,
  },
  qLabel: { fontSize: 15, fontWeight: '700', color: COLORS.text, lineHeight: 20 },
  qRequired: { color: COLORS.error, fontWeight: '700' },
  qHelp: { fontSize: 12, color: COLORS.textMuted, marginTop: -SPACING.sm },
  input: {
    backgroundColor: '#fff',
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
    color: COLORS.text,
  },
  textarea: { minHeight: 120, textAlignVertical: 'top' },
  optionRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10,
    paddingVertical: 10, paddingHorizontal: 12,
    backgroundColor: '#fff',
    borderRadius: RADIUS.sm,
    borderWidth: 1, borderColor: COLORS.border,
  },
  optionRowSelected: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  optionLabel: { fontSize: 14, color: COLORS.text, flex: 1 },
  error: {
    color: COLORS.error,
    backgroundColor: '#fee2e2',
    padding: 12,
    borderRadius: RADIUS.sm,
    marginTop: SPACING.sm,
    fontSize: 13, fontWeight: '500',
  },
  submitBtn: {
    marginTop: SPACING.md,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
  },
  submitBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
