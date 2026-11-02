import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text } from 'react-native';

import { COLORS } from '@/config';

/**
 * Raccourci rond vers les documents d'un jeune (fiche sanitaire, autorisation de rentrer seul, autorisations de sortie
 * du territoire de ses stages), pour le staff : depuis l'annuaire des adhérents ou un groupe d'entraînement, deux appuis suffisent pour ouvrir la fiche sanitaire.
 */
export function YouthDocsButton({ youthId, name }: { youthId: number; name: string }) {
  const router = useRouter();
  return (
    <Pressable
      onPress={() => router.push(('/youth-documents/' + youthId) as never)}
      accessibilityRole="button"
      accessibilityLabel={`Documents de ${name} : fiche sanitaire, autorisation de rentrer seul`}
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
