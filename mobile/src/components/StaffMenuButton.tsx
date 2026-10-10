import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canUseStaffSpace } from '@/utils/profile';

/**
 * Bouton « Staff » de l'en-tête, à gauche du changement de profil lié :
 * ouvre l'espace staff (présences, émargements, annuaire des adhérents).
 * Visible pour les profils Entraîneur et Encadrant et pour le CoDir.
 */
export function StaffMenuButton() {
  const { user } = useAuth();
  const router = useRouter();

  if (!canUseStaffSpace(user)) {
    return null;
  }

  return (
    <Pressable
      onPress={() => router.push('/gestion' as never)}
      accessibilityRole="button"
      accessibilityLabel="Espace Staff"
      style={({ pressed }) => [styles.trigger, pressed && styles.triggerPressed]}
    >
      <Ionicons name="briefcase" size={15} color={COLORS.secondary} />
      <Text style={styles.label} numberOfLines={1}>Staff</Text>
    </Pressable>
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
  label: { color: COLORS.secondaryDark, fontWeight: '700', fontSize: 13 },
});
