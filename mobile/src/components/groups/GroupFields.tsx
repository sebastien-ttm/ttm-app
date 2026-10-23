import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS } from '@/config';

/** L'échelle du club : 5 allures (1 à 5). */
export const PACE_LEVELS = [1, 2, 3, 4, 5] as const;

/**
 * Allure du club (1 à 5) et vitesse d'un groupe, en une pastille : « Allure 3 · 28 km/h ». Rien si ni l'une ni l'autre
 * n'est renseignée.
 */
export function PaceBadge({ level, speed }: { level: number | null; speed: string | null }) {
  if (level === null && !speed) return null;
  return (
    <View style={styles.badge} accessible accessibilityLabel={`${level !== null ? `Allure ${level}` : ''}${level !== null && speed ? ', ' : ''}${speed ?? ''}`}>
      {level !== null && <Text style={styles.badgeLevel}>Allure {level}</Text>}
      {speed ? <Text style={styles.badgeSpeed}>{level !== null ? ' · ' : ''}{speed}</Text> : null}
    </View>
  );
}

/** Choix de l'allure du club : 1 à 5 ; un second appui sur l'allure choisie la retire (« non précisée »). */
export function PaceLevelPicker({ value, onChange, disabled }: { value: number | null; onChange: (next: number | null) => void; disabled?: boolean }) {
  return (
    <View style={styles.levels} accessibilityRole="radiogroup">
      {PACE_LEVELS.map((n) => {
        const on = value === n;
        return (
          <Pressable
            key={n}
            disabled={disabled}
            onPress={() => onChange(on ? null : n)}
            accessibilityRole="radio"
            accessibilityState={{ checked: on, disabled }}
            accessibilityLabel={`Allure ${n}`}
            style={({ pressed }) => [styles.level, on && styles.levelOn, pressed && { opacity: 0.8 }]}
          >
            <Text style={[styles.levelLabel, on && styles.levelLabelOn]}>{n}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Nombre de places : − / valeur / +, entre `min` et `max`. */
export function CapacityStepper({ value, onChange, min = 1, max = 80, disabled }: {
  value: number;
  onChange: (next: number) => void;
  min?: number;
  max?: number;
  disabled?: boolean;
}) {
  return (
    <View style={styles.stepper}>
      <Pressable
        disabled={disabled || value <= min}
        onPress={() => onChange(Math.max(min, value - 1))}
        accessibilityRole="button"
        accessibilityLabel="Une place de moins"
        style={({ pressed }) => [styles.stepBtn, (disabled || value <= min) && { opacity: 0.35 }, pressed && { opacity: 0.7 }]}
      >
        <Ionicons name="remove" size={20} color={COLORS.primary} />
      </Pressable>
      <Text style={styles.stepValue} accessibilityLabel={`${value} places`}>{value}</Text>
      <Pressable
        disabled={disabled || value >= max}
        onPress={() => onChange(Math.min(max, value + 1))}
        accessibilityRole="button"
        accessibilityLabel="Une place de plus"
        style={({ pressed }) => [styles.stepBtn, (disabled || value >= max) && { opacity: 0.35 }, pressed && { opacity: 0.7 }]}
      >
        <Ionicons name="add" size={20} color={COLORS.primary} />
      </Pressable>
      <Text style={styles.stepUnit}>place{value > 1 ? 's' : ''}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start',
    paddingHorizontal: 9, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.secondary,
  },
  badgeLevel: { fontSize: 12, fontWeight: '800', color: '#fff' },
  badgeSpeed: { fontSize: 12, fontWeight: '700', color: '#fff' },
  levels: { flexDirection: 'row', gap: 8 },
  level: {
    width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.surface, borderWidth: 1.5, borderColor: COLORS.border,
  },
  levelOn: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  levelLabel: { fontSize: 17, fontWeight: '800', color: COLORS.text },
  levelLabelOn: { color: '#fff' },
  stepper: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  stepBtn: {
    width: 40, height: 40, borderRadius: RADIUS.md, alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.surface, borderWidth: 1.5, borderColor: COLORS.primary,
  },
  stepValue: { minWidth: 36, textAlign: 'center', fontSize: 20, fontWeight: '800', color: COLORS.text },
  stepUnit: { fontSize: 14, color: COLORS.textMuted },
});
