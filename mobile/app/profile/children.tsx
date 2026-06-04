import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
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
import type { LinkedChild } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Écran « Mes enfants » — un parent peut lier / délier ses enfants
 * adhérents par numéro de licence après son inscription.
 *
 * Utile principalement pour :
 *  - les parents externes inscrits via /api/auth/register-parent qui
 *    ont oublié un enfant ou en ont un nouveau ;
 *  - les parents adhérents (compte avec licence propre + Profile.Parent)
 *    dont l'enfant a un email différent du leur.
 */
export default function ProfileChildrenScreen() {
  const router = useRouter();
  const { user, replaceLinkedProfiles } = useAuth();
  const [children, setChildren] = useState<LinkedChild[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Form add
  const [licence, setLicence] = useState('');
  const [adding, setAdding] = useState(false);
  const [addError, setAddError] = useState<string | null>(null);

  // Per-row remove busy
  const [removingId, setRemovingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setLoadError(null);
      const resp = await auth.listChildren();
      setChildren(resp.data);
      setCanManage(resp.canManage);
    } catch (e) {
      setLoadError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  // Guard : non connecté → la racine redirigera
  if (!user) return <Redirect href="/" />;

  async function submitAdd() {
    setAddError(null);
    const trimmed = licence.trim();
    if (trimmed === '') {
      setAddError('Saisissez un numéro de licence.');
      return;
    }
    setAdding(true);
    try {
      const resp = await auth.addChild(trimmed);
      // Met à jour la liste locale + propage au ProfileSwitcher
      setChildren((prev) => [...prev, resp.child]);
      replaceLinkedProfiles(resp.linkedProfiles);
      setLicence('');
    } catch (e) {
      setAddError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setAdding(false);
    }
  }

  async function confirmRemove(child: LinkedChild) {
    const doRemove = async () => {
      setRemovingId(child.id);
      try {
        const resp = await auth.removeChild(child.id);
        setChildren((prev) => prev.filter((c) => c.id !== child.id));
        replaceLinkedProfiles(resp.linkedProfiles);
      } catch (e) {
        const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
        if (Platform.OS === 'web') {
          if (typeof window !== 'undefined') window.alert(msg);
        } else {
          Alert.alert('Erreur', msg);
        }
      } finally {
        setRemovingId(null);
      }
    };
    const message = `Délier ${child.fullName} de votre compte ? Le compte adhérent n'est pas supprimé.`;
    if (Platform.OS === 'web') {
      if (typeof window !== 'undefined' && window.confirm(message)) {
        await doRemove();
      }
      return;
    }
    Alert.alert('Délier cet enfant ?', message, [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Délier', style: 'destructive', onPress: doRemove },
    ]);
  }

  if (loading) return <FullScreenLoading />;
  if (loadError) {
    return <ErrorState message={loadError} onRetry={() => { setLoading(true); void load(); }} />;
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Mes enfants' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <Text style={styles.intro}>
            Liez un enfant adhérent à votre compte en saisissant son numéro de licence.
            Vous pourrez ensuite basculer vers son profil depuis l'icône en haut à droite.
          </Text>

          {/* Liste actuelle */}
          <Text style={styles.sectionTitle}>Enfants liés</Text>
          {children.length === 0 ? (
            <View style={styles.emptyCard}>
              <Ionicons name="people-outline" size={28} color={COLORS.textMuted} />
              <Text style={styles.emptyLabel}>Aucun enfant lié pour le moment.</Text>
            </View>
          ) : (
            <View style={styles.list}>
              {children.map((c) => (
                <View key={c.id} style={styles.row}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rowTitle}>{c.fullName}</Text>
                    <Text style={styles.rowSub}>
                      Licence {c.licenceLabel}
                      {c.categorieFFTri ? ` · ${c.categorieFFTri}` : ''}
                    </Text>
                  </View>
                  {canManage && (
                    <Pressable
                      hitSlop={8}
                      disabled={removingId === c.id}
                      onPress={() => confirmRemove(c)}
                      style={({ pressed }) => [styles.removeBtn, pressed && { opacity: 0.5 }]}
                    >
                      {removingId === c.id ? (
                        <ActivityIndicator color={COLORS.error} />
                      ) : (
                        <Ionicons name="close-circle" size={26} color={COLORS.error} />
                      )}
                    </Pressable>
                  )}
                </View>
              ))}
            </View>
          )}

          {/* Form ajout */}
          {canManage ? (
            <>
              <Text style={[styles.sectionTitle, { marginTop: SPACING.xl }]}>
                Ajouter un enfant
              </Text>
              <Text style={styles.label}>N° de licence</Text>
              <TextInput
                value={licence}
                onChangeText={setLicence}
                placeholder="Ex : A123456"
                placeholderTextColor={COLORS.textSubtle}
                autoCapitalize="characters"
                autoCorrect={false}
                style={styles.input}
                editable={!adding}
                onSubmitEditing={submitAdd}
                returnKeyType="done"
              />
              {addError && <Text style={styles.error}>{addError}</Text>}
              <Pressable
                style={[styles.button, (adding || licence.trim() === '') && styles.buttonDisabled]}
                onPress={submitAdd}
                disabled={adding || licence.trim() === ''}
              >
                {adding ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <Text style={styles.buttonLabel}>Lier cet enfant</Text>
                )}
              </Pressable>
            </>
          ) : (
            <Text style={styles.notice}>
              Seuls les comptes parents peuvent gérer cette liste depuis l'app.
              Contactez l'équipe du club pour une modification.
            </Text>
          )}

          <Pressable style={styles.cancel} onPress={() => router.back()} disabled={adding}>
            <Text style={styles.cancelLabel}>Retour au profil</Text>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, maxWidth: 560, width: '100%', alignSelf: 'center' },
  intro: { fontSize: 14, color: COLORS.textMuted, marginBottom: SPACING.lg, lineHeight: 20 },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: SPACING.sm,
  },
  emptyCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.lg,
    alignItems: 'center',
    gap: 8,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 13 },
  list: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    overflow: 'hidden',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: SPACING.md,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
    gap: SPACING.sm,
  },
  rowTitle: { fontSize: 15, fontWeight: '600', color: COLORS.text },
  rowSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  removeBtn: { padding: 4 },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
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
  error: {
    color: COLORS.error,
    backgroundColor: COLORS.primarySoft,
    padding: 12,
    borderRadius: RADIUS.sm,
    marginBottom: SPACING.md,
    fontSize: 13,
    fontWeight: '500',
  },
  notice: {
    color: COLORS.textMuted,
    fontSize: 13,
    marginTop: SPACING.lg,
    lineHeight: 18,
    fontStyle: 'italic',
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
  cancel: { alignItems: 'center', paddingVertical: 14, marginTop: SPACING.lg },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
