import { Pressable, StyleSheet, Text } from 'react-native';

import type { ArticleAttachment } from '@/api/types';
import { COLORS } from '@/config';
import { openAttachment, withBearer } from '@/lib/openAttachment';

/**
 * Pièce jointe (PDF, document…) cliquable : s'ouvre dans un nouvel
 * onglet sur le web, dans le navigateur intégré sur mobile. Partagée
 * par les articles et les pages statiques (même forme d'objet servi par
 * un endpoint authentifié, jeton passé en `?bearer=`).
 */
export function AttachmentLink({ attachment }: { attachment: ArticleAttachment }) {
  async function open() {
    await openAttachment((token) => withBearer(attachment.url, token));
  }
  return (
    <Pressable onPress={open} style={({ pressed }) => [styles.chip, pressed && styles.pressed]}>
      <Text style={styles.icon}>📎</Text>
      <Text style={styles.name} numberOfLines={1}>{attachment.name}</Text>
      <Text style={styles.size}>{attachment.humanSize}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    padding: 10,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  icon: { fontSize: 16 },
  name: { flex: 1, fontSize: 14, color: COLORS.text, fontWeight: '500' },
  size: { fontSize: 12, color: COLORS.textMuted },
  pressed: { opacity: 0.6 },
});
