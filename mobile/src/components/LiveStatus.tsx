import { StyleSheet, Text, View } from 'react-native';

import { COLORS } from '@/config';

/**
 * Petit indicateur de synchronisation en direct des écrans du Staff : vert « En direct »
 * quand l'écran se met à jour tout seul, orange « Hors ligne » quand le réseau manque
 * (les nouvelles tentatives continuent, rien n'est perdu).
 */
export function LiveStatus({ online }: { online: boolean }) {
  return (
    <View
      style={styles.row}
      accessibilityLabel={online ? 'Synchronisé en direct avec les autres' : 'Hors ligne, nouvelle tentative en cours'}
    >
      <View style={[styles.dot, online ? styles.dotOn : styles.dotOff]} />
      <Text style={[styles.label, !online && styles.labelOff]}>
        {online ? 'En direct' : 'Hors ligne — nouvelle tentative…'}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  dotOn: { backgroundColor: COLORS.success },
  dotOff: { backgroundColor: '#f59e0b' },
  label: { fontSize: 12, color: COLORS.textMuted, fontWeight: '600' },
  labelOff: { color: '#b45309' },
});
