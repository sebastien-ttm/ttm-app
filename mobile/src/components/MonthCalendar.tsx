import Ionicons from '@expo/vector-icons/Ionicons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { EventItem } from '@/api/types';
import { COLORS } from '@/config';

type Props = {
  /** First day of the displayed month (any time of day, will be normalized) */
  visibleMonth: Date;
  /** All events. Will be filtered to the visible month. */
  events: EventItem[];
  /** Currently selected day, or null. */
  selectedDate: Date | null;
  onChangeMonth: (newFirstOfMonth: Date) => void;
  onSelectDate: (date: Date | null) => void;
};

const WEEKDAY_LABELS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

const MONTH_NAMES = [
  'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
  'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
];

/** Convert Date → "YYYY-MM-DD" using local time */
function toLocalIso(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

function addMonths(d: Date, delta: number): Date {
  return new Date(d.getFullYear(), d.getMonth() + delta, 1);
}

/** Day-of-week as Mon=0..Sun=6 (vs JS native Sun=0..Sat=6) */
function dayIndexFromMonday(d: Date): number {
  const js = d.getDay();
  return (js + 6) % 7;
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function MonthCalendar({ visibleMonth, events, selectedDate, onChangeMonth, onSelectDate }: Props) {
  const firstOfMonth = startOfMonth(visibleMonth);
  const today = new Date();

  const cells = useMemo(() => {
    const startOffset = dayIndexFromMonday(firstOfMonth);
    const gridStart = new Date(firstOfMonth);
    gridStart.setDate(1 - startOffset);
    const out: Date[] = [];
    for (let i = 0; i < 42; i++) {
      const d = new Date(gridStart);
      d.setDate(gridStart.getDate() + i);
      out.push(d);
    }
    return out;
  }, [firstOfMonth]);

  const eventsByDay = useMemo(() => {
    const m = new Map<string, EventItem[]>();
    for (const e of events) {
      const date = new Date(e.startsAt);
      const key = toLocalIso(date);
      const arr = m.get(key);
      if (arr) arr.push(e);
      else m.set(key, [e]);
    }
    return m;
  }, [events]);

  const monthLabel = `${MONTH_NAMES[firstOfMonth.getMonth()]} ${firstOfMonth.getFullYear()}`;

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Pressable onPress={() => onChangeMonth(addMonths(firstOfMonth, -1))} hitSlop={12} style={styles.arrowButton}>
          <Ionicons name="chevron-back" size={20} color={COLORS.primary} />
        </Pressable>
        <Pressable onPress={() => { onChangeMonth(startOfMonth(today)); onSelectDate(null); }}>
          <Text style={styles.monthTitle}>{monthLabel}</Text>
        </Pressable>
        <Pressable onPress={() => onChangeMonth(addMonths(firstOfMonth, 1))} hitSlop={12} style={styles.arrowButton}>
          <Ionicons name="chevron-forward" size={20} color={COLORS.primary} />
        </Pressable>
      </View>

      <View style={styles.weekdaysRow}>
        {WEEKDAY_LABELS.map((label, i) => (
          <View key={label} style={styles.weekdayCell}>
            <Text style={[styles.weekdayLabel, i >= 5 && styles.weekendLabel]}>{label}</Text>
          </View>
        ))}
      </View>

      <View style={styles.grid}>
        {cells.map((day, i) => {
          const isCurrentMonth = day.getMonth() === firstOfMonth.getMonth();
          const isToday = isSameDay(day, today);
          const isSelected = selectedDate ? isSameDay(day, selectedDate) : false;
          const dayEvents = eventsByDay.get(toLocalIso(day)) ?? [];
          const visibleEvents = dayEvents.slice(0, 2);
          const overflow = dayEvents.length - visibleEvents.length;

          return (
            <Pressable
              key={i}
              style={styles.dayCell}
              onPress={() => onSelectDate(isSelected ? null : day)}
            >
              <View
                style={[
                  styles.dayInner,
                  isToday && !isSelected && styles.dayToday,
                  isSelected && styles.daySelected,
                ]}
              >
                <Text
                  style={[
                    styles.dayNumber,
                    !isCurrentMonth && styles.dayOutsideMonth,
                    isToday && !isSelected && styles.dayTodayText,
                    isSelected && styles.daySelectedText,
                  ]}
                >
                  {day.getDate()}
                </Text>
                <View style={styles.chips}>
                  {visibleEvents.map((e, idx) => (
                    <View
                      key={idx}
                      style={[
                        styles.chip,
                        { backgroundColor: e.color },
                        isSelected && styles.chipSelected,
                      ]}
                    >
                      <Text style={styles.chipText} numberOfLines={1}>
                        {e.title}
                      </Text>
                    </View>
                  ))}
                  {overflow > 0 && (
                    <Text
                      style={[styles.overflowLabel, isSelected && styles.overflowLabelSelected]}
                      numberOfLines={1}
                    >
                      +{overflow}
                    </Text>
                  )}
                </View>
              </View>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    backgroundColor: COLORS.surface,
    margin: 12,
    borderRadius: 12,
    padding: 12,
    elevation: 1,
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 4,
    marginBottom: 8,
  },
  arrowButton: { padding: 6, borderRadius: 8 },
  monthTitle: {
    fontSize: 17,
    fontWeight: '700',
    color: COLORS.text,
    textTransform: 'capitalize',
  },
  weekdaysRow: { flexDirection: 'row', marginBottom: 4 },
  weekdayCell: { flex: 1, alignItems: 'center', paddingVertical: 4 },
  weekdayLabel: {
    fontSize: 11,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
  },
  weekendLabel: { color: COLORS.primary },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  dayCell: {
    width: `${100 / 7}%`,
    height: 64,
    padding: 2,
  },
  dayInner: {
    flex: 1,
    borderRadius: 6,
    paddingVertical: 3,
    paddingHorizontal: 2,
    backgroundColor: 'transparent',
    overflow: 'hidden',
  },
  dayToday: { backgroundColor: '#FFE6E6' },
  daySelected: { backgroundColor: COLORS.primary },
  dayNumber: {
    fontSize: 12,
    color: COLORS.text,
    fontWeight: '600',
    textAlign: 'center',
    marginBottom: 2,
  },
  dayOutsideMonth: { color: '#bbb' },
  dayTodayText: { color: COLORS.primary, fontWeight: '700' },
  daySelectedText: { color: '#fff', fontWeight: '700' },
  chips: { gap: 1 },
  chip: {
    borderRadius: 3,
    paddingHorizontal: 3,
    paddingVertical: 1,
  },
  chipSelected: { opacity: 0.85 },
  chipText: {
    color: '#fff',
    fontSize: 9,
    fontWeight: '600',
  },
  overflowLabel: {
    fontSize: 9,
    color: COLORS.textMuted,
    textAlign: 'center',
    marginTop: 1,
  },
  overflowLabelSelected: { color: '#fff' },
});
