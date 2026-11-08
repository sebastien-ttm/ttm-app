import { Alert, Platform, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { VolunteerApplicationStatus, VolunteerPoleColor, VolunteerRoleSummary } from '@/api/types';

/** Charte de l'événement (Breathman) : utilisée uniquement dans les écrans de bénévolat. */
export const VOL = {
  cream: '#FFF8EC',
  navy: '#15304B',
  coral: '#EE7468',
  peach: '#F7C9A4',
  sun: '#FAD98C',
  teal: '#8FCFCB',
  orange: '#F28C3A',
  muted: '#5b6b7c',
  border: '#ecdcc4',
} as const;

export const POLE_COLORS: Record<VolunteerPoleColor, string> = {
  teal: VOL.teal,
  sun: VOL.sun,
  coral: VOL.coral,
  orange: VOL.orange,
  navy: VOL.navy,
};

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function parts(iso: string): { d: number; m: number; y: number } {
  const [y, m, d] = iso.split('-').map((x) => parseInt(x, 10));
  return { d, m: m - 1, y };
}

/** « 15 décembre 2026 ». */
export function formatDay(iso: string): string {
  const { d, m, y } = parts(iso);
  return `${d} ${MONTHS[m]} ${y}`;
}

/** « 19 & 20 juin 2027 » (jours consécutifs du même mois), « 19 juin 2027 » ou « 30 juin – 2 juillet 2027 ». */
export function formatDays(startsOn: string, endsOn: string | null): string {
  if (!endsOn || endsOn === startsOn) return formatDay(startsOn);
  const a = parts(startsOn);
  const b = parts(endsOn);
  if (a.y === b.y && a.m === b.m) {
    return b.d - a.d === 1 ? `${a.d} & ${b.d} ${MONTHS[a.m]} ${a.y}` : `${a.d} – ${b.d} ${MONTHS[a.m]} ${a.y}`;
  }
  return `${a.d} ${MONTHS[a.m]} – ${formatDay(endsOn)}`;
}

export const STATUS_META: Record<VolunteerApplicationStatus, { label: string; bg: string; fg: string }> = {
  pending: { label: 'En attente', bg: '#fef3c7', fg: '#92400e' },
  accepted: { label: 'Validée', bg: '#dcfce7', fg: '#166534' },
  declined: { label: 'Déclinée', bg: '#f3f4f6', fg: '#4b5563' },
};

export function StatusChip({ status, prefix }: { status: VolunteerApplicationStatus; prefix?: string }) {
  const meta = STATUS_META[status];
  return (
    <View style={[styles.chip, { backgroundColor: meta.bg }]}>
      <Text style={[styles.chipLabel, { color: meta.fg }]}>{(prefix ?? '') + meta.label}</Text>
    </View>
  );
}

/**
 * Badge des personnes en place sur une fiche : turquoise si complet, jaune s'il manque quelqu'un (binôme
 * recherché ou postes encore à pourvoir), corail si personne n'est en place.
 */
export function HoldersBadge({ role }: { role: VolunteerRoleSummary }) {
  if (role.holders.length === 0) {
    return (
      <View style={[styles.badge, { backgroundColor: VOL.coral }]}>
        <Text style={[styles.badgeLabel, { color: '#fff' }]}>
          {role.kind === 'field' && role.requiredCount ? `À pourvoir · ${role.requiredCount} poste${role.requiredCount > 1 ? 's' : ''}` : 'À pourvoir'}
        </Text>
      </View>
    );
  }
  const names = role.holders.join(', ');
  let extra = '';
  if (role.kind === 'activity' && role.needsPartner) extra = ' · binôme recherché';
  if (role.kind === 'field' && role.missing) extra = ` · encore ${role.missing} à pourvoir`;
  const label = role.kind === 'field' ? `En place : ${names}` : `${role.holderLabel} : ${names}`;
  return (
    <View style={[styles.badge, { backgroundColor: extra ? VOL.sun : VOL.teal }]}>
      <Text style={[styles.badgeLabel, { color: VOL.navy }]}>{label + extra}</Text>
    </View>
  );
}

export function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
}

export function confirmAsync(title: string, message: string, confirmLabel: string): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(typeof window !== 'undefined' ? window.confirm(title + '\n' + message) : false);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
      { text: confirmLabel, style: 'destructive', onPress: () => resolve(true) },
    ]);
  });
}

const styles = StyleSheet.create({
  chip: { alignSelf: 'flex-start', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  chipLabel: { fontSize: 11, fontWeight: '700' },
  badge: { alignSelf: 'flex-start', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, marginTop: 6, maxWidth: '100%' },
  badgeLabel: { fontSize: 11.5, fontWeight: '600' },
});
