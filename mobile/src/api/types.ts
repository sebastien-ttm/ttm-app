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
  /**
   * URL WhatsApp du CO-inscrit (https://wa.me/…) — exposée UNIQUEMENT
   * quand le viewer est lui-même inscrit sur ce créneau ET que la ligne
   * n'est pas la sienne. null sinon (privacy).
   */
  whatsappUrl: string | null;
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

export type AttendanceStatus = 'yes' | 'no' | 'maybe';

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
  /** True = admin a activé le vote de présence sur cet événement. */
  voteEnabled: boolean;
  /** Le vote actuel du viewer, ou null s'il n'a pas voté. */
  myVote: AttendanceStatus | null;
  /** Compteurs agrégés (null si voteEnabled=false). */
  voteCounts: { yes: number; no: number; maybe: number } | null;
  /** True = covoiturage activé sur cet événement (page dédiée /event/{id}/carpool). */
  carpoolingEnabled: boolean;
};

export type CarpoolRole = 'driver' | 'passenger';

export type CarpoolOffer = {
  id: number;
  userId: number;
  fullName: string;
  role: CarpoolRole;
  seatsAvailable: number | null;
  bikeSlots: number | null;
  isFull: boolean | null;
  /** null si l'user n'a pas de téléphone renseigné. */
  whatsappUrl: string | null;
  updatedAt: string;
};

export type CarpoolBoard = {
  drivers: CarpoolOffer[];
  passengers: CarpoolOffer[];
  /** La proposition du viewer (null s'il ne s'est pas encore positionné). */
  myOffer: CarpoolOffer | null;
};

/** Type de réponse d'une question de sondage. */
export type SurveyQuestionType = 'short_text' | 'long_text' | 'single_choice' | 'multi_choice';

/** Une question dans un sondage. */
export type SurveyQuestion = {
  id: string;
  label: string;
  type: SurveyQuestionType;
  required?: boolean;
  help?: string;
  /** Requis pour single_choice / multi_choice. */
  options?: string[];
};

/** Résumé (liste onglet Contact). */
export type SurveySummary = {
  id: number;
  title: string;
  description: string | null;
  publishedAt: string | null;
  closesAt: string | null;
  sectionCount: number;
  /** True si le viewer a déjà répondu (au moins une fois). */
  answered: boolean;
};

/** Détail complet + éventuelle réponse existante du viewer. */
export type Survey = {
  id: number;
  title: string;
  description: string | null;
  publishedAt: string | null;
  closesAt: string | null;
  isClosed: boolean;
  sections: SurveyQuestion[];
  myResponse: {
    /** Indexé par question id. Valeurs : string | string[] selon le type. */
    answers: Record<string, string | string[]>;
    submittedAt: string;
    updatedAt: string | null;
  } | null;
};

/** Valeurs formulaire côté client — même forme que myResponse.answers. */
export type SurveyAnswers = Record<string, string | string[]>;

/**
 * Message ponctuel poussé par les admins en cours de saison
 * (indépendant du tunnel charte). Requiert un acquittement explicite
 * (« J'ai compris ») — s'affiche en modale plein écran à l'ouverture
 * de l'appli, et à chaque retour de background après > 10 min
 * d'inactivité, tant qu'il n'est pas acquitté.
 */
export type AdminNotice = {
  id: number;
  title: string;
  /** HTML riche (rendu via RichContent). */
  content: string;
  /** Ex : « J'ai compris », « J'accepte »… — personnalisable par notice. */
  acknowledgeLabel: string;
  publishedAt: string | null;
  expiresAt: string | null;
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
  /** Tous les staff déjà positionnés sur ce créneau (moi inclus). */
  assignedStaff: AssignedStaff[];
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

/** Un staff positionné sur un créneau. */
export type AssignedStaff = {
  userId: number;
  fullName: string;
  role: 'entraineur' | 'encadrant';
  status: StaffPresenceStatus;
  notes: string | null;
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
  /** Titre optionnel affiché en tête de l'engagement (acceptation + récap). */
  title?: string;
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
  /** Message final HTML affiché juste avant le bouton « Valider mon accès ». */
  finalMessage: string | null;
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

/** Rôle CoDir d'un adhérent (source enum backend BoardRole). */
export type BoardRole = 'president' | 'tresorier' | 'secretaire' | 'membre_codir';

/** Membre affiché sur la page trombinoscope Comité. */
export type CommitteeMember = {
  id: number;
  fullName: string;
  prenom: string | null;
  nom: string | null;
  avatarUrl: string | null;
  boardRole: BoardRole | null;
  boardRoleLabel: string | null;
  clubFunction: string | null;
};

export type CommitteeResponse = {
  bureau: CommitteeMember[];
  codir: CommitteeMember[];
};

export type StaffResponse = {
  coaches: CommitteeMember[];
  encadrants: CommitteeMember[];
};

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

/**
 * Portée d'un message :
 *  - club          : adressé au club → visible admins
 *  - trainer       : adressé à UN entraîneur nommé (recipientId renseigné)
 *  - all_trainers  : adressé à TOUS les entraîneurs actifs
 */
export type MessageScope = 'club' | 'trainer' | 'all_trainers';

/**
 * Catégorie/intention d'un message :
 *  - general      : composer libre (défaut, comportement historique)
 *  - bug          : signalement de bug appli (bouton dédié)
 *  - improvement  : idée d'amélioration appli (bouton dédié)
 *  - help_offer   : proposition d'aide au club (bouton dédié)
 */
export type MessageCategory = 'general' | 'bug' | 'improvement' | 'help_offer';

/** Message envoyé depuis l'app (côté expéditeur). */
export type UserMessage = {
  id: number;
  scope: MessageScope;
  category: MessageCategory;
  /** Libellé prêt-à-afficher (« Bug appli », « Idée d'amélioration »…). */
  categoryLabel: string;
  /** Emoji illustratif (🐞, 💡, 🤝, 💬). */
  categoryIcon: string;
  /** Renseigné uniquement pour scope='trainer'. */
  recipientId: number | null;
  /** Libellé prêt-à-afficher : « Le club », « Tous les entraîneurs » ou « Prénom Nom ». */
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
  /** Horodatage d'archivage côté expéditeur (null = non archivé). */
  senderArchivedAt: string | null;
};

/**
 * Message reçu (boîte de réception d'un entraîneur ou d'un admin).
 * L'état d'archivage `myArchivedAt` est PROPRE au viewer — chaque
 * destinataire d'un message scope=all_trainers/club archive
 * indépendamment des collègues.
 */
export type InboxMessage = {
  id: number;
  scope: MessageScope;
  /** Ex : « Pour vous seul », « Pour tous les entraîneurs », « Pour le club (admins) ». */
  scopeLabel: string;
  category: MessageCategory;
  categoryLabel: string;
  categoryIcon: string;
  senderId: number;
  senderLabel: string;
  subject: string | null;
  body: string;
  sentAt: string;
  reply: string | null;
  repliedAt: string | null;
  repliedById: number | null;
  repliedByLabel: string | null;
  hasReply: boolean;
  /** false si un collègue a déjà répondu (verrou une-seule-réponse). */
  canReply: boolean;
  /** Horodatage d'archivage individuel du viewer (null = non archivé). */
  myArchivedAt: string | null;
};
