import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import type { RaceCourseInput, RaceProposalInput, RaceType } from '@/api/types';
import { DateField, formatIsoDate, todayIso } from '@/components/DateField';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Types de course : libellé, emoji et couleur (liste, détail, formulaire). */
export const RACE_TYPES: { value: RaceType; label: string; emoji: string; color: string }[] = [
  { value: 'triathlon', label: 'Triathlon', emoji: '🏊', color: '#1d4ed8' },
  { value: 'trail', label: 'Trail', emoji: '⛰️', color: '#15803d' },
  { value: 'route', label: 'Course à pied sur route', emoji: '🏃', color: '#c2410c' },
  { value: 'cyclosportive', label: 'Cyclosportive', emoji: '🚴', color: '#b91c1c' },
  { value: 'eau_libre', label: 'Eau libre', emoji: '🌊', color: '#0e7490' },
  { value: 'autre', label: 'Autre', emoji: '🏅', color: '#6b21a8' },
];

export function raceTypeMeta(type: RaceType) {
  return RACE_TYPES.find((t) => t.value === type) ?? RACE_TYPES[RACE_TYPES.length - 1];
}

export const formatRaceDate = formatIsoDate;

/**
 * Dates d'une compétition : « sam. 14 juin 2026 » sur une journée, « du 13 au 15 juin 2026 », « du 30 mai au
 * 1 juin 2026 » ou « du 31 déc. 2026 au 2 janv. 2027 » sur plusieurs jours.
 */
export function formatRaceDates(start: string, end?: string | null): string {
  if (!end || end === start) return formatIsoDate(start);
  const [sy, sm, sd] = start.split('-').map(Number);
  const [ey, em, ed] = end.split('-').map(Number);
  const s = new Date(sy, sm - 1, sd);
  const e = new Date(ey, em - 1, ed);
  const endLabel = e.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
  if (sy !== ey) return `du ${s.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })} au ${endLabel}`;
  if (sm !== em) return `du ${s.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} au ${endLabel}`;
  return `du ${sd} au ${endLabel}`;
}

/**
 * Formulaire commun création / édition d'une compétition proposée : nom, dates de début et de fin (une compétition
 * peut durer plusieurs jours et comporter plusieurs épreuves), site Internet, description libre, type, case « Je me
 * propose d'être capitaine » (avec, dans ce cas, la date limite de réponse avant l'inscription groupée) et covoiturage.
 */
export function RaceForm({ initial, submitLabel, onSubmit, onCancel, withCourses = false }: {
  initial?: RaceProposalInput;
  submitLabel: string;
  onSubmit: (input: RaceProposalInput) => Promise<void>;
  onCancel: () => void;
  /** Création : saisie des courses de la compétition (ensuite, elles se gèrent depuis le détail). */
  withCourses?: boolean;
}) {
  const [courses, setCourses] = useState<RaceCourseInput[]>([]);
  const [courseName, setCourseName] = useState('');
  const [courseDate, setCourseDate] = useState<string | null>(null);
  const [courseDetails, setCourseDetails] = useState('');
  // Remonté pour vider le champ date après chaque ajout (DateField garde sa saisie en interne).
  const [courseKey, setCourseKey] = useState(0);
  const [name, setName] = useState(initial?.name ?? '');
  const [isoDate, setIsoDate] = useState<string | null>(initial?.raceDate ?? null);
  // Dernier jour : null = une seule journée (ou saisie incomplète).
  const [endIso, setEndIso] = useState<string | null>(initial?.raceEndDate || null);
  const [url, setUrl] = useState(initial?.url ?? '');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [type, setType] = useState<RaceType | null>(initial?.type ?? null);
  const [captain, setCaptain] = useState(initial?.captain ?? false);
  // Date limite de réponse (capitaine) : null = aucune ou saisie incomplète.
  const [deadlineIso, setDeadlineIso] = useState<string | null>(initial?.registrationDeadline || null);
  const [carpoolingEnabled, setCarpoolingEnabled] = useState(initial?.carpoolingEnabled ?? false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSubmit = !busy && name.trim() !== '' && isoDate !== null && type !== null;
  const multiDay = isoDate !== null && endIso !== null && endIso !== isoDate;

  function addCourse() {
    const n = courseName.trim();
    if (n === '') return;
    setCourses((prev) => [...prev, { name: n, date: courseDate ?? '', details: courseDetails.trim() }]);
    setCourseName('');
    setCourseDetails('');
    setCourseDate(null);
    setCourseKey((k) => k + 1);
  }

  async function submit() {
    setError(null);
    if (name.trim() === '') { setError('Le nom de la compétition ne peut pas être vide.'); return; }
    if (isoDate === null) { setError('Date de début invalide (JJ/MM/AAAA).'); return; }
    if (endIso !== null && endIso < isoDate) { setError('La date de fin doit être le jour du début ou après.'); return; }
    if (type === null) { setError('Choisissez le type de compétition.'); return; }
    // La date limite n'a de sens que pour un capitaine ; elle ne peut pas dépasser le premier jour.
    const deadline = captain ? deadlineIso : null;
    if (deadline !== null && deadline > isoDate) {
      setError('La date limite de réponse doit être au plus tard le premier jour de la compétition.');
      return;
    }
    setBusy(true);
    try {
      await onSubmit({
        name: name.trim(),
        raceDate: isoDate,
        raceEndDate: endIso ?? '',
        url: url.trim(),
        description: description.trim(),
        captain,
        registrationDeadline: deadline ?? '',
        carpoolingEnabled,
        type,
        // Une course saisie mais pas encore ajoutée à la liste est prise aussi. Jour seulement sur plusieurs jours.
        ...(withCourses ? {
          courses: [
            ...courses,
            ...(courseName.trim() !== '' ? [{ name: courseName.trim(), date: courseDate ?? '', details: courseDetails.trim() }] : []),
          ].map((c) => ({ ...c, date: multiDay ? c.date : '' })),
        } : {}),
      });
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur inattendue.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View>
      <Text style={styles.label}>Nom de la compétition</Text>
      <TextInput
        value={name}
        onChangeText={setName}
        placeholder="Ex : Triathlon de Carcassonne"
        placeholderTextColor={COLORS.textSubtle}
        maxLength={150}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Date de début</Text>
      <DateField initialIso={initial?.raceDate} onChange={setIsoDate} disabled={busy} minIso={todayIso()} />

      <Text style={styles.label}>Date de fin (facultative)</Text>
      <DateField initialIso={initial?.raceEndDate || undefined} onChange={setEndIso} disabled={busy} minIso={isoDate ?? todayIso()} />
      <Text style={styles.counter}>Laissez vide pour une compétition sur une seule journée.</Text>

      <Text style={styles.label}>Site Internet (facultatif)</Text>
      <TextInput
        value={url}
        onChangeText={setUrl}
        placeholder="https://www.lacourse.fr"
        placeholderTextColor={COLORS.textSubtle}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        maxLength={500}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Description (facultative)</Text>
      <TextInput
        value={description}
        onChangeText={setDescription}
        placeholder="Épreuves proposées, distances, parcours, niveau, hébergement…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={3000}
        style={[styles.input, styles.textarea]}
        editable={!busy}
      />
      <Text style={styles.counter}>{description.length} / 3000</Text>

      {withCourses && (
        <>
          <Text style={styles.label}>Courses (facultatif)</Text>
          <Text style={styles.captainHint}>
            Les courses ou épreuves de la compétition : chacun pourra indiquer celles qu'il fait. Vous pourrez en
            ajouter d'autres ensuite.
          </Text>
          {courses.map((c, i) => (
            <View key={i} style={styles.courseRow}>
              <View style={{ flex: 1 }}>
                <Text style={styles.courseName}>{c.name}</Text>
                {(multiDay && c.date) || c.details ? (
                  <Text style={styles.captainHint}>
                    {[multiDay && c.date ? formatIsoDate(c.date) : null, c.details].filter(Boolean).join(' · ')}
                  </Text>
                ) : null}
              </View>
              <Pressable
                onPress={() => setCourses((prev) => prev.filter((_, j) => j !== i))}
                disabled={busy}
                hitSlop={10}
                accessibilityRole="button"
                accessibilityLabel={`Retirer la course ${c.name}`}
              >
                <Ionicons name="close-circle" size={22} color={COLORS.textSubtle} />
              </Pressable>
            </View>
          ))}
          <View style={styles.deadlineBox}>
            <TextInput
              value={courseName}
              onChangeText={setCourseName}
              placeholder="Nom : Triathlon M, Trail 25 km, Relais…"
              placeholderTextColor={COLORS.textSubtle}
              maxLength={120}
              style={styles.input}
              editable={!busy}
            />
            {multiDay && (
              <>
                <Text style={[styles.deadlineLabel, { marginTop: SPACING.sm }]}>Jour (facultatif)</Text>
                <DateField key={courseKey} onChange={setCourseDate} disabled={busy} minIso={isoDate ?? undefined} />
              </>
            )}
            <TextInput
              value={courseDetails}
              onChangeText={setCourseDetails}
              placeholder="Précisions : distances, heure de départ…"
              placeholderTextColor={COLORS.textSubtle}
              maxLength={500}
              style={[styles.input, { marginTop: SPACING.sm }]}
              editable={!busy}
            />
            <Pressable
              onPress={addCourse}
              disabled={busy || courseName.trim() === ''}
              style={({ pressed }) => [styles.addCourseBtn, (busy || courseName.trim() === '' || pressed) && { opacity: 0.5 }]}
            >
              <Ionicons name="add-circle-outline" size={18} color={COLORS.primary} />
              <Text style={styles.addCourseLabel}>Ajouter cette course</Text>
            </Pressable>
          </View>
        </>
      )}

      <Text style={styles.label}>Type de compétition</Text>
      <View style={styles.typeGrid}>
        {RACE_TYPES.map((t) => {
          const active = type === t.value;
          return (
            <Pressable
              key={t.value}
              onPress={() => setType(t.value)}
              disabled={busy}
              style={({ pressed }) => [
                styles.typeChip,
                active && { backgroundColor: t.color, borderColor: t.color },
                pressed && { opacity: 0.7 },
              ]}
            >
              <Text style={styles.typeEmoji}>{t.emoji}</Text>
              <Text style={[styles.typeLabel, active && { color: '#fff' }]}>{t.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <Pressable
        onPress={() => setCaptain((c) => !c)}
        disabled={busy}
        style={({ pressed }) => [styles.captainBox, captain && styles.captainBoxActive, pressed && { opacity: 0.8 }]}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: captain }}
      >
        <Ionicons
          name={captain ? 'checkbox' : 'square-outline'}
          size={24}
          color={captain ? COLORS.primary : COLORS.textMuted}
        />
        <View style={{ flex: 1 }}>
          <Text style={styles.captainTitle}>Je me propose d'être capitaine</Text>
          <Text style={styles.captainHint}>
            Je prends contact avec l'organisateur pour pouvoir proposer une inscription groupée.
          </Text>
        </View>
      </Pressable>

      {captain && (
        <View style={styles.deadlineBox}>
          <Text style={styles.deadlineLabel}>Date limite de réponse (facultative)</Text>
          <DateField
            initialIso={initial?.registrationDeadline || undefined}
            onChange={setDeadlineIso}
            disabled={busy}
            minIso={todayIso()}
          />
          <Text style={styles.captainHint}>
            Les adhérents doivent répondre avant cette date (incluse) pour l'inscription groupée ; ensuite leurs
            réponses sont figées. Vous pourrez la repousser en modifiant la proposition.
          </Text>
        </View>
      )}

      <Pressable
        onPress={() => setCarpoolingEnabled((c) => !c)}
        disabled={busy}
        style={({ pressed }) => [styles.captainBox, carpoolingEnabled && styles.captainBoxActive, pressed && { opacity: 0.8 }]}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: carpoolingEnabled }}
      >
        <Ionicons
          name={carpoolingEnabled ? 'checkbox' : 'square-outline'}
          size={24}
          color={carpoolingEnabled ? COLORS.primary : COLORS.textMuted}
        />
        <View style={{ flex: 1 }}>
          <Text style={styles.captainTitle}>Activer le covoiturage</Text>
          <Text style={styles.captainHint}>
            Les adhérents intéressés pourront proposer des places (conducteur) ou en chercher une (passager).
          </Text>
        </View>
      </Pressable>

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable
        onPress={submit}
        disabled={!canSubmit}
        style={[styles.button, !canSubmit && styles.buttonDisabled]}
      >
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonLabel}>{submitLabel}</Text>}
      </Pressable>
      <Pressable onPress={onCancel} style={styles.backBtn} disabled={busy}>
        <Text style={styles.backBtnLabel}>Annuler</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  label: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6, marginTop: SPACING.md },
  input: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.text,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  textarea: { minHeight: 110, textAlignVertical: 'top' },
  counter: { textAlign: 'right', fontSize: 11, color: COLORS.textMuted, marginTop: 4 },
  deadlineBox: {
    marginTop: SPACING.sm, padding: SPACING.md,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.primary,
    backgroundColor: COLORS.surface,
  },
  deadlineLabel: { color: COLORS.text, fontWeight: '600', fontSize: 13, marginBottom: 6 },
  courseRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md, marginBottom: 6,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  courseName: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  addCourseBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', marginTop: SPACING.sm, paddingVertical: 6 },
  addCourseLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  typeGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  typeChip: {
    flexDirection: 'row', alignItems: 'center', gap: 6,
    paddingHorizontal: 12, paddingVertical: 8,
    borderRadius: 20, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  typeEmoji: { fontSize: 15 },
  typeLabel: { fontSize: 13, fontWeight: '600', color: COLORS.text },
  captainBox: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    marginTop: SPACING.lg, padding: SPACING.md,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  captainBoxActive: { borderColor: COLORS.primary, backgroundColor: COLORS.primarySoft },
  captainTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  captainHint: { fontSize: 12, color: COLORS.textMuted, marginTop: 2, lineHeight: 17 },
  error: {
    color: COLORS.error,
    backgroundColor: '#fee2e2',
    padding: 12,
    borderRadius: RADIUS.sm,
    marginTop: SPACING.md,
    fontSize: 13, fontWeight: '500',
  },
  button: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: SPACING.lg,
  },
  buttonDisabled: { opacity: 0.4 },
  buttonLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { alignItems: 'center', paddingVertical: 14 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
