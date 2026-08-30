import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, auth } from '@/api/client';
import type { FamilyRelation, FamilyResponse, LinkedChild } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Écran « Ma famille » : déclare la relation (enfant / parent) avec
 * chaque compte lié à celui-ci (email partagé, famille existante).
 * Aucune saisie de numéro de licence — uniquement de la sélection.
 *
 * Marquer un compte comme « parent » lui assigne aussi automatiquement
 * le profil Parent (voir MeController::setFamilyLink côté serveur).
 */
export default function ProfileFamilyScreen() {
  const { user, replaceLinkedProfiles } = useAuth();
  const [data, setData] = useState<FamilyResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await auth.family();
      setData(resp);
      replaceLinkedProfiles(resp.linkedProfiles);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [replaceLinkedProfiles]);

  useEffect(() => { void load(); }, [load]);

  if (!user) return <Redirect href="/" />;

  async function setLink(targetUserId: number, relation: FamilyRelation) {
    setBusyId(targetUserId);
    setError(null);
    try {
      const resp = await auth.setFamilyLink(targetUserId, relation);
      setData(resp);
      replaceLinkedProfiles(resp.linkedProfiles);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur mise à jour');
    } finally {
      setBusyId(null);
    }
  }

  if (loading) return <FullScreenLoading />;
  if (error && !data) return <ErrorState message={error} onRetry={load} />;
  if (!data) return null;

  const noRelations = data.children.length === 0 && data.parents.length === 0;

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Ma famille' }} />
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.intro}>
          Sélectionnez parmi vos comptes liés qui est votre <Text style={styles.bold}>enfant</Text>
          {' '}ou votre <Text style={styles.bold}>parent</Text>. Ces liens apparaîtront dans les profils
          concernés. Marquer un compte comme parent lui assigne automatiquement le rôle Parent.
        </Text>

        {error && <Text style={styles.flash}>{error}</Text>}

        {data.parents.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>👨‍👩 Mes parents</Text>
            {data.parents.map((p) => (
              <RelationRow
                key={'p-' + p.id}
                person={p}
                relation="parent"
                busy={busyId === p.id}
                onRemove={() => setLink(p.id, 'none')}
              />
            ))}
          </View>
        )}

        {data.children.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>👶 Mes enfants</Text>
            {data.children.map((c) => (
              <RelationRow
                key={'c-' + c.id}
                person={c}
                relation="child"
                busy={busyId === c.id}
                onRemove={() => setLink(c.id, 'none')}
              />
            ))}
          </View>
        )}

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>
            {noRelations ? '➕ Déclarer une relation' : '➕ Autres comptes liés'}
          </Text>
          {data.assignable.length === 0 ? (
            <Text style={styles.empty}>
              {noRelations
                ? 'Aucun compte lié à déclarer pour l\'instant.'
                : 'Tous vos comptes liés sont déjà déclarés.'}
            </Text>
          ) : (
            data.assignable.map((p) => (
              <AssignRow
                key={'a-' + p.id}
                person={p}
                busy={busyId === p.id}
                onPick={(r) => setLink(p.id, r)}
              />
            ))
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function RelationRow({
  person,
  relation,
  busy,
  onRemove,
}: {
  person: LinkedChild;
  relation: 'child' | 'parent';
  busy: boolean;
  onRemove: () => void;
}) {
  return (
    <View style={styles.row}>
      <View style={styles.rowIcon}>
        <Ionicons name={relation === 'parent' ? 'people' : 'happy'} size={18} color="#fff" />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.name}>{person.fullName}</Text>
        {person.licenceLabel ? <Text style={styles.meta}>{person.licenceLabel}</Text> : null}
      </View>
      <Pressable
        onPress={onRemove}
        disabled={busy}
        style={({ pressed }) => [styles.smallBtn, styles.btnDanger, (busy || pressed) && { opacity: 0.6 }]}
      >
        {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.smallBtnLabel}>Retirer</Text>}
      </Pressable>
    </View>
  );
}

function AssignRow({
  person,
  busy,
  onPick,
}: {
  person: LinkedChild;
  busy: boolean;
  onPick: (relation: 'child' | 'parent') => void;
}) {
  return (
    <View style={styles.row}>
      <View style={[styles.rowIcon, styles.rowIconMuted]}>
        <Ionicons name="person" size={18} color="#fff" />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.name}>{person.fullName}</Text>
        {person.licenceLabel ? <Text style={styles.meta}>{person.licenceLabel}</Text> : null}
      </View>
      <View style={styles.assignActions}>
        <Pressable
          onPress={() => onPick('child')}
          disabled={busy}
          style={({ pressed }) => [styles.smallBtn, styles.btnPrimary, (busy || pressed) && { opacity: 0.6 }]}
        >
          <Text style={styles.smallBtnLabel}>Mon enfant</Text>
        </Pressable>
        <Pressable
          onPress={() => onPick('parent')}
          disabled={busy}
          style={({ pressed }) => [styles.smallBtn, styles.btnSecondary, (busy || pressed) && { opacity: 0.6 }]}
        >
          <Text style={styles.smallBtnLabel}>Mon parent</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, gap: SPACING.lg },
  intro: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  bold: { fontWeight: '700', color: COLORS.text },
  flash: {
    padding: SPACING.md,
    backgroundColor: '#fee2e2',
    color: '#991b1b',
    borderRadius: RADIUS.md,
    fontSize: 13, fontWeight: '600',
  },
  section: { gap: SPACING.sm },
  sectionTitle: {
    fontSize: 13, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5,
  },
  empty: { fontSize: 13, color: COLORS.textSubtle, fontStyle: 'italic' },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.md,
    padding: SPACING.md,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
  },
  rowIcon: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center', justifyContent: 'center',
  },
  rowIconMuted: { backgroundColor: COLORS.textMuted },
  name: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  meta: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  smallBtn: {
    paddingHorizontal: 12, paddingVertical: 6,
    borderRadius: RADIUS.sm, alignItems: 'center', justifyContent: 'center',
  },
  smallBtnLabel: { color: '#fff', fontSize: 12, fontWeight: '700' },
  btnPrimary: { backgroundColor: COLORS.primary },
  btnSecondary: { backgroundColor: COLORS.secondary },
  btnDanger: { backgroundColor: COLORS.error },
  assignActions: { flexDirection: 'row', gap: 6 },
});
