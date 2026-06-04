import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, auth as authApi } from '@/api/client';
import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';
import { accountTypeColor, accountTypeLabel, canSeePoolBadge, profileColor, profileLabel, sortProfiles, subTypeLabel } from '@/utils/profile';

const AVATAR_SIZE = 96;

export default function ProfileScreen() {
  const { user, signOut, refreshMe } = useAuth();
  const router = useRouter();
  const [uploading, setUploading] = useState(false);

  if (!user) return null;

  const hasBackendAccess = user.role === 'admin' || user.role === 'entraineur' || user.role === 'editeur';
  const backendRoleLabel =
    user.role === 'admin' ? 'Admin'
    : user.role === 'entraineur' ? 'Entraîneur (backend)'
    : user.role === 'editeur' ? 'Éditeur (backend)'
    : null;
  const profiles = sortProfiles(user.profiles ?? []);
  const showPoolBadge = canSeePoolBadge(user);
  // Section « Mes enfants » : visible pour tout compte qui s'identifie
  // comme parent (profile Parent OU parent externe).
  const showChildrenManager = user.profiles.includes('parent') || (user.type === 'externe' && user.subType === 'parent');

  async function pickAvatar() {
    if (uploading) return;

    // Demande permission caméra/galerie (no-op sur le web)
    if (Platform.OS !== 'web') {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('Permission refusée', 'Autorise l\'accès aux photos dans les réglages.');
        return;
      }
    }

    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      allowsEditing: true,
      aspect: [1, 1],
      quality: 0.8,
    });
    if (result.canceled || result.assets.length === 0) return;

    const asset = result.assets[0];
    setUploading(true);
    try {
      const mimeType = asset.mimeType ?? 'image/jpeg';
      const ext = mimeType.includes('png') ? 'png' : (mimeType.includes('webp') ? 'webp' : 'jpg');
      await authApi.uploadAvatar(asset.uri, mimeType, `avatar.${ext}`);
      await refreshMe();
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Échec de l\'upload';
      Alert.alert('Erreur', msg);
    } finally {
      setUploading(false);
    }
  }

  async function removeAvatar() {
    const doRemove = async () => {
      setUploading(true);
      try {
        await authApi.deleteAvatar();
        await refreshMe();
      } catch (e) {
        Alert.alert('Erreur', e instanceof Error ? e.message : 'Échec de la suppression');
      } finally {
        setUploading(false);
      }
    };
    if (Platform.OS === 'web') {
      if (typeof window !== 'undefined' && window.confirm('Supprimer la photo de profil ?')) {
        await doRemove();
      }
      return;
    }
    Alert.alert('Supprimer la photo ?', '', [
      { text: 'Annuler', style: 'cancel' },
      { text: 'Supprimer', style: 'destructive', onPress: doRemove },
    ]);
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <Pressable onPress={pickAvatar} disabled={uploading} style={styles.avatarPressable}>
          {uploading ? (
            <View style={styles.avatar}><ActivityIndicator color="#fff" /></View>
          ) : user.avatarUrl ? (
            <Image source={{ uri: user.avatarUrl }} style={styles.avatar} contentFit="cover" />
          ) : (
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>
                {user.prenom.charAt(0)}
                {user.nom.charAt(0)}
              </Text>
            </View>
          )}
          <View style={styles.avatarEditBadge}>
            <Ionicons name="camera" size={14} color="#fff" />
          </View>
        </Pressable>

        {user.avatarUrl && !uploading && (
          <Pressable onPress={removeAvatar} hitSlop={6}>
            <Text style={styles.removeAvatarLabel}>Supprimer la photo</Text>
          </Pressable>
        )}

        <Text style={styles.name}>{user.fullName}</Text>
        <Text style={styles.email}>{user.email}</Text>

        <View style={styles.badgeRow}>
          <Badge color={accountTypeColor(user.type)} label={accountTypeLabel(user.type)} />
          {user.isDirigeant && <Badge color="#f59e0b" label="Dirigeant" />}
          {hasBackendAccess && backendRoleLabel && <Badge color="#D32F2F" label={backendRoleLabel} />}
          {profiles.map((p) => (
            <Badge key={p} color={profileColor(p)} label={profileLabel(p)} />
          ))}
        </View>
      </View>

      <View style={styles.card}>
        <Row label="N° de licence" value={user.licenceLabel} />
        <Row label="Statut" value={subTypeLabel(user.subType)} />
        {user.categorieFFTri && <Row label="Catégorie FFTri" value={user.categorieFFTri} />}

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

      {showPoolBadge && (
        <View style={styles.card}>
          <Pressable
            style={({ pressed }) => [styles.actionRow, pressed && styles.actionRowPressed, { borderTopWidth: 0 }]}
            onPress={() => router.push('/pool-badge' as never)}
          >
            <View style={styles.qrIcon}>
              <Ionicons name="qr-code-outline" size={22} color="#fff" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.rowLabel}>Accès piscines</Text>
              <Text style={styles.actionHint}>QR code à présenter à l'entrée</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        </View>
      )}

      {(user.profiles.includes('encadrant') || user.profiles.includes('entraineur')) && (
        <View style={styles.card}>
          <Pressable
            style={({ pressed }) => [styles.actionRow, pressed && styles.actionRowPressed, { borderTopWidth: 0 }]}
            onPress={() => router.push('/staff-presence' as never)}
          >
            <View style={{ flex: 1 }}>
              <Text style={styles.rowLabel}>Mes présences</Text>
              <Text style={styles.actionHint}>
                Réserver / confirmer ma présence sur les créneaux que j'encadre
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        </View>
      )}

      {showChildrenManager && (
        <View style={styles.card}>
          <Pressable
            style={({ pressed }) => [styles.actionRow, pressed && styles.actionRowPressed, { borderTopWidth: 0 }]}
            onPress={() => router.push('/profile/children' as never)}
          >
            <View style={styles.qrIcon}>
              <Ionicons name="people-outline" size={22} color="#fff" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.rowLabel}>Mes enfants</Text>
              <Text style={styles.actionHint}>
                Lier un enfant adhérent par n° de licence pour basculer vers son profil
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
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
  avatarPressable: {
    width: AVATAR_SIZE,
    height: AVATAR_SIZE,
    marginBottom: 8,
    alignSelf: 'center',
    position: 'relative',
  },
  avatar: {
    width: AVATAR_SIZE,
    height: AVATAR_SIZE,
    borderRadius: AVATAR_SIZE / 2,
    backgroundColor: COLORS.brandNavy,
    justifyContent: 'center',
    alignItems: 'center',
    borderWidth: 3,
    borderColor: COLORS.primary,
    overflow: 'hidden',
  },
  avatarText: { color: '#fff', fontSize: 32, fontWeight: '700' },
  avatarEditBadge: {
    position: 'absolute',
    right: 0,
    bottom: 4,
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: COLORS.primary,
    borderWidth: 2,
    borderColor: COLORS.surface,
    justifyContent: 'center',
    alignItems: 'center',
  },
  removeAvatarLabel: {
    color: COLORS.textMuted,
    fontSize: 12,
    textAlign: 'center',
    marginBottom: 4,
    textDecorationLine: 'underline',
  },
  name: { fontSize: 20, fontWeight: '700', color: COLORS.text, textAlign: 'center', marginTop: 4 },
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
  qrIcon: {
    width: 36,
    height: 36,
    borderRadius: 8,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12,
  },
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
