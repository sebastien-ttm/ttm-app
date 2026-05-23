import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Bouton dans le header qui affiche le prénom du profil courant et permet
 * de switcher entre les profils liés (parent + enfants partageant l'email).
 * S'affiche uniquement si le user a au moins 2 profils accessibles.
 */
export function ProfileSwitcher() {
  const { user, linkedProfiles, switchProfile } = useAuth();
  const [open, setOpen] = useState(false);
  const [switching, setSwitching] = useState<string | null>(null);

  if (!user || linkedProfiles.length < 2) {
    return null;
  }

  async function onSelect(numLicence: string) {
    if (switching) return;
    if (numLicence === user?.numLicence) {
      setOpen(false);
      return;
    }
    setSwitching(numLicence);
    try {
      await switchProfile(numLicence);
      setOpen(false);
    } finally {
      setSwitching(null);
    }
  }

  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        style={({ pressed }) => [styles.trigger, pressed && styles.triggerPressed]}
      >
        <Text style={styles.triggerLabel} numberOfLines={1}>
          {user.prenom}
        </Text>
        <Ionicons name="swap-horizontal" size={16} color={COLORS.secondary} />
      </Pressable>

      <Modal visible={open} transparent animationType="fade" onRequestClose={() => setOpen(false)}>
        <Pressable style={styles.backdrop} onPress={() => setOpen(false)}>
          <Pressable style={styles.sheet} onPress={(e) => e.stopPropagation()}>
            <Text style={styles.title}>Changer de profil</Text>
            <Text style={styles.subtitle}>
              Vous gérez {linkedProfiles.length} profils sur cette adresse e-mail.
            </Text>

            <ScrollView style={styles.list}>
              {linkedProfiles.map((p) => {
                const isActive = p.numLicence === user.numLicence;
                const isLoading = switching === p.numLicence;
                return (
                  <Pressable
                    key={p.numLicence}
                    style={({ pressed }) => [
                      styles.item,
                      isActive && styles.itemActive,
                      pressed && !isActive && styles.itemPressed,
                    ]}
                    onPress={() => onSelect(p.numLicence)}
                    disabled={isLoading}
                  >
                    <View style={styles.itemAvatar}>
                      <Text style={styles.itemAvatarText}>{p.prenom.charAt(0)}</Text>
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.itemName}>{p.fullName}</Text>
                      <Text style={styles.itemMeta}>
                        {p.categorieAge ?? (p.categorie === 'jeune' ? 'Jeune' : 'Sénior')}
                        {p.isPrimary ? ' · Compte principal' : ''}
                      </Text>
                    </View>
                    {isLoading ? (
                      <ActivityIndicator color={COLORS.secondary} />
                    ) : isActive ? (
                      <Ionicons name="checkmark-circle" size={22} color={COLORS.secondary} />
                    ) : (
                      <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
                    )}
                  </Pressable>
                );
              })}
            </ScrollView>

            <Pressable style={styles.cancel} onPress={() => setOpen(false)}>
              <Text style={styles.cancelLabel}>Annuler</Text>
            </Pressable>
          </Pressable>
        </Pressable>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  trigger: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: RADIUS.full,
    backgroundColor: COLORS.secondarySoft,
    marginRight: SPACING.sm,
  },
  triggerPressed: { opacity: 0.7 },
  triggerLabel: {
    color: COLORS.secondaryDark,
    fontWeight: '700',
    fontSize: 13,
    maxWidth: 100,
  },
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.45)',
    justifyContent: 'center',
    padding: SPACING.lg,
  },
  sheet: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.xl,
    padding: SPACING.lg,
    maxWidth: 420,
    width: '100%',
    alignSelf: 'center',
    maxHeight: '80%',
    ...SHADOWS.md,
  },
  title: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  subtitle: { fontSize: 13, color: COLORS.textMuted, marginTop: 4, marginBottom: SPACING.md },
  list: { maxHeight: 360 },
  item: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACING.md,
    padding: SPACING.md,
    borderRadius: RADIUS.md,
    backgroundColor: COLORS.surfaceAlt,
    borderWidth: 1,
    borderColor: COLORS.border,
    marginBottom: 8,
  },
  itemActive: {
    backgroundColor: COLORS.secondarySoft,
    borderColor: COLORS.secondary,
  },
  itemPressed: { opacity: 0.7 },
  itemAvatar: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: COLORS.brandNavy,
    justifyContent: 'center',
    alignItems: 'center',
  },
  itemAvatarText: { color: '#fff', fontSize: 16, fontWeight: '700' },
  itemName: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  itemMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  cancel: { alignItems: 'center', paddingVertical: SPACING.md, marginTop: SPACING.sm },
  cancelLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
