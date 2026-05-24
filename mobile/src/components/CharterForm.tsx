import { useMemo, useState } from 'react';
import { Platform, Pressable, StyleSheet, Switch, Text, TextInput, View } from 'react-native';

import type { CharterAnswers, CharterField } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';

type Props = {
  fields: CharterField[];
  value: CharterAnswers;
  onChange: (next: CharterAnswers) => void;
  /** local validation errors keyed by field id (empty if none). */
  errors?: Record<string, string>;
};

/**
 * Rendu d'un formulaire défini par schéma JSON côté admin.
 * Validation finale faite côté serveur ; on aide juste à saisir.
 */
export function CharterForm({ fields, value, onChange, errors = {} }: Props) {
  return (
    <View style={styles.root}>
      {fields.map((field) => (
        <FieldRow
          key={field.id}
          field={field}
          value={value[field.id]}
          error={errors[field.id]}
          onChange={(v) => onChange({ ...value, [field.id]: v })}
        />
      ))}
    </View>
  );
}

function FieldRow({
  field,
  value,
  error,
  onChange,
}: {
  field: CharterField;
  value: CharterAnswers[string] | undefined;
  error?: string;
  onChange: (v: CharterAnswers[string]) => void;
}) {
  const label = (
    <Text style={styles.label}>
      {field.label}
      {field.required ? <Text style={styles.required}> *</Text> : null}
    </Text>
  );

  return (
    <View style={styles.row}>
      {label}
      {field.help ? <Text style={styles.help}>{field.help}</Text> : null}
      <FieldInput field={field} value={value} onChange={onChange} />
      {error ? <Text style={styles.errorText}>{error}</Text> : null}
    </View>
  );
}

function FieldInput({
  field,
  value,
  onChange,
}: {
  field: CharterField;
  value: CharterAnswers[string] | undefined;
  onChange: (v: CharterAnswers[string]) => void;
}) {
  switch (field.type) {
    case 'textarea':
      return (
        <TextInput
          style={[styles.input, styles.textarea]}
          value={value == null ? '' : String(value)}
          onChangeText={onChange}
          multiline
          numberOfLines={4}
          textAlignVertical="top"
        />
      );
    case 'number':
      return (
        <TextInput
          style={styles.input}
          value={value == null ? '' : String(value)}
          onChangeText={(t) => onChange(t === '' ? null : t)}
          keyboardType="numeric"
          inputMode="numeric"
        />
      );
    case 'date':
      return <DateInput value={value == null ? '' : String(value)} onChange={onChange} />;
    case 'checkbox':
      return (
        <View style={styles.checkboxRow}>
          <Switch
            value={value === true || value === 'true' || value === 1 || value === '1'}
            onValueChange={(b) => onChange(b)}
            trackColor={{ false: '#d1d5db', true: COLORS.secondarySoft }}
            thumbColor={value === true ? COLORS.secondary : '#f9fafb'}
          />
          <Text style={styles.checkboxLabel}>{value ? 'Oui' : 'Non'}</Text>
        </View>
      );
    case 'select':
    case 'radio':
      return <OptionList options={field.options ?? []} value={value} onChange={onChange} />;
    case 'text':
    default:
      return (
        <TextInput
          style={styles.input}
          value={value == null ? '' : String(value)}
          onChangeText={onChange}
        />
      );
  }
}

function DateInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  // Sur le web, un <input type="date"> natif fait largement l'affaire.
  if (Platform.OS === 'web') {
    return (
      <input
        type="date"
        value={value}
        onChange={(e: { target: { value: string } }) => onChange(e.target.value)}
        style={{
          fontFamily: 'inherit',
          fontSize: 15,
          padding: 10,
          borderRadius: RADIUS.sm,
          border: `1px solid ${COLORS.border}`,
          backgroundColor: '#fff',
          color: COLORS.text,
        }}
      />
    );
  }
  // Sur natif, on accepte la saisie manuelle YYYY-MM-DD (suffisant pour le V1).
  return (
    <TextInput
      style={styles.input}
      value={value}
      onChangeText={onChange}
      placeholder="AAAA-MM-JJ"
      autoCapitalize="none"
      autoCorrect={false}
      keyboardType="numbers-and-punctuation"
    />
  );
}

function OptionList({
  options,
  value,
  onChange,
}: {
  options: string[];
  value: CharterAnswers[string] | undefined;
  onChange: (v: string) => void;
}) {
  const safeOptions = useMemo(() => options.filter((o) => typeof o === 'string'), [options]);
  return (
    <View style={styles.optionList}>
      {safeOptions.map((opt) => {
        const active = value === opt;
        return (
          <Pressable
            key={opt}
            onPress={() => onChange(opt)}
            style={[styles.optionChip, active && styles.optionChipActive]}
          >
            <Text style={[styles.optionLabel, active && styles.optionLabelActive]}>{opt}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/**
 * Validation côté client (miroir light du FormSchemaValidator backend).
 * Permet de bloquer le bouton Submit avant l'aller-retour réseau.
 */
export function validateCharterAnswers(
  fields: CharterField[],
  answers: CharterAnswers,
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const f of fields) {
    const v = answers[f.id];
    const isEmpty = v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
    if (f.required && isEmpty) {
      errors[f.id] = `« ${f.label} » est obligatoire.`;
      continue;
    }
    if (isEmpty) continue;
    if (f.type === 'number' && Number.isNaN(Number(v))) {
      errors[f.id] = `« ${f.label} » doit être un nombre.`;
    }
    if (f.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(String(v))) {
      errors[f.id] = `« ${f.label} » doit être au format AAAA-MM-JJ.`;
    }
    if ((f.type === 'select' || f.type === 'radio') && f.options && !f.options.includes(String(v))) {
      errors[f.id] = `« ${f.label} » : choix invalide.`;
    }
  }
  return errors;
}

/** Hook utilitaire : gère state + validation pour CharterForm. */
export function useCharterForm(fields: CharterField[]) {
  const [answers, setAnswers] = useState<CharterAnswers>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  return {
    answers,
    setAnswers,
    errors,
    setErrors,
    validate: () => {
      const e = validateCharterAnswers(fields, answers);
      setErrors(e);
      return Object.keys(e).length === 0;
    },
  };
}

const styles = StyleSheet.create({
  root: { gap: SPACING.lg },
  row: { gap: 6 },
  label: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  required: { color: COLORS.error },
  help: { fontSize: 12, color: COLORS.textMuted },
  input: {
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.sm,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
    color: COLORS.text,
    backgroundColor: '#fff',
  },
  textarea: { minHeight: 96 },
  checkboxRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  checkboxLabel: { fontSize: 14, color: COLORS.text },
  optionList: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  optionChip: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: '#fff',
  },
  optionChipActive: {
    backgroundColor: COLORS.secondarySoft,
    borderColor: COLORS.secondary,
  },
  optionLabel: { fontSize: 14, color: COLORS.text },
  optionLabelActive: { color: COLORS.secondaryDark, fontWeight: '600' },
  errorText: { fontSize: 12, color: COLORS.error, marginTop: 2 },
});
