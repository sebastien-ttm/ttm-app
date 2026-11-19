import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
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
import { minibus as minibusApi } from '@/api/resources';
import { useAuth } from '@/auth/AuthContext';
import { DateField, todayIso } from '@/components/DateField';
import { CheckRow } from '@/components/minibus/CheckRow';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useGoBackOrHome } from '@/lib/goBackOrHome';
import { canUseMinibus } from '@/utils/profile';

/**
 * Demande de réservation du minibus : premier jour, dernier jour (si plusieurs jours), raison de l'emprunt, remorque. La
 * demande part au gestionnaire du minibus, qui la valide ou la refuse ; une fois validée, la charte d'utilisation est
 * présentée et les états des lieux (départ, retour) se remplissent depuis son détail. `?date=AAAA-MM-JJ` préremplit le jour.
 */
export default function MinibusNewScreen() {
  const router = useRouter();
  const goBack = useGoBackOrHome('/gestion?tab=minibus');
  const { user } = useAuth();
  const { date } = useLocalSearchParams<{ date?: string }>();
  const initial = typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : todayIso();

  const [start, setStart] = useState<string | null>(initial);
  const [end, setEnd] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [withTrailer, setWithTrailer] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!canUseMinibus(user)) {
    return (
      <SafeAreaView style={styles.container}>
        <Stack.Screen options={{ title: 'Emprunt du minibus' }} />
        <Text style={styles.denied}>L'emprunt du minibus est réservé aux membres du comité directeur.</Text>
      </SafeAreaView>
    );
  }

  const ready = start !== null && reason.trim().length >= 3;

  async function submit() {
    setError(null);
    if (start === null) {
      setError('Indiquez le premier jour.');
      return;
    }
    const last = end ?? start;
    if (last < start) {
      setError('Le dernier jour ne peut pas précéder le premier.');
      return;
    }
    if (reason.trim().length < 3) {
      setError('Indiquez la raison de l\'emprunt.');
      return;
    }
    setBusy(true);
    try {
      const created = await minibusApi.request({ startDate: start, endDate: last, reason: reason.trim(), withTrailer });
      router.replace(('/minibus/' + created.id) as never);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Demander le minibus' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.label}>Premier jour</Text>
          <DateField initialIso={initial} onChange={setStart} disabled={busy} minIso={todayIso()} />

          <Text style={styles.label}>Dernier jour (si l'emprunt dure plusieurs jours)</Text>
          <DateField onChange={setEnd} disabled={busy} minIso={start ?? todayIso()} />
          <Text style={styles.hint}>Laissez vide pour un emprunt d'une seule journée.</Text>

          <Text style={styles.label}>Raison de l'emprunt</Text>
          <TextInput
            value={reason}
            onChangeText={setReason}
            placeholder="Ex : déplacement au triathlon de Gruissan avec 8 jeunes"
            placeholderTextColor={COLORS.textSubtle}
            multiline
            maxLength={1000}
            style={[styles.input, styles.textarea]}
            editable={!busy}
          />
          <Text style={styles.counter}>{reason.length} / 1000</Text>

          <View style={{ marginTop: SPACING.md }}>
            <CheckRow label="J'ai aussi besoin de la remorque" checked={withTrailer} onChange={setWithTrailer} disabled={busy} />
          </View>

          <View style={styles.info}>
            <Text style={styles.infoText}>
              Votre demande est examinée par le gestionnaire du minibus. Si elle est validée, vous lirez et accepterez la charte
              d'utilisation, puis vous remplirez l'état des lieux au départ et au retour (photos, comment cela s'est passé).
            </Text>
          </View>

          {error && <Text style={styles.error}>{error}</Text>}

          <Pressable onPress={submit} disabled={busy || !ready} style={[styles.button, (busy || !ready) && styles.buttonDisabled]}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>Envoyer la demande</Text>}
          </Pressable>
          <Pressable onPress={goBack} style={styles.backBtn} disabled={busy}>
            <Text style={styles.backBtnLabel}>Annuler</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, maxWidth: 560, width: '100%', alignSelf: 'center' },
  denied: { padding: SPACING.lg, color: COLORS.textMuted, fontSize: 14 },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.md },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 110, textAlignVertical: 'top' },
  hint: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17, marginTop: 6 },
  counter: { textAlign: 'right', fontSize: 11, color: COLORS.textMuted, marginTop: 4 },
  info: { marginTop: SPACING.md, padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: COLORS.secondarySoft },
  infoText: { fontSize: 13, color: COLORS.secondaryDark, lineHeight: 18 },
  error: {
    color: COLORS.error, backgroundColor: '#fee2e2', padding: 12, borderRadius: RADIUS.sm,
    marginTop: SPACING.md, fontSize: 13, fontWeight: '500',
  },
  button: { backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 14, alignItems: 'center', marginTop: SPACING.lg },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
