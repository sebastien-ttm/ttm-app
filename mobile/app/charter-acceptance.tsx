import Ionicons from '@expo/vector-icons/Ionicons';
import { useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { CharterAnswers, CharterField } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { filterCharterFields } from '@/components/CharterForm';
import { RichContent } from '@/components/RichContent';
import { APP_NAME, COLORS, RADIUS, SPACING } from '@/config';

/**
 * Tunnel d'onboarding : suite d'écrans qui s'enchaînent.
 *
 *  Étape 0                : message de bienvenue (charter.content)
 *  Étapes 1..N            : un engagement par écran (case à cocher)
 *  Étape N+1 (optionnelle): message final (charter.finalMessage)
 *  Bouton final           : « Valider mon accès à l'application »
 *
 * Le bouton « Suivant » est désactivé sur un écran d'engagement tant
 * que la case n'est pas cochée. Le refus / déconnexion reste accessible
 * en permanence en pied de page.
 */
export default function CharterAcceptanceScreen() {
  const { user, pendingCharter, acknowledgeCharter, signOut } = useAuth();

  // Filtre chaque engagement selon le profil (audience Parent/Jeune / Autre).
  // Le backend applique le même filtre à l'acceptance — cohérence garantie.
  const fields: CharterField[] = useMemo(
    () => filterCharterFields(pendingCharter?.fields ?? [], user)
      .filter((f) => f.type === 'checkbox'),
    [pendingCharter, user],
  );

  const hasFinal = !!pendingCharter?.finalMessage;
  const stepsCount = 1 + fields.length + (hasFinal ? 1 : 0);
  // Index des étapes :
  //   0        → welcome
  //   1..N     → engagement i-1
  //   N+1      → final (si présent)
  const [stepIndex, setStepIndex] = useState(0);
  const [answers, setAnswers] = useState<CharterAnswers>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isWelcome = stepIndex === 0;
  const engagementIdx = stepIndex - 1; // -1 si welcome, 0..N-1 si engagement
  const isEngagement = engagementIdx >= 0 && engagementIdx < fields.length;
  const isFinal = hasFinal && stepIndex === stepsCount - 1;
  const isLastStep = stepIndex === stepsCount - 1;

  const currentEngagement: CharterField | null = isEngagement ? fields[engagementIdx] : null;
  const currentEngagementChecked = currentEngagement
    ? (() => {
        const v = answers[currentEngagement.id];
        return v === true || v === 'true' || v === 1 || v === '1';
      })()
    : true;

  function next() {
    if (busy) return;
    setError(null);
    if (isEngagement && !currentEngagementChecked) return;
    if (isLastStep) {
      void submit();
      return;
    }
    setStepIndex((i) => i + 1);
  }

  function previous() {
    if (busy || stepIndex === 0) return;
    setError(null);
    setStepIndex((i) => i - 1);
  }

  function toggleCurrentEngagement() {
    if (!currentEngagement || busy) return;
    setAnswers((prev) => ({ ...prev, [currentEngagement.id]: !currentEngagementChecked }));
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await acknowledgeCharter(fields.length > 0 ? answers : undefined);
    } catch (e) {
      const apiBody = (e as { body?: { details?: string[] } } | undefined)?.body;
      const serverDetails = Array.isArray(apiBody?.details) ? apiBody.details.join(' ') : null;
      setError(serverDetails ?? (e instanceof Error ? e.message : 'Erreur lors de la validation.'));
    } finally {
      setBusy(false);
    }
  }

  async function onDecline() {
    const doSignOut = async () => {
      try { await signOut(); } catch { /* ignore */ }
    };
    if (Platform.OS === 'web') {
      const ok = typeof window !== 'undefined'
        && window.confirm('Refuser vous déconnecte de l\'application. Voulez-vous continuer ?');
      if (ok) await doSignOut();
      return;
    }
    Alert.alert(
      'Refuser ?',
      'Vous serez déconnecté et ne pourrez plus utiliser l\'application tant que vous n\'aurez pas validé.',
      [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Refuser et me déconnecter', style: 'destructive', onPress: doSignOut },
      ],
    );
  }

  if (!pendingCharter) {
    return (
      <SafeAreaView style={styles.container}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </SafeAreaView>
    );
  }

  // Libellé du bouton d'action principal selon l'étape courante.
  const nextLabel = isWelcome
    ? 'Suite'
    : isLastStep
      ? 'Valider mon accès à l\'application'
      : 'Suivant';
  const canGoNext = isEngagement ? currentEngagementChecked : true;

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
      <View style={styles.header}>
        <Text style={styles.brand}>{APP_NAME}</Text>
        <Text style={styles.title}>{pendingCharter.title}</Text>
        <Text style={styles.version}>Saison {pendingCharter.version}</Text>
        <ProgressBar current={stepIndex + 1} total={stepsCount} />
      </View>

      <ScrollView
        key={stepIndex} /* scroll top on step change */
        style={styles.scroll}
        contentContainerStyle={styles.scrollContent}
      >
        {isWelcome && (
          <>
            <StepBadge label="Bienvenue" />
            <RichContent html={pendingCharter.content} />
          </>
        )}

        {isEngagement && currentEngagement && (
          <>
            <StepBadge label={`Engagement ${engagementIdx + 1} sur ${fields.length}`} />
            {currentEngagement.title ? (
              <Text style={styles.engagementTitle}>{currentEngagement.title}</Text>
            ) : null}
            {currentEngagement.description ? (
              <RichContent html={currentEngagement.description} />
            ) : null}
            <Pressable
              onPress={toggleCurrentEngagement}
              style={({ pressed }) => [
                styles.checkboxCard,
                currentEngagementChecked && styles.checkboxCardChecked,
                pressed && { opacity: 0.7 },
              ]}
            >
              <View style={[styles.checkbox, currentEngagementChecked && styles.checkboxTicked]}>
                {currentEngagementChecked && <Text style={styles.checkboxMark}>✓</Text>}
              </View>
              <Text style={styles.checkboxLabel}>{currentEngagement.label}</Text>
            </Pressable>
          </>
        )}

        {isFinal && (
          <>
            <StepBadge label="Presque terminé" />
            <RichContent html={pendingCharter.finalMessage ?? ''} />
          </>
        )}
      </ScrollView>

      {error && <Text style={styles.errorBanner}>{error}</Text>}

      <View style={styles.footer}>
        <View style={styles.actions}>
          {!isWelcome ? (
            <Pressable
              style={({ pressed }) => [styles.prevBtn, pressed && { opacity: 0.7 }]}
              onPress={previous}
              disabled={busy}
            >
              <Ionicons name="chevron-back" size={18} color={COLORS.textMuted} />
              <Text style={styles.prevLabel}>Précédent</Text>
            </Pressable>
          ) : (
            <Pressable
              style={({ pressed }) => [styles.prevBtn, pressed && { opacity: 0.7 }]}
              onPress={onDecline}
              disabled={busy}
            >
              <Text style={styles.prevLabel}>Refuser</Text>
            </Pressable>
          )}

          <Pressable
            style={[styles.nextBtn, (!canGoNext || busy) && styles.nextBtnDisabled]}
            onPress={next}
            disabled={!canGoNext || busy}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.nextLabel}>{nextLabel}</Text>
            )}
          </Pressable>
        </View>
      </View>
    </SafeAreaView>
  );
}

function ProgressBar({ current, total }: { current: number; total: number }) {
  const pct = Math.round((current / Math.max(1, total)) * 100);
  return (
    <View style={styles.progressWrap}>
      <View style={[styles.progressFill, { width: `${pct}%` }]} />
      <Text style={styles.progressLabel}>{current} / {total}</Text>
    </View>
  );
}

function StepBadge({ label }: { label: string }) {
  return (
    <View style={styles.stepBadge}>
      <Text style={styles.stepBadgeLabel}>{label.toUpperCase()}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  header: {
    backgroundColor: COLORS.brandNavy,
    padding: 18,
    borderBottomWidth: 3,
    borderBottomColor: COLORS.primary,
  },
  brand: { color: 'rgba(255,255,255,0.85)', fontSize: 13, fontWeight: '600' },
  title: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 4 },
  version: { color: 'rgba(255,255,255,0.85)', fontSize: 13, marginTop: 2 },
  progressWrap: {
    marginTop: 14,
    height: 8,
    borderRadius: 4,
    backgroundColor: 'rgba(255,255,255,0.15)',
    overflow: 'hidden',
    position: 'relative',
  },
  progressFill: {
    position: 'absolute',
    left: 0, top: 0, bottom: 0,
    backgroundColor: COLORS.primary,
  },
  progressLabel: {
    position: 'absolute',
    right: -2, top: 10,
    color: 'rgba(255,255,255,0.75)',
    fontSize: 10,
    fontWeight: '600',
  },
  scroll: { flex: 1, backgroundColor: COLORS.surface },
  scrollContent: { padding: 18, paddingBottom: 36 },
  stepBadge: {
    alignSelf: 'flex-start',
    backgroundColor: COLORS.surfaceAlt,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
  },
  stepBadgeLabel: {
    fontSize: 11, fontWeight: '700', color: COLORS.textMuted, letterSpacing: 0.5,
  },
  engagementTitle: {
    fontSize: 22, fontWeight: '700', color: COLORS.brandNavy, marginBottom: SPACING.md,
  },
  checkboxCard: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderWidth: 2,
    borderColor: COLORS.border,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginTop: SPACING.lg,
  },
  checkboxCardChecked: {
    borderColor: COLORS.primary,
    backgroundColor: COLORS.primarySoft,
  },
  checkbox: {
    width: 24, height: 24, borderRadius: 5,
    borderWidth: 2, borderColor: COLORS.borderStrong,
    backgroundColor: '#fff',
    alignItems: 'center', justifyContent: 'center',
    marginTop: 1,
  },
  checkboxTicked: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  checkboxMark: { color: '#fff', fontWeight: '800', fontSize: 15, lineHeight: 15 },
  checkboxLabel: {
    flex: 1, fontSize: 15, fontWeight: '600', color: COLORS.text, lineHeight: 22,
  },
  errorBanner: {
    backgroundColor: '#FEE',
    color: COLORS.error,
    padding: 12,
    fontSize: 13,
    textAlign: 'center',
  },
  footer: {
    padding: 16,
    backgroundColor: COLORS.surface,
    borderTopWidth: 1,
    borderTopColor: COLORS.border,
  },
  actions: { flexDirection: 'row', gap: 10, alignItems: 'stretch' },
  prevBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    paddingVertical: 12,
    paddingHorizontal: SPACING.md,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  prevLabel: { color: COLORS.textMuted, fontWeight: '600', fontSize: 13 },
  nextBtn: {
    flex: 1,
    backgroundColor: COLORS.primary,
    paddingVertical: 14,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  nextBtnDisabled: { backgroundColor: '#ccc' },
  nextLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
});
