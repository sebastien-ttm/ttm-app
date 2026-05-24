import { Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS, SPACING } from '@/config';
import { addWeeks, formatWeekRange, getMonday, isSameWeek, toIsoDate } from '@/utils/week';

type Props = {
  /** Lundi de la semaine actuellement affichée (Date locale). */
  weekStart: Date;
  /** Appelée avec le nouveau lundi quand l'utilisateur navigue. */
  onChange: (newWeekStart: Date) => void;
  /** Si vrai, désactive le bouton "Précédente" (les adhérents n'ont pas accès au passé). */
  disablePast?: boolean;
};

export function WeekNavigator({ weekStart, onChange, disablePast = false }: Props) {
  const today = new Date();
  const todayMonday = getMonday(today);
  const isCurrentWeek = isSameWeek(weekStart, today);
  const prevDisabled = disablePast && toIsoDate(addWeeks(weekStart, -1)) < toIsoDate(todayMonday);

  return (
    <View style={styles.root}>
      <View style={styles.navRow}>
        <NavButton
          label="←"
          onPress={() => onChange(addWeeks(weekStart, -1))}
          disabled={prevDisabled}
        />
        <Pressable
          style={[styles.todayChip, isCurrentWeek && styles.todayChipActive]}
          onPress={() => onChange(todayMonday)}
        >
          <Text style={[styles.todayLabel, isCurrentWeek && styles.todayLabelActive]}>
            Aujourd'hui
          </Text>
        </Pressable>
        <NavButton label="→" onPress={() => onChange(addWeeks(weekStart, 1))} />
      </View>
      <Text style={styles.label}>{formatWeekRange(weekStart)}</Text>
    </View>
  );
}

function NavButton({
  label,
  onPress,
  disabled,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
}) {
  return (
    <Pressable
      onPress={disabled ? undefined : onPress}
      style={[styles.navBtn, disabled && styles.navBtnDisabled]}
    >
      <Text style={[styles.navLabel, disabled && styles.navLabelDisabled]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: {
    paddingHorizontal: SPACING.md,
    paddingVertical: SPACING.sm,
    backgroundColor: COLORS.surface,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
    gap: 6,
  },
  navRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  navBtn: {
    width: 42,
    height: 36,
    borderRadius: RADIUS.sm,
    backgroundColor: COLORS.surfaceAlt,
    borderWidth: 1,
    borderColor: COLORS.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  navBtnDisabled: { opacity: 0.35 },
  navLabel: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  navLabelDisabled: { color: COLORS.textSubtle },
  todayChip: {
    flex: 1,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
    backgroundColor: COLORS.surfaceAlt,
    borderWidth: 1,
    borderColor: COLORS.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  todayChipActive: {
    backgroundColor: COLORS.secondarySoft,
    borderColor: COLORS.secondary,
  },
  todayLabel: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  todayLabelActive: { color: COLORS.secondaryDark },
  label: { textAlign: 'center', fontSize: 13, color: COLORS.textMuted },
});
