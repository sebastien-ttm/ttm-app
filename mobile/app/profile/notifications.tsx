import { Stack } from 'expo-router';
import { Platform, ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { WebPushSettings } from '@/components/WebPushSettings';
import { COLORS, SPACING } from '@/config';

/**
 * Notifications push (appli web / installée) : activation sur cet appareil, choix des sujets, notification de test.
 * Écran à part, ouvert depuis la ligne « Notifications push » du profil. L'appli native gère ses notifications dans les
 * réglages du téléphone : la ligne du profil n'y est pas proposée, et un lien direct y explique où les régler.
 */
export default function ProfileNotificationsScreen() {
  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Notifications push' }} />
      <ScrollView contentContainerStyle={styles.content}>
        {Platform.OS === 'web' ? (
          <WebPushSettings />
        ) : (
          <Text style={styles.native}>
            Les notifications de l'application se règlent dans les réglages de votre téléphone (Notifications).
          </Text>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  native: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
});
