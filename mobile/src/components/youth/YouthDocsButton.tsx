import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text } from 'react-native';

import { COLORS } from '@/config';

/**
 * Raccourci rond vers les documents officiels d'un jeune (fiche sanitaire, autorisation de sortie du territoire), pour le
 * staff : depuis l'annuaire des adhérents ou un groupe d'entraînement, deux appuis suffisent pour ouvrir la fiche sanitaire.
 */
export function YouthDocsButton({ youthId, name }: { youthId: number; name: string }) {
  const router = useRouter();
  return (
    <Pressable
      onPress={() => router.push(('/youth-documents/' + youthId) as never)}
      accessibilityRole="button"
      accessibilityLabel={`Fiche sanitaire et autorisation de sortie du territoire de ${name}`}
      style={({ pressed }) => [styles.btn, pressed && { opacity: 0.7 }]}
    >
      <Text style={styles.icon}>🩺</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: {
    width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center',
    backgroundColor: '#eff6ff', borderWidth: 1.5, borderColor: COLORS.secondaryDark,
  },
  icon: { fontSize: 17 },
});
