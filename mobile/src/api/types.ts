export type UserSummary = {
  id: number;
  fullName: string;
  prenom: string;
  nom: string;
};

export type Photo = {
  id: number;
  url: string | null;
  alt: string | null;
  position: number;
};

export type ArticleAttachment = {
  id: number;
  name: string;
  size: number;
  humanSize: string;
  mimeType: string;
  url: string;
};

export type Article = {
  id: number;
  title: string;
  content: string;
  publishedAt: string | null;
  author: UserSummary;
  photos: Photo[];
  attachments: ArticleAttachment[];
  reactionCounts: Record<string, number>;
  myReactions: string[];
  commentCount: number;
};

export type Paginated<T> = {
  data: T[];
  page: number;
  limit: number;
  total: number;
  totalPages?: number;
};

export type Comment = {
  id: number;
  content: string;
  createdAt: string;
  user: UserSummary;
};

export type TrainingPlan = {
  id: number;
  title: string;
  displayTitle: string;
  category: 'general' | 'longue_distance';
  categoryLabel: string;
  description: string | null;
  fileUrl: string;
  postedBy: UserSummary;
  weekStartsAt: string | null;
  weekRangeLabel: string | null;
  postedAt: string;
  publishedAt: string | null;
};

export type StaticPageSummary = { slug: string; title: string };

export type GouterSignupSummary = {
  id: number;
  userId: number;
  fullName: string;
  isMine: boolean;
  notes: string | null;
  createdAt: string;
  byAdmin: boolean;
};

export type GouterSlot = {
  date: string; // YYYY-MM-DD (Wednesday)
  capacity: number;
  isCancelled: boolean;
  cancellationReason: string | null;
  signups: GouterSignupSummary[];
};

export type GouterPlanning = {
  slots: GouterSlot[];
};

export type StaticPageNode = {
  slug: string;
  title: string;
  hasChildren: boolean;
  children: StaticPageNode[];
};

export type StaticPage = StaticPageSummary & {
  content: string;
  updatedAt: string;
  parentSlug: string | null;
  children: StaticPageNode[];
};

export type MenuItem = {
  id: number;
  label: string;
  type: 'feed' | 'training' | 'calendar' | 'page' | 'external';
  target: string | null;
  icon: string | null;
  position: number;
};

export type EventItem = {
  id: number;
  title: string;
  description: string | null;
  location: string | null;
  startsAt: string;
  endsAt: string | null;
  type: 'course' | 'stage' | 'entrainement' | 'social' | 'organisation';
  color: string;
  /** True = événement « toute la journée » : ne pas afficher l'heure. */
  isAllDay: boolean;
};

export type Banner = {
  id: number;
  imageUrl: string | null;
  title: string | null;
  linkUrl: string | null;
};

export type SportKey = 'natation' | 'velo' | 'course' | 'multi' | 'renfo' | 'autre';

export type TrainingSlotAttachment = {
  id: number;
  name: string;
  size: number;
  humanSize: string;
  mimeType: string;
};

export type TrainingSlot = {
  /** null si créneau virtuel (semaine type non encore matérialisée). */
  id: number | null;
  /** null si créneau occasionnel sans template. */
  templateId: number | null;
  /** Date YYYY-MM-DD du jour précis (lundi + dayOfWeek - 1). */
  date: string;
  dayOfWeek: number; // 1 = lundi, 7 = dimanche
  startTime: string; // "HH:MM"
  durationMinutes: number;
  sport: SportKey;
  sportLabel: string;
  sportIcon: string;
  sportColor: string;
  title: string;
  location: string;
  description: string | null;
  isCancelled: boolean;
  isOverride: boolean;
  isOccasional: boolean;
  attachments: TrainingSlotAttachment[];
};

export type WeeklySchedule = {
  /** YYYY-MM-DD du lundi de la semaine. */
  week: string;
  weekLabel: string;
  isoWeek: string; // ex. "2026-W22"
  slots: TrainingSlot[];
  plans: TrainingPlan[];
};

export type StaffPresenceStatus = 'scheduled' | 'attended';

export type StaffPresence = {
  id: number;
  /** null si c'est une tâche custom (hors créneau). */
  slotId: number | null;
  isCustom: boolean;
  title: string;
  date: string; // YYYY-MM-DD
  startTime: string; // HH:MM
  durationMinutes: number;
  weekStartsAt: string; // YYYY-MM-DD
  status: StaffPresenceStatus;
  notes: string | null;
};

/** TrainingSlot tel que renvoyé par /api/me/staff-presence avec ma présence éventuelle. */
export type StaffPresenceSlot = TrainingSlot & {
  myPresence: {
    id: number;
    status: StaffPresenceStatus;
    notes: string | null;
  } | null;
};

export type StaffPresenceWeek = {
  week: string;
  slots: StaffPresenceSlot[];
  customTasks: StaffPresence[];
  /** True si le user a déclaré être non-dispo cette semaine (marqueur global). */
  unavailable: boolean;
  /** Note libre associée à l'indisponibilité (« vacances », « déplacement pro »). */
  unavailableNotes: string | null;
};

export type PoolBadge = {
  id: number;
  title: string | null;
  notes: string | null;
  imageUrl: string;
  updatedAt: string | null;
};

export type CharterFieldType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'date'
  | 'checkbox'
  | 'select'
  | 'radio';

/**
 * Public cible d'un engagement :
 *  - 'all' (défaut si absent) : visible par tout le monde
 *  - 'parent_jeune'           : uniquement pour les profils Parent ou Jeune
 *  - 'senior'                 : uniquement pour les profils Sénior
 *
 * (Valeur `other` acceptée en entrée comme alias rétro-compat de `senior`.)
 */
export type CharterFieldAudience = 'all' | 'parent_jeune' | 'senior' | 'other';

export type CharterField = {
  id: string;
  label: string;
  type: CharterFieldType;
  required?: boolean;
  help?: string;
  options?: string[];
  /**
   * Explication multi-ligne de l'engagement — surtout utile pour les
   * cases à cocher : le `label` est la phrase d'acceptation, la
   * `description` détaille ce à quoi l'adhérent s'engage.
   */
  description?: string;
  audience?: CharterFieldAudience;
};

export type Charter = {
  id: number;
  title: string;
  version: string;
  content: string;
  publishedAt: string;
  hasForm: boolean;
  fields: CharterField[];
};

export type CharterStatus = {
  charter: Charter | null;
  acceptanceRequired: boolean;
  /** true si l'user a déjà signé un formulaire d'acceptation (toutes chartes confondues). */
  hasEverAccepted: boolean;
};

export type CharterAnswers = Record<string, string | number | boolean | null>;

export const REACTION_EMOJIS = ['👍', '❤️', '🔥', '😂', '😮', '👏'] as const;
export type ReactionEmoji = (typeof REACTION_EMOJIS)[number];

/** Enfant adhérent lié à mon compte parent (Phase E). */
export type LinkedChild = {
  id: number;
  fullName: string;
  prenom: string;
  nom: string;
  numLicence: string | null;
  licenceLabel: string;
  categorieFFTri: string | null;
  profiles: string[];
  isActive: boolean;
};

export type LinkedChildrenResponse = {
  data: LinkedChild[];
  canManage: boolean;
};

/** Réponse GET /api/me/family + POST /api/me/family-link. */
export type FamilyResponse = {
  children: LinkedChild[];
  parents: LinkedChild[];
  /** Comptes liés (email/famille) pas encore déclarés comme enfant/parent. */
  assignable: LinkedChild[];
  linkedProfiles: LinkedProfile[];
};

/**
 * Types de relation posables depuis le mobile.
 * Seul le compte parent gère le lien vers ses enfants ; l'inverse
 * (« mon parent m'a déclaré ») apparaît dans la vue de l'enfant mais
 * n'est pas éditable côté enfant.
 */
export type FamilyRelation = 'child' | 'none';

/** Entraîneur sélectionnable comme destinataire de message (Phase messages). */
export type Trainer = {
  id: number;
  fullName: string;
};

/** Message envoyé depuis l'app vers le club ou un entraîneur. */
export type UserMessage = {
  id: number;
  /** null si adressé « au club » (= aux admins). */
  recipientId: number | null;
  /** « Le club » ou nom de l'entraîneur. */
  recipientLabel: string;
  subject: string | null;
  body: string;
  sentAt: string;
  /** Réponse (text) si reçue. */
  reply: string | null;
  repliedAt: string | null;
  /** Nom de l'admin/entraîneur qui a répondu. */
  repliedByLabel: string | null;
  hasReply: boolean;
};
