import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import { COLORS } from '@/config';

export function FullScreenLoading() {
  return (
    <View style={styles.container}>
      <ActivityIndicator size="large" color={COLORS.primary} />
    </View>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.container}>
      <Text style={styles.errorIcon}>⚠️</Text>
      <Text style={styles.errorMessage}>{message}</Text>
      {onRetry && (
        <Text onPress={onRetry} style={styles.retry}>
          Réessayer
        </Text>
      )}
    </View>
  );
}

export function EmptyState({ icon, title, message }: { icon: string; title: string; message?: string }) {
  return (
    <View style={styles.container}>
      <Text style={styles.emptyIcon}>{icon}</Text>
      <Text style={styles.emptyTitle}>{title}</Text>
      {message && <Text style={styles.emptyMessage}>{message}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    padding: 32,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorIcon: { fontSize: 36, marginBottom: 12 },
  errorMessage: { color: COLORS.error, textAlign: 'center', fontSize: 14 },
  retry: { color: COLORS.primary, fontWeight: '700', fontSize: 15, marginTop: 12, padding: 8 },
  emptyIcon: { fontSize: 48, marginBottom: 12 },
  emptyTitle: { fontSize: 17, fontWeight: '700', color: COLORS.text, marginBottom: 4 },
  emptyMessage: { fontSize: 14, color: COLORS.textMuted, textAlign: 'center' },
});
