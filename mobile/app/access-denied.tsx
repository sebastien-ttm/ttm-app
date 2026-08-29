import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Page « Contenu non autorisé » — affichée quand un user connecté tente
 * d'ouvrir un contenu (article, page, plan) qu'il n'a pas le droit
 * de voir (audience filter côté serveur → 404, ou 403 explicite).
 *
 * Optionnel : ?reason=... personnalise le message.
 */
export default function AccessDeniedScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ reason?: string }>();
  const reason = params.reason ?? 'not-found';

  const message = reason === 'not-found'
    ? "Ce contenu n'existe pas, ou n'est pas accessible depuis votre compte."
    : reason === 'forbidden'
      ? 'Vous n\'avez pas les droits nécessaires pour voir ce contenu.'
      : 'Contenu non disponible.';

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Accès refusé' }} />
      <View style={styles.center}>
        <View style={styles.iconWrap}>
          <Ionicons name="lock-closed" size={48} color={COLORS.primary} />
        </View>
        <Text style={styles.title}>Contenu non autorisé</Text>
        <Text style={styles.message}>{message}</Text>
        <Text style={styles.hint}>
          Si vous pensez qu'il s'agit d'une erreur, contactez l'équipe du club.
        </Text>

        <Pressable
          style={({ pressed }) => [styles.button, pressed && { opacity: 0.7 }]}
          onPress={() => router.replace('/(tabs)' as never)}
        >
          <Text style={styles.buttonLabel}>Retour à l'accueil</Text>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: SPACING.xl,
  },
  iconWrap: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: COLORS.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: SPACING.lg,
  },
  title: {
    fontSize: 22,
    fontWeight: '700',
    color: COLORS.text,
    marginBottom: SPACING.sm,
    textAlign: 'center',
  },
  message: {
    fontSize: 15,
    color: COLORS.text,
    textAlign: 'center',
    lineHeight: 22,
    marginBottom: SPACING.sm,
    maxWidth: 400,
  },
  hint: {
    fontSize: 13,
    color: COLORS.textMuted,
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: SPACING.xl,
    maxWidth: 400,
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 12,
    paddingHorizontal: SPACING.xl,
  },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
});
