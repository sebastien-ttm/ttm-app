import { Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Sélecteur de vues d'un onglet de l'espace Staff (« Présences · Groupes · Émargement & tests »…) : un petit commutateur
 * segmenté sous les onglets. Rien n'est affiché quand il n'y a qu'une vue à proposer.
 */
export function SubTabs<K extends string>({ items, value, onChange }: {
  /** `badge` : pastille rouge facultative (nombre de choses à traiter), affichée si > 0. */
  items: { key: K; label: string; badge?: number }[];
  value: K;
  onChange: (key: K) => void;
}) {
  if (items.length < 2) return null;
  return (
    <View style={styles.wrap}>
      <View style={styles.switch} accessibilityRole="tablist">
        {items.map((v) => {
          const active = v.key === value;
          return (
            <Pressable
              key={v.key}
              onPress={() => onChange(v.key)}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              style={[styles.btn, active && styles.btnActive]}
            >
              {/* Deux lignes possibles : « Émargement & tests » ne tient pas sur une ligne sur un petit téléphone. */}
              <Text style={[styles.label, active && styles.labelActive]} numberOfLines={2}>{v.label}</Text>
              {v.badge ? (
                <View style={styles.badge} accessibilityLabel={`${v.badge} à traiter`}>
                  <Text style={styles.badgeLabel}>{v.badge > 9 ? '9+' : v.badge}</Text>
                </View>
              ) : null}
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { paddingHorizontal: SPACING.md, paddingTop: SPACING.xs, paddingBottom: SPACING.xs },
  switch: { flexDirection: 'row', backgroundColor: COLORS.border, borderRadius: RADIUS.md, padding: 3, gap: 3 },
  btn: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 8, paddingHorizontal: 4, borderRadius: RADIUS.sm },
  btnActive: { backgroundColor: COLORS.surface },
  badge: {
    position: 'absolute', top: 2, right: 4, minWidth: 18, height: 18, paddingHorizontal: 4,
    borderRadius: 9, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center',
  },
  badgeLabel: { color: '#fff', fontSize: 11, fontWeight: '800' },
  label: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textAlign: 'center' },
  labelActive: { color: COLORS.brandNavy },
});
