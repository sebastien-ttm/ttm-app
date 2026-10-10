import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Onglet « Présences » de l'espace Gestion : tout ce qui concerne la
 * présence du staff aux créneaux (indiquer / confirmer, semaine type).
 * Ces entrées étaient auparavant dans l'onglet Entraînements (« Mes
 * encadrements »).
 */
export function PresenceTab() {
  const router = useRouter();

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <Text style={styles.intro}>
        Indiquez les créneaux que vous encadrez ; les autres entraîneurs et encadrants voient votre présence.
      </Text>

      <Pressable
        style={({ pressed }) => [styles.card, pressed && { opacity: 0.7 }]}
        onPress={() => router.push('/staff-presence' as never)}
      >
        <View style={styles.iconWrap}>
          <Ionicons name="checkmark-circle" size={22} color="#fff" />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.title}>Indiquer / Confirmer</Text>
          <Text style={styles.sub}>pour les créneaux de la semaine et des suivantes</Text>
        </View>
        <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
      </Pressable>

      <Pressable
        style={({ pressed }) => [styles.card, styles.cardMuted, pressed && { opacity: 0.7 }]}
        onPress={() => router.push('/staff-presence-template' as never)}
      >
        <View style={[styles.iconWrap, styles.iconWrapMuted]}>
          <Ionicons name="settings" size={20} color="#fff" />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.title}>Ma semaine type</Text>
          <Text style={styles.sub}>Créneaux où vous êtes présent(e) habituellement</Text>
        </View>
        <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  intro: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18, marginBottom: SPACING.md },
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.primary,
  },
  cardMuted: { borderLeftColor: COLORS.textMuted },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 8,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconWrapMuted: { backgroundColor: COLORS.textMuted },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
});
