import { useRouter } from 'expo-router';
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
import { useAuth } from '@/auth/AuthContext';
import { APP_NAME, COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Création d'un compte adhérent NON licencié au club (« ami du club »).
 * Même flow que register-parent mais avec la date de naissance en +
 * (utilisée pour dériver le profil principal Jeune/Sénior).
 */
export default function RegisterMemberScreen() {
  const router = useRouter();
  const { registerMember } = useAuth();

  const [prenom, setPrenom] = useState('');
  const [nom, setNom] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [dateNaissance, setDateNaissance] = useState('');
  const [numLicence, setNumLicence] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [details, setDetails] = useState<string[]>([]);

  async function submit() {
    setError(null);
    setDetails([]);

    if (!prenom.trim() || !nom.trim() || !email.trim()) {
      setError('Prénom, nom et e-mail sont obligatoires.');
      return;
    }
    if (password.length < 8) {
      setError('Le mot de passe doit faire au moins 8 caractères.');
      return;
    }
    if (password !== passwordConfirm) {
      setError('Les deux mots de passe ne correspondent pas.');
      return;
    }
    if (!dateNaissance.trim() || !/^\d{4}-\d{2}-\d{2}$/.test(dateNaissance.trim())) {
      setError('Date de naissance requise au format AAAA-MM-JJ.');
      return;
    }
    if (!numLicence.trim()) {
      setError('Numéro de licence requis.');
      return;
    }

    setBusy(true);
    try {
      await registerMember({
        email: email.trim(),
        prenom: prenom.trim(),
        nom: nom.trim(),
        password,
        dateNaissance: dateNaissance.trim(),
        numLicence: numLicence.trim(),
      });
    } catch (e) {
      const apiBody = (e as { body?: { error?: string; details?: string[] } } | undefined)?.body;
      setError(apiBody?.error ?? (e instanceof ApiError ? e.message : 'Erreur lors de l\'inscription.'));
      if (Array.isArray(apiBody?.details)) {
        setDetails(apiBody.details);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.container}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.header}>
            <Pressable onPress={() => router.back()} style={styles.backBtn}>
              <Text style={styles.backBtnLabel}>← Retour</Text>
            </Pressable>
            <Text style={styles.brand}>{APP_NAME}</Text>
            <Text style={styles.title}>Créer un compte adhérent externe</Text>
            <Text style={styles.subtitle}>
              Pour les adhérents du club licenciés dans un autre club FFTri.
            </Text>
          </View>

          <View style={styles.card}>
            <View style={styles.row}>
              <View style={styles.col}>
                <Text style={styles.label}>Prénom</Text>
                <TextInput
                  value={prenom}
                  onChangeText={setPrenom}
                  style={styles.input}
                  autoCapitalize="words"
                  autoComplete="given-name"
                  editable={!busy}
                />
              </View>
              <View style={styles.col}>
                <Text style={styles.label}>Nom</Text>
                <TextInput
                  value={nom}
                  onChangeText={setNom}
                  style={styles.input}
                  autoCapitalize="words"
                  autoComplete="family-name"
                  editable={!busy}
                />
              </View>
            </View>

            <Text style={styles.label}>Date de naissance</Text>
            {Platform.OS === 'web' ? (
              /* eslint-disable-next-line react/no-unknown-property */
              <input
                type="date"
                value={dateNaissance}
                onChange={(e: { target: { value: string } }) => setDateNaissance(e.target.value)}
                disabled={busy}
                style={{
                  fontFamily: 'inherit',
                  fontSize: 15,
                  padding: '12px 14px',
                  borderRadius: RADIUS.md,
                  border: `1px solid ${COLORS.border}`,
                  backgroundColor: COLORS.surface,
                  color: COLORS.text,
                  marginBottom: SPACING.md,
                }}
              />
            ) : (
              <TextInput
                value={dateNaissance}
                onChangeText={setDateNaissance}
                placeholder="AAAA-MM-JJ"
                placeholderTextColor={COLORS.textSubtle}
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="numbers-and-punctuation"
                style={styles.input}
                editable={!busy}
              />
            )}

            <Text style={styles.label}>Numéro de licence (autre club)</Text>
            <TextInput
              value={numLicence}
              onChangeText={setNumLicence}
              placeholder="Ex : A12345C0180418MS1FRA"
              placeholderTextColor={COLORS.textSubtle}
              autoCapitalize="characters"
              autoCorrect={false}
              style={styles.input}
              editable={!busy}
            />

            <Text style={styles.label}>Adresse e-mail</Text>
            <TextInput
              value={email}
              onChangeText={setEmail}
              placeholder="vous@example.fr"
              placeholderTextColor={COLORS.textSubtle}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              style={styles.input}
              editable={!busy}
            />

            <Text style={styles.label}>Mot de passe (8 caractères min.)</Text>
            <TextInput
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              style={styles.input}
              autoComplete="new-password"
              editable={!busy}
            />

            <Text style={styles.label}>Confirmation</Text>
            <TextInput
              value={passwordConfirm}
              onChangeText={setPasswordConfirm}
              secureTextEntry
              style={styles.input}
              autoComplete="new-password"
              editable={!busy}
            />

            {error && (
              <View style={styles.errorBox}>
                <Text style={styles.errorText}>{error}</Text>
                {details.length > 0 && (
                  <View style={{ marginTop: 6 }}>
                    {details.map((d, i) => (
                      <Text key={i} style={styles.errorDetail}>• {d}</Text>
                    ))}
                  </View>
                )}
              </View>
            )}

            <Pressable
              style={({ pressed }) => [styles.submitBtn, busy && { opacity: 0.6 }, pressed && { backgroundColor: COLORS.primaryDark }]}
              onPress={submit}
              disabled={busy}
            >
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>Créer mon compte</Text>}
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.brandNavy },
  scroll: { flexGrow: 1, padding: SPACING.lg, maxWidth: 560, width: '100%', alignSelf: 'center' },
  header: { marginBottom: SPACING.xl },
  backBtn: { paddingVertical: 6, alignSelf: 'flex-start' },
  backBtnLabel: { color: '#cbd5e1', fontSize: 14, fontWeight: '600' },
  brand: { color: 'rgba(255,255,255,0.85)', fontSize: 13, fontWeight: '600', marginTop: SPACING.sm },
  title: { color: '#fff', fontSize: 22, fontWeight: '800', marginTop: 4 },
  subtitle: { color: '#cbd5e1', fontSize: 14, marginTop: 6, lineHeight: 20 },
  card: { backgroundColor: COLORS.surface, borderRadius: RADIUS.xl, padding: SPACING.xl, ...SHADOWS.md },
  row: { flexDirection: 'row', gap: SPACING.md },
  col: { flex: 1 },
  label: { color: COLORS.text, fontWeight: '600', marginBottom: 6, fontSize: 13 },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    marginBottom: SPACING.md,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  errorBox: {
    backgroundColor: COLORS.primarySoft,
    borderColor: '#FFC9C9',
    borderWidth: 1,
    padding: 12,
    borderRadius: RADIUS.sm,
    marginVertical: SPACING.md,
  },
  errorText: { color: COLORS.error, fontSize: 13, fontWeight: '600' },
  errorDetail: { color: COLORS.error, fontSize: 12, marginTop: 2 },
  submitBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.md,
  },
  submitLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
});
