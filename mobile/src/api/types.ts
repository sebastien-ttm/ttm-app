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

export type Article = {
  id: number;
  title: string;
  content: string;
  publishedAt: string | null;
  author: UserSummary;
  photos: Photo[];
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
};

export type StaticPageSummary = { slug: string; title: string };

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
  type: 'course' | 'stage' | 'entrainement' | 'social';
  color: string;
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

export type CharterField = {
  id: string;
  label: string;
  type: CharterFieldType;
  required?: boolean;
  help?: string;
  options?: string[];
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
