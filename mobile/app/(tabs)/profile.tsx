import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';
import { accountTypeColor, accountTypeLabel, profileColor, profileLabel, sortProfiles } from '@/utils/profile';

export default function ProfileScreen() {
  const { user, signOut } = useAuth();
  const router = useRouter();

  if (!user) return null;

  const isAdmin = user.role === 'admin';
  const profiles = sortProfiles(user.profiles ?? []);

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
          <Badge color={accountTypeColor(user.type)} label={accountTypeLabel(user.type)} />
          {isAdmin && <Badge color="#D32F2F" label="Admin" />}
          {profiles.map((p) => (
            <Badge key={p} color={profileColor(p)} label={profileLabel(p)} />
          ))}
        </View>
      </View>

      <View style={styles.card}>
        <Row label="N° de licence" value={user.numLicence ?? '—'} />

        <Pressable
          style={({ pressed }) => [styles.actionRow, pressed && styles.actionRowPressed]}
          onPress={() => router.push('/profile/password' as never)}
        >
          <View style={{ flex: 1 }}>
            <Text style={styles.rowLabel}>Mot de passe</Text>
            <Text style={styles.actionHint}>
              {user.hasPassword
                ? 'Configuré · cliquez pour modifier'
                : 'Non configuré · cliquez pour définir'}
            </Text>
          </View>
          <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
        </Pressable>
      </View>

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
    backgroundColor: COLORS.brandNavy,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 12,
    alignSelf: 'center',
    borderWidth: 3,
    borderColor: COLORS.primary,
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
  actionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
    marginTop: 8,
  },
  actionRowPressed: { opacity: 0.6 },
  actionHint: { fontSize: 13, color: COLORS.text, fontWeight: '500', marginTop: 2 },
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
