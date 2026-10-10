import type { AuthenticatedUser, UserAccountType, UserProfile, UserSubType } from '@/api/client';

/**
 * Libellé d'affichage d'un profil (utilisé pour les badges).
 */
const PROFILE_LABELS: Record<UserProfile, string> = {
  jeune: 'Jeune',
  senior: 'Sénior',
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
 * premier (Jeune/Senior), puis fonctions (Parent, Entraîneur, Encadrant).
 */
const PROFILE_ORDER: UserProfile[] = ['jeune', 'senior', 'parent', 'entraineur', 'encadrant'];

export function sortProfiles(profiles: UserProfile[]): UserProfile[] {
  return [...profiles].sort((a, b) => PROFILE_ORDER.indexOf(a) - PROFILE_ORDER.indexOf(b));
}

const SUBTYPE_LABELS: Record<UserSubType, string> = {
  club: 'Licencié au club',
  autre_club: 'Licencié autre club',
  parent: 'Parent d\'adhérent',
  ami: 'Ami du club',
};

export function subTypeLabel(s: UserSubType): string {
  return SUBTYPE_LABELS[s] ?? s;
}

/**
 * Un utilisateur a-t-il accès à l'onglet « Entraînement » dans la nav ?
 *
 * Règles (alignées avec la spec Phase D) :
 *  - Sans numéro de licence (parent externe, ami du club) → non.
 *  - Dirigeant (typeLicence='Dirigeant') → non (rôle administratif sans entraînement).
 *  - Sinon → oui.
 *
 * Sont en revanche TOUJOURS autorisés les Encadrants / Entraîneurs : le
 * back-end leur expose toutes les séances (bypass d'audience Phase C),
 * ce qui leur permet de superviser n'importe quel créneau.
 */
export function canSeeTraining(user: AuthenticatedUser | null | undefined): boolean {
  if (!user) return false;
  if (!user.numLicence) return false;
  if (user.isDirigeant) return false;
  return true;
}

/**
 * Plans d'entraînement hebdomadaires : pas pour les comptes Jeune (sauf
 * s'ils sont aussi Entraîneur / Encadrant). Le backend applique la même
 * règle (User::canSeeTrainingPlans) — ceci ne fait que masquer la section.
 */
export function canSeeTrainingPlans(user: AuthenticatedUser | null | undefined): boolean {
  if (!user) return false;
  if (!user.profiles.includes('jeune')) return true;
  return user.profiles.includes('entraineur') || user.profiles.includes('encadrant');
}

/**
 * Le compte donne-t-il droit au QR code piscines (badge d'accès club) ?
 * Réservé aux adhérents licenciés. Un dirigeant garde l'accès (il est licencié).
 */
export function canSeePoolBadge(user: AuthenticatedUser | null | undefined): boolean {
  if (!user) return false;
  return !!user.numLicence;
}

/**
 * Le compte peut-il accéder au planning goûter du mercredi ?
 * Réservé aux profils Parent et Jeune (ceux qui apportent le goûter).
 */
export function canSeeGouter(user: AuthenticatedUser | null | undefined): boolean {
  if (!user) return false;
  return user.profiles.includes('parent') || user.profiles.includes('jeune');
}

/**
 * L'onglet Entraînements doit-il apparaître dans la nav ?
 *
 * Élargi par rapport à `canSeeTraining` pour inclure les parents non
 * licenciés : la page héberge désormais la section « Goûter du mercredi »
 * (parents/jeunes) — un parent externe qui n'a pas de créneaux à voir
 * a quand même besoin d'ouvrir la page pour se positionner sur un goûter.
 */
export function canSeeTrainingTab(user: AuthenticatedUser | null | undefined): boolean {
  return canSeeTraining(user) || canSeeGouter(user);
}

/**
 * Staff sportif : profil Entraîneur ou Encadrant. Donne accès aux onglets
 * « Présences » et « Adhérents » de l'espace « Staff » (annuaire, présences
 * aux entraînements). Le back-end revérifie (StaffMembersController) — ceci
 * ne fait que masquer l'interface.
 */
export function isStaffMember(user: AuthenticatedUser | null | undefined): boolean {
  if (!user) return false;
  return user.profiles.includes('entraineur') || user.profiles.includes('encadrant');
}

/** Profil Entraîneur (les encadrants n'en font pas partie). */
export function isEntraineur(user: AuthenticatedUser | null | undefined): boolean {
  return !!user && user.profiles.includes('entraineur');
}

/** Membre du CoDir (poste au bureau). */
export function isBoardMember(user: AuthenticatedUser | null | undefined): boolean {
  return !!user && user.isBoardMember === true;
}

/** Administrateur (niveau d'accès du compte, pas un profil). */
export function isAdminUser(user: AuthenticatedUser | null | undefined): boolean {
  return !!user && user.role === 'admin';
}

/**
 * Émargement de la présence aux événements : entraîneurs, encadrants, membres
 * du CoDir et administrateurs (StaffCheckInController).
 */
export function canCheckIn(user: AuthenticatedUser | null | undefined): boolean {
  return isStaffMember(user) || isBoardMember(user) || isAdminUser(user);
}

/**
 * Remise des bonnets et saisie des temps des tests chronométrés : entraîneurs
 * et administrateurs (StaffCapController, StaffPerfTestController).
 */
export function canManageCapsAndTimes(user: AuthenticatedUser | null | undefined): boolean {
  return isEntraineur(user) || isAdminUser(user);
}

/**
 * Le bouton « Staff » de l'en-tête est-il proposé ? Staff sportif (entraîneur,
 * encadrant), membre du CoDir ou administrateur ; chaque onglet reste filtré
 * selon le profil.
 */
export function canUseStaffSpace(user: AuthenticatedUser | null | undefined): boolean {
  return isStaffMember(user) || isBoardMember(user) || isAdminUser(user);
}
