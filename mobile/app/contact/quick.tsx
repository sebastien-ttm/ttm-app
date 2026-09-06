import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
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

import { ApiError, auth } from '@/api/client';
import type { MessageCategory } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Composer simplifié pour les 3 nouvelles catégories de messages
 * postées via boutons dédiés (onglet Contact) :
 *
 *  - kind=feedback : « Amélioration de l'appli » avec radio Bug/Idée
 *                    → adressé au club (scope=club)
 *  - kind=help     : « Je propose mon aide au club »
 *                    → adressé au club (scope=club)
 *
 * Pas de choix de destinataire ni d'objet (préréglés) — l'user tape
 * juste son message. Pour un envoi général, utiliser /contact/new.
 */
type Kind = 'feedback' | 'help';

export default function ContactQuickScreen() {
  const router = useRouter();
  const { kind: rawKind } = useLocalSearchParams<{ kind?: string }>();
  const kind: Kind = rawKind === 'help' ? 'help' : 'feedback';

  const [feedbackKind, setFeedbackKind] = useState<'bug' | 'improvement'>('bug');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const config = useMemo(() => configFor(kind, feedbackKind), [kind, feedbackKind]);

  async function submit() {
    setError(null);
    const trimmed = body.trim();
    if (trimmed === '') {
      setError('Le message ne peut pas être vide.');
      return;
    }
    if (trimmed.length > 5000) {
      setError('Message trop long (5000 caractères max).');
      return;
    }
    setBusy(true);
    try {
      const category: MessageCategory = kind === 'help' ? 'help_offer' : feedbackKind;
      await auth.sendMessage({
        scope: 'club',
        subject: config.subject,
        body: trimmed,
        category,
      });
      router.replace('/(tabs)/contact' as never);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: config.headerTitle }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.hero}>
            <Text style={styles.heroIcon}>{config.icon}</Text>
            <Text style={styles.heroTitle}>{config.title}</Text>
            <Text style={styles.heroSub}>{config.subtitle}</Text>
          </View>

          {kind === 'feedback' && (
            <>
              <Text style={styles.label}>Type de retour</Text>
              <View style={styles.pickerRow}>
                <TypeChoice
                  icon="🐞"
                  label="Bug rencontré"
                  selected={feedbackKind === 'bug'}
                  onPress={() => setFeedbackKind('bug')}
                />
                <TypeChoice
                  icon="💡"
                  label="Idée d'amélioration"
                  selected={feedbackKind === 'improvement'}
                  onPress={() => setFeedbackKind('improvement')}
                />
              </View>
            </>
          )}

          <Text style={[styles.label, { marginTop: SPACING.md }]}>{config.textareaLabel}</Text>
          <TextInput
            value={body}
            onChangeText={setBody}
            placeholder={config.placeholder}
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={5000}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />
          <Text style={styles.counter}>{body.length} / 5000</Text>

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable
            style={[styles.button, (busy || body.trim() === '') && styles.buttonDisabled]}
            onPress={submit}
            disabled={busy || body.trim() === ''}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Envoyer au club</Text>}
          </Pressable>

          <Pressable style={styles.cancel} onPress={() => router.back()} disabled={busy}>
            <Text style={styles.cancelLabel}>Annuler</Text>
          </Pressable>

          <Text style={styles.notice}>
            Ce message est envoyé aux administrateurs du club.
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function TypeChoice({ icon, label, selected, onPress }: {
  icon: string; label: string; selected: boolean; onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.typeCard, selected && styles.typeCardSelected, pressed && { opacity: 0.7 }]}
    >
      <Text style={styles.typeCardIcon}>{icon}</Text>
      <Text style={[styles.typeCardLabel, selected && { color: COLORS.primaryDark, fontWeight: '700' }]}>
        {label}
      </Text>
      <Ionicons
        name={selected ? 'checkmark-circle' : 'ellipse-outline'}
        size={18}
        color={selected ? COLORS.primary : COLORS.textMuted}
      />
    </Pressable>
  );
}

function configFor(kind: Kind, feedbackKind: 'bug' | 'improvement') {
  if (kind === 'help') {
    return {
      headerTitle: 'Proposer mon aide',
      icon: '🤝',
      title: 'Je propose mon aide au club',
      subtitle: 'Bénévolat, coup de main ponctuel, compétences… Dites-nous ce que vous pouvez apporter.',
      textareaLabel: 'Décrivez ce que vous pouvez faire',
      placeholder: 'Ex : disponible les samedis matins pour aider aux compétitions, je peux gérer un stand goûter, je maîtrise Excel pour les inscriptions…',
      subject: 'Proposition d\'aide',
    };
  }
  const isBug = feedbackKind === 'bug';
  return {
    headerTitle: 'Amélioration de l\'appli',
    icon: isBug ? '🐞' : '💡',
    title: 'Aidez-nous à améliorer l\'appli',
    subtitle: 'Signalez un dysfonctionnement ou proposez une idée — chaque retour est lu.',
    textareaLabel: isBug ? 'Décrivez le bug' : 'Décrivez votre idée',
    placeholder: isBug
      ? 'Ex : quand j\'ouvre l\'onglet Actualités sur iPhone, la liste ne charge pas. Cela arrive à chaque fois depuis mardi.'
      : 'Ex : ajouter un widget météo sur la page piscine, permettre de trier les articles par date…',
    subject: isBug ? 'Bug appli' : 'Idée d\'amélioration',
  };
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  hero: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.lg,
    alignItems: 'center',
    marginBottom: SPACING.lg,
    gap: 6,
  },
  heroIcon: { fontSize: 36 },
  heroTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text, textAlign: 'center' },
  heroSub: { fontSize: 13, color: COLORS.textMuted, textAlign: 'center', lineHeight: 18 },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
  pickerRow: { flexDirection: 'row', gap: 8 },
  typeCard: {
    flex: 1,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    paddingVertical: 12,
    paddingHorizontal: 10,
    alignItems: 'center',
    gap: 4,
  },
  typeCardSelected: {
    borderColor: COLORS.primary,
    backgroundColor: COLORS.primarySoft,
  },
  typeCardIcon: { fontSize: 22 },
  typeCardLabel: { fontSize: 12, color: COLORS.text, textAlign: 'center', fontWeight: '600' },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    marginBottom: 4,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  textarea: { minHeight: 180, textAlignVertical: 'top' },
  counter: { textAlign: 'right', fontSize: 11, color: COLORS.textMuted, marginBottom: SPACING.sm },
  error: {
    color: COLORS.error,
    backgroundColor: COLORS.primarySoft,
    padding: 12,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
    fontSize: 13,
    fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.sm,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  cancel: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.xs },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
  notice: {
    color: COLORS.textMuted,
    fontSize: 12,
    textAlign: 'center',
    marginTop: SPACING.md,
    fontStyle: 'italic',
  },
});
