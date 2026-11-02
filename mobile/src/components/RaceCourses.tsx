import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal } from '@/api/types';
import { DateField, formatIsoDate } from '@/components/DateField';
import { COLORS, RADIUS, SPACING } from '@/config';

function notify(message: string): void {
  if (Platform.OS === 'web') window.alert(message);
  else Alert.alert('Courses', message);
}

/**
 * Courses (épreuves) d'une compétition proposée : liste par date, ajout par n'importe quel adhérent (nom, jour dans
 * les dates de la compétition, précisions), suppression par celui qui l'a ajoutée ou l'auteur de la compétition.
 * `onChange` reçoit la compétition à jour renvoyée par l'API.
 */
export function RaceCourses({ race, onChange }: { race: RaceProposal; onChange: (race: RaceProposal) => void }) {
  const courses = race.courses ?? [];
  const multiDay = !!race.raceEndDate && race.raceEndDate !== race.raceDate;
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [date, setDate] = useState<string | null>(null);
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState(false);
  const [removingId, setRemovingId] = useState<number | null>(null);

  async function add() {
    if (name.trim() === '' || busy) return;
    setBusy(true);
    try {
      onChange(await racesApi.addCourse(race.id, { name: name.trim(), date: multiDay ? (date ?? '') : '', details: details.trim() }));
      setName('');
      setDetails('');
      setDate(null);
      setOpen(false);
    } catch (e) {
      notify(e instanceof ApiError ? e.message : 'Ajout impossible. Réessayez.');
    } finally {
      setBusy(false);
    }
  }

  async function remove(courseId: number) {
    setRemovingId(courseId);
    try {
      onChange(await racesApi.removeCourse(race.id, courseId));
    } catch (e) {
      notify(e instanceof ApiError ? e.message : 'Suppression impossible.');
    } finally {
      setRemovingId(null);
    }
  }

  return (
    <View>
      <Text style={styles.sectionTitle}>🏁 Courses{courses.length > 0 ? ` (${courses.length})` : ''}</Text>

      {courses.length === 0 && !open && (
        <Text style={styles.muted}>Aucune course indiquée pour le moment : ajoutez celles proposées par la compétition.</Text>
      )}

      {courses.map((c) => (
        <View key={c.id} style={styles.course}>
          <View style={{ flex: 1, gap: 2 }}>
            <Text style={styles.courseName}>{c.name}</Text>
            {(c.date && multiDay) || c.details ? (
              <Text style={styles.courseMeta}>
                {[c.date && multiDay ? formatIsoDate(c.date) : null, c.details].filter(Boolean).join(' · ')}
              </Text>
            ) : null}
            {c.authorFirstName ? <Text style={styles.courseAuthor}>ajoutée par {c.authorFirstName}</Text> : null}
          </View>
          {c.canDelete && (
            <Pressable
              onPress={() => void remove(c.id)}
              disabled={removingId === c.id}
              hitSlop={10}
              accessibilityRole="button"
              accessibilityLabel={`Retirer la course ${c.name}`}
            >
              {removingId === c.id
                ? <ActivityIndicator size="small" color={COLORS.textMuted} />
                : <Ionicons name="close-circle" size={22} color={COLORS.textSubtle} />}
            </Pressable>
          )}
        </View>
      ))}

      {open ? (
        <View style={styles.form}>
          <Text style={styles.label}>Nom de la course</Text>
          <TextInput
            value={name}
            onChangeText={setName}
            placeholder="Ex : Triathlon M, Trail 25 km, Relais…"
            placeholderTextColor={COLORS.textSubtle}
            maxLength={120}
            editable={!busy}
            style={styles.input}
          />
          {multiDay && (
            <>
              <Text style={styles.label}>Jour (facultatif)</Text>
              <DateField onChange={setDate} disabled={busy} minIso={race.raceDate} />
            </>
          )}
          <Text style={styles.label}>Précisions (facultatives)</Text>
          <TextInput
            value={details}
            onChangeText={setDetails}
            placeholder="Distances, heure de départ, format…"
            placeholderTextColor={COLORS.textSubtle}
            maxLength={500}
            multiline
            editable={!busy}
            style={[styles.input, { minHeight: 70, textAlignVertical: 'top' }]}
          />
          <View style={styles.formActions}>
            <Pressable onPress={() => setOpen(false)} disabled={busy} style={styles.cancelBtn}>
              <Text style={styles.cancelLabel}>Annuler</Text>
            </Pressable>
            <Pressable
              onPress={() => void add()}
              disabled={busy || name.trim() === ''}
              style={[styles.addBtn, (busy || name.trim() === '') && { opacity: 0.5 }]}
            >
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.addLabel}>Ajouter</Text>}
            </Pressable>
          </View>
        </View>
      ) : (
        <Pressable onPress={() => setOpen(true)} style={({ pressed }) => [styles.openBtn, pressed && { opacity: 0.7 }]}>
          <Ionicons name="add-circle-outline" size={18} color={COLORS.primary} />
          <Text style={styles.openLabel}>Ajouter une course</Text>
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  sectionTitle: {
    fontSize: 12, fontWeight: '700', color: COLORS.textMuted,
    textTransform: 'uppercase', letterSpacing: 0.5,
    marginTop: SPACING.lg, marginBottom: SPACING.sm,
  },
  muted: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic', marginBottom: SPACING.sm },
  course: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md, marginBottom: 6,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  courseName: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  courseMeta: { fontSize: 13, color: COLORS.text },
  courseAuthor: { fontSize: 11, color: COLORS.textMuted },
  form: {
    padding: SPACING.md, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.primary,
    backgroundColor: COLORS.surface,
  },
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.sm },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  formActions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10, marginTop: SPACING.md },
  cancelBtn: { paddingHorizontal: 14, paddingVertical: 11 },
  cancelLabel: { fontSize: 14, fontWeight: '600', color: COLORS.textMuted },
  addBtn: { paddingHorizontal: 18, paddingVertical: 11, borderRadius: RADIUS.md, backgroundColor: COLORS.primary, minWidth: 100, alignItems: 'center' },
  addLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
  openBtn: {
    flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start',
    paddingHorizontal: 12, paddingVertical: 8, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.primary, backgroundColor: COLORS.surface,
  },
  openLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
});
