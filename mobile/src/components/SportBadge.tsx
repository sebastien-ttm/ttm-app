import { StyleSheet, Text, View } from 'react-native';

import { RADIUS } from '@/config';

type Props = {
  icon: string;
  label: string;
  color: string;
  size?: 'sm' | 'md';
};

/**
 * Badge coloré pour identifier le sport d'un créneau.
 * La couleur vient du backend (enum Sport::color()).
 */
export function SportBadge({ icon, label, color, size = 'md' }: Props) {
  const isSm = size === 'sm';
  return (
    <View
      style={[
        styles.root,
        isSm ? styles.sm : styles.md,
        { backgroundColor: hexToRgba(color, 0.12), borderColor: hexToRgba(color, 0.35) },
      ]}
    >
      <Text style={[styles.icon, isSm && styles.iconSm]}>{icon}</Text>
      <Text style={[styles.label, isSm && styles.labelSm, { color }]}>{label}</Text>
    </View>
  );
}

function hexToRgba(hex: string, alpha: number): string {
  const h = hex.replace('#', '');
  const r = parseInt(h.substring(0, 2), 16);
  const g = parseInt(h.substring(2, 4), 16);
  const b = parseInt(h.substring(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

const styles = StyleSheet.create({
  root: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderRadius: RADIUS.full,
    gap: 4,
  },
  sm: { paddingHorizontal: 8, paddingVertical: 2 },
  md: { paddingHorizontal: 10, paddingVertical: 4 },
  icon: { fontSize: 14 },
  iconSm: { fontSize: 12 },
  label: { fontWeight: '700', fontSize: 13 },
  labelSm: { fontSize: 11 },
});
