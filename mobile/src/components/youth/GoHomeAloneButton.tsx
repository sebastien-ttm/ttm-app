import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text } from 'react-native';

import { openAttachment, withBearer } from '@/lib/openAttachment';

/**
 * Raccourci rond vers l'autorisation de rentrer seul d'un jeune, pour l'encadrant d'un groupe en fin de séance. Couleur
 * selon l'état : vert (déposée, s'ouvre directement), orange (expirée, s'ouvre aussi), rouge (manquante : ouvre la fiche
 * documents du jeune pour la déposer).
 */
export function GoHomeAloneButton({ youthId, name, doc }: {
  youthId: number;
  name: string;
  doc: { url: string; expired: boolean } | null | undefined;
}) {
  const router = useRouter();
  const state = !doc ? 'missing' : doc.expired ? 'expired' : 'ok';
  const status = state === 'ok' ? 'ouvrir' : state === 'expired' ? 'expirée' : 'manquante';

  return (
    <Pressable
      onPress={() => (doc
        ? void openAttachment((token) => withBearer(doc.url, token))
        : router.push(('/youth-documents/' + youthId) as never))}
      accessibilityRole="button"
      accessibilityLabel={`Autorisation de rentrer seul de ${name} : ${status}`}
      style={({ pressed }) => [styles.btn, styles[state], pressed && { opacity: 0.7 }]}
    >
      <Text style={styles.icon}>🏠</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: {
    width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center', borderWidth: 1.5,
  },
  ok: { backgroundColor: '#dcfce7', borderColor: '#16a34a' },
  expired: { backgroundColor: '#ffedd5', borderColor: '#ea580c' },
  missing: { backgroundColor: '#fee2e2', borderColor: '#dc2626' },
  icon: { fontSize: 17 },
});
