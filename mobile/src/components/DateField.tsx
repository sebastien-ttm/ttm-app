import { useState } from 'react';
import { Platform, StyleSheet, TextInput } from 'react-native';

import { COLORS, RADIUS } from '@/config';

/** AAAA-MM-JJ → JJ/MM/AAAA (saisie native). */
function isoToFr(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
}

/** JJ/MM/AAAA → AAAA-MM-JJ, ou null si incomplet/invalide. */
function frToIso(fr: string): string | null {
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(fr.trim());
  if (!m) return null;
  const [, d, mo, y] = m;
  const date = new Date(Number(y), Number(mo) - 1, Number(d));
  if (date.getFullYear() !== Number(y) || date.getMonth() !== Number(mo) - 1 || date.getDate() !== Number(d)) {
    return null;
  }
  return `${y}-${mo}-${d}`;
}

export function todayIso(): string {
  const d = new Date();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/** « sam. 14 juin 2026 » à partir d'une date AAAA-MM-JJ (sans décalage de fuseau). */
export function formatIsoDate(iso: string, withWeekday = true): string {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString('fr-FR', {
    ...(withWeekday ? { weekday: 'short' } : {}),
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

/**
 * Saisie d'une date (jour seul). Web : <input type="date"> natif du
 * navigateur (calendrier). Natif : saisie JJ/MM/AAAA avec insertion
 * automatique des « / ». Remonte la date en AAAA-MM-JJ, ou null tant
 * qu'elle est incomplète/invalide.
 */
export function DateField({ initialIso, onChange, disabled, minIso }: {
  initialIso?: string;
  onChange: (iso: string | null) => void;
  disabled?: boolean;
  minIso?: string;
}) {
  const [text, setText] = useState(
    initialIso ? (Platform.OS === 'web' ? initialIso : isoToFr(initialIso)) : '',
  );

  if (Platform.OS === 'web') {
    return (
      <input
        type="date"
        value={text}
        min={minIso}
        disabled={disabled}
        onChange={(e: { target: { value: string } }) => {
          setText(e.target.value);
          onChange(/^\d{4}-\d{2}-\d{2}$/.test(e.target.value) ? e.target.value : null);
        }}
        style={{
          fontFamily: 'inherit',
          fontSize: 15,
          padding: 12,
          borderRadius: RADIUS.md,
          border: `1px solid ${COLORS.border}`,
          backgroundColor: COLORS.surface,
          color: COLORS.text,
        }}
      />
    );
  }

  // 14062026 → 14/06/2026
  function onNativeChange(v: string) {
    const digits = v.replace(/\D/g, '').slice(0, 8);
    let out = digits;
    if (digits.length > 4) out = `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
    else if (digits.length > 2) out = `${digits.slice(0, 2)}/${digits.slice(2)}`;
    setText(out);
    onChange(frToIso(out));
  }

  return (
    <TextInput
      value={text}
      onChangeText={onNativeChange}
      placeholder="JJ/MM/AAAA"
      placeholderTextColor={COLORS.textSubtle}
      keyboardType="number-pad"
      maxLength={10}
      style={styles.input}
      editable={!disabled}
    />
  );
}

const styles = StyleSheet.create({
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
});
