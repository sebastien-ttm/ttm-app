import { StyleSheet, Text, View } from 'react-native';

import { useServedFromCache } from '@/api/offlineCache';

/**
 * Bandeau affiché tant que l'appli montre les dernières données enregistrées sur l'appareil faute de réseau
 * (voir api/offlineCache). Disparaît dès qu'une requête aboutit de nouveau.
 */
export function OfflineBanner() {
  const offline = useServedFromCache();
  if (!offline) return null;

  return (
    <View style={styles.bar} accessibilityRole="alert">
      <Text style={styles.icon}>📡</Text>
      <Text style={styles.text}>Hors connexion — données de votre dernière visite. Elles se mettront à jour au retour du réseau.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 12,
    paddingVertical: 6,
    backgroundColor: '#e0f2fe',
    borderBottomWidth: 1,
    borderBottomColor: '#0284c7',
  },
  icon: { fontSize: 16 },
  text: { flex: 1, fontSize: 12, color: '#075985' },
});
