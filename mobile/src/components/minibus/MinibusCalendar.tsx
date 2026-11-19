import Ionicons from '@expo/vector-icons/Ionicons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { MinibusBooking } from '@/api/types';
import { COLORS, RADIUS } from '@/config';
import { MINIBUS_STATUS_STYLE, coversDay, isLiveBooking, isoOfDate } from '@/utils/minibus';

const WEEKDAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
/** Barres affichées par jour avant « +N » (la case est étroite). */
const MAX_CHIPS = 2;

/** Premier jour du mois affiché ↦ 42 cases (6 semaines, lundi en tête). */
function monthCells(firstOfMonth: Date): Date[] {
  const offset = (firstOfMonth.getDay() + 6) % 7;
  const start = new Date(firstOfMonth.getFullYear(), firstOfMonth.getMonth(), 1 - offset);
  return Array.from({ length: 42 }, (_, i) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + i));
}

/**
 * Calendrier mensuel du minibus : chaque jour montre les demandes qui le couvrent — validées en bleu, en attente en
 * orange (nom de l'emprunteur). Toucher un jour le sélectionne (la liste du jour s'affiche dessous), le toucher à
 * nouveau le désélectionne. Les demandes refusées ou annulées n'occupent pas le calendrier.
 */
export function MinibusCalendar({ bookings, month, selected, onChangeMonth, onSelect }: {
  bookings: MinibusBooking[];
  /** N'importe quel jour du mois affiché. */
  month: Date;
  /** Jour sélectionné (AAAA-MM-JJ) ou null. */
  selected: string | null;
  onChangeMonth: (firstOfMonth: Date) => void;
  onSelect: (iso: string | null) => void;
}) {
  const year = month.getFullYear();
  const monthIndex = month.getMonth();
  const first = new Date(year, monthIndex, 1);
  const cells = useMemo(() => monthCells(new Date(year, monthIndex, 1)), [year, monthIndex]);
  const live = useMemo(() => bookings.filter(isLiveBooking), [bookings]);
  const todayIso = isoOfDate(new Date());
  const shift = (delta: number) => onChangeMonth(new Date(first.getFullYear(), first.getMonth() + delta, 1));

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Pressable onPress={() => shift(-1)} hitSlop={12} style={styles.arrow} accessibilityLabel="Mois précédent">
          <Ionicons name="chevron-back" size={20} color={COLORS.secondary} />
        </Pressable>
        <Pressable onPress={() => { onChangeMonth(new Date()); onSelect(null); }} accessibilityLabel="Revenir à aujourd'hui">
          <Text style={styles.monthTitle}>{MONTHS[first.getMonth()]} {first.getFullYear()}</Text>
        </Pressable>
        <Pressable onPress={() => shift(1)} hitSlop={12} style={styles.arrow} accessibilityLabel="Mois suivant">
          <Ionicons name="chevron-forward" size={20} color={COLORS.secondary} />
        </Pressable>
      </View>

      <View style={styles.weekdays}>
        {WEEKDAYS.map((d, i) => (
          <View key={d} style={styles.weekday}>
            <Text style={[styles.weekdayLabel, i >= 5 && { color: COLORS.primary }]}>{d}</Text>
          </View>
        ))}
      </View>

      <View style={styles.grid}>
        {cells.map((day) => {
          const iso = isoOfDate(day);
          const inMonth = day.getMonth() === first.getMonth();
          const isToday = iso === todayIso;
          const isSelected = iso === selected;
          const here = live.filter((b) => coversDay(b, iso));
          const shown = here.slice(0, MAX_CHIPS);
          const overflow = here.length - shown.length;
          return (
            <Pressable key={iso} style={styles.cell} onPress={() => onSelect(isSelected ? null : iso)} accessibilityLabel={`${iso}${here.length ? `, ${here.length} réservation${here.length > 1 ? 's' : ''}` : ''}`}>
              <View style={[styles.cellInner, isToday && !isSelected && styles.today, isSelected && styles.selected]}>
                <Text style={[styles.dayNumber, !inMonth && styles.outside, isToday && !isSelected && styles.todayText, isSelected && styles.selectedText]}>
                  {day.getDate()}
                </Text>
                {shown.map((b) => (
                  <View key={b.id} style={[styles.chip, { backgroundColor: MINIBUS_STATUS_STYLE[b.status].bar }, !inMonth && { opacity: 0.45 }]}>
                    <Text style={styles.chipText} numberOfLines={1}>{b.requesterName.split(' ')[0]}</Text>
                  </View>
                ))}
                {overflow > 0 && <Text style={[styles.overflow, isSelected && { color: '#fff' }]}>+{overflow}</Text>}
              </View>
            </Pressable>
          );
        })}
      </View>

      <View style={styles.legend}>
        <View style={styles.legendItem}><View style={[styles.legendDot, { backgroundColor: MINIBUS_STATUS_STYLE.approved.bar }]} /><Text style={styles.legendText}>Validée</Text></View>
        <View style={styles.legendItem}><View style={[styles.legendDot, { backgroundColor: MINIBUS_STATUS_STYLE.pending.bar }]} /><Text style={styles.legendText}>En attente de validation</Text></View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, padding: 10 },
  header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 4, marginBottom: 8 },
  arrow: { padding: 6, borderRadius: 8 },
  monthTitle: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  weekdays: { flexDirection: 'row', marginBottom: 2 },
  weekday: { flex: 1, alignItems: 'center', paddingVertical: 3 },
  weekdayLabel: { fontSize: 11, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase' },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: `${100 / 7}%`, height: 58, padding: 1.5 },
  cellInner: { flex: 1, borderRadius: 6, paddingVertical: 3, paddingHorizontal: 2, overflow: 'hidden' },
  today: { backgroundColor: COLORS.secondarySoft },
  selected: { backgroundColor: COLORS.secondary },
  dayNumber: { fontSize: 12, fontWeight: '600', color: COLORS.text, textAlign: 'center', marginBottom: 2 },
  outside: { color: '#bbb' },
  todayText: { color: COLORS.secondaryDark, fontWeight: '700' },
  selectedText: { color: '#fff', fontWeight: '700' },
  chip: { borderRadius: 3, paddingHorizontal: 3, paddingVertical: 1, marginBottom: 1 },
  chipText: { color: '#fff', fontSize: 9, fontWeight: '700' },
  overflow: { fontSize: 9, color: COLORS.textMuted, textAlign: 'center' },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: 14, marginTop: 8, paddingHorizontal: 4 },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendDot: { width: 10, height: 10, borderRadius: 3 },
  legendText: { fontSize: 12, color: COLORS.textMuted },
});
