import type { UserAccountType, UserProfile } from '@/api/client';

/**
 * Libellé d'affichage d'un profil (utilisé pour les badges).
 */
const PROFILE_LABELS: Record<UserProfile, string> = {
  jeune: 'Jeune',
  senior: 'Sénior',
  u25: 'U25',
  parent: 'Parent',
  entraineur: 'Entraîneur',
  encadrant: 'Encadrant',
};

/**
 * Couleurs des badges — alignées avec backend Profile::color().
 */
const PROFILE_COLORS: Record<UserProfile, string> = {
  jeune: '#16a34a',
  senior: '#1d4ed8',
  u25: '#7c3aed',
  parent: '#ea580c',
  entraineur: '#0d2148',
  encadrant: '#dc2626',
};

export function profileLabel(p: UserProfile): string {
  return PROFILE_LABELS[p] ?? p;
}

export function profileColor(p: UserProfile): string {
  return PROFILE_COLORS[p] ?? '#6b7280';
}

const TYPE_LABELS: Record<UserAccountType, string> = {
  adherent: 'Adhérent',
  externe: 'Externe',
};

const TYPE_COLORS: Record<UserAccountType, string> = {
  adherent: '#16a34a',
  externe: '#ea580c',
};

export function accountTypeLabel(t: UserAccountType): string {
  return TYPE_LABELS[t] ?? t;
}

export function accountTypeColor(t: UserAccountType): string {
  return TYPE_COLORS[t] ?? '#6b7280';
}

/**
 * Tri des profils pour un affichage cohérent : catégorie principale en
 * premier (Jeune/Senior), puis modifieurs (U25), puis fonctions (Parent,
 * Entraîneur, Encadrant).
 */
const PROFILE_ORDER: UserProfile[] = ['jeune', 'senior', 'u25', 'parent', 'entraineur', 'encadrant'];

export function sortProfiles(profiles: UserProfile[]): UserProfile[] {
  return [...profiles].sort((a, b) => PROFILE_ORDER.indexOf(a) - PROFILE_ORDER.indexOf(b));
}
