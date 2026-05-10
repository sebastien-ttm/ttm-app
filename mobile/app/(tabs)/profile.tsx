import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { menu as menuApi, pages as pagesApi } from '@/api/resources';
import type { MenuItem, StaticPageSummary } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';

export default function ProfileScreen() {
  const { user, signOut } = useAuth();
  const router = useRouter();
  const [pageItems, setPageItems] = useState<StaticPageSummary[]>([]);
  const [externalItems, setExternalItems] = useState<MenuItem[]>([]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const [{ data: items }, { data: pageList }] = await Promise.all([menuApi.list(), pagesApi.list()]);
        if (cancelled) return;
        setPageItems(pageList);
        setExternalItems(items.filter((i) => i.type === 'external'));
      } catch {
        // Silent; menu is decorative
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (!user) return null;

  const isCoach = user.roles.includes('ROLE_COACH');
  const isAdmin = user.roles.includes('ROLE_ADMIN');

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {user.prenom.charAt(0)}
            {user.nom.charAt(0)}
          </Text>
        </View>
        <Text style={styles.name}>{user.fullName}</Text>
        <Text style={styles.email}>{user.email}</Text>

        <View style={styles.badgeRow}>
          {isAdmin && <Badge color="#D32F2F" label="Admin" />}
          {isCoach && !isAdmin && <Badge color="#1976D2" label="Entraîneur" />}
          {!isCoach && !isAdmin && <Badge color="#388E3C" label="Adhérent" />}
          <Badge color="#555" label={user.categorie === 'jeune' ? 'Jeune' : 'Sénior'} />
        </View>
      </View>

      <View style={styles.card}>
        <Row label="N° de licence" value={user.numLicence} />
        <Row label="Mot de passe" value={user.hasPassword ? 'Configuré' : 'Non configuré'} />
      </View>

      {pageItems.length > 0 && (
        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Pages utiles</Text>
          {pageItems.map((p) => (
            <Pressable
              key={p.slug}
              style={({ pressed }) => [styles.linkRow, pressed && styles.linkRowPressed]}
              onPress={() => router.push(`/page/${p.slug}` as never)}
            >
              <Text style={styles.linkLabel}>{p.title}</Text>
              <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />
            </Pressable>
          ))}
        </View>
      )}

      <Pressable style={styles.logoutButton} onPress={signOut}>
        <Text style={styles.logoutLabel}>Se déconnecter</Text>
      </Pressable>
    </ScrollView>
  );
}

function Badge({ color, label }: { color: string; label: string }) {
  return (
    <View style={[styles.badge, { backgroundColor: color }]}>
      <Text style={styles.badgeLabel}>{label}</Text>
    </View>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: 16, paddingBottom: 40 },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
  },
  avatar: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: COLORS.primary,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 12,
    alignSelf: 'center',
  },
  avatarText: { color: '#fff', fontSize: 28, fontWeight: '700' },
  name: { fontSize: 20, fontWeight: '700', color: COLORS.text, textAlign: 'center' },
  email: { fontSize: 14, color: COLORS.textMuted, textAlign: 'center', marginTop: 2 },
  badgeRow: { flexDirection: 'row', gap: 8, flexWrap: 'wrap', justifyContent: 'center', marginTop: 12 },
  badge: { paddingHorizontal: 12, paddingVertical: 4, borderRadius: 12 },
  badgeLabel: { color: '#fff', fontWeight: '700', fontSize: 12 },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  rowLabel: { fontSize: 14, color: COLORS.textMuted },
  rowValue: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.5 },
  linkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  linkRowPressed: { backgroundColor: COLORS.background },
  linkLabel: { fontSize: 15, color: COLORS.text },
  logoutButton: {
    backgroundColor: COLORS.surface,
    paddingVertical: 14,
    borderRadius: 12,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: COLORS.error,
    marginTop: 8,
  },
  logoutLabel: { color: COLORS.error, fontWeight: '700' },
});
