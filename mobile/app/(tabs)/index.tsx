import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';

export default function FeedScreen() {
  const { user } = useAuth();

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.welcome}>
        <Text style={styles.welcomeTitle}>Bonjour {user?.prenom} 👋</Text>
        <Text style={styles.welcomeSubtitle}>Le flux d'actualités arrive dans le prochain commit.</Text>
      </View>

      <View style={styles.placeholder}>
        <Text style={styles.placeholderTitle}>📰 Bientôt ici</Text>
        <Text style={styles.placeholderText}>
          Articles, photos, réactions, commentaires — tout le mur d'actualités du club.
        </Text>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: 16 },
  welcome: { backgroundColor: COLORS.surface, padding: 16, borderRadius: 12, marginBottom: 16 },
  welcomeTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  welcomeSubtitle: { fontSize: 14, color: COLORS.textMuted, marginTop: 4 },
  placeholder: {
    backgroundColor: COLORS.surface,
    padding: 24,
    borderRadius: 12,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: COLORS.border,
    borderStyle: 'dashed',
  },
  placeholderTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text, marginBottom: 8 },
  placeholderText: { fontSize: 14, color: COLORS.textMuted, textAlign: 'center', lineHeight: 20 },
});
