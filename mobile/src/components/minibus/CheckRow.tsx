import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS } from '@/config';

/** Case à cocher avec son libellé (toute la ligne se touche). */
export function CheckRow({ label, hint, checked, onChange, disabled }: {
  label: string;
  hint?: string;
  checked: boolean;
  onChange: (value: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      onPress={() => onChange(!checked)}
      disabled={disabled}
      accessibilityRole="checkbox"
      accessibilityState={{ checked, disabled }}
      style={[styles.row, checked && styles.rowChecked, disabled && { opacity: 0.6 }]}
    >
      <Ionicons name={checked ? 'checkbox' : 'square-outline'} size={22} color={checked ? COLORS.secondary : COLORS.textMuted} />
      <View style={{ flex: 1 }}>
        <Text style={styles.label}>{label}</Text>
        {hint ? <Text style={styles.hint}>{hint}</Text> : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10, paddingHorizontal: 12,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  rowChecked: { borderColor: COLORS.secondary, backgroundColor: COLORS.secondarySoft },
  label: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  hint: { fontSize: 12, color: COLORS.textMuted, marginTop: 1 },
});
