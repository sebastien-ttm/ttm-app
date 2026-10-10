import { api } from './client';
import type {
  AdminNotice,
  Article,
  Banner,
  BibOffer,
  BibOfferInput,
  Survey,
  SurveyAnswers,
  SurveySummary,
  CharterAnswers,
  CharterStatus,
  Comment,
  CarpoolBoard,
  CarpoolKind,
  CarpoolOffer,
  CarpoolRole,
  CommitteeResponse,
  StaffResponse,
  EventItem,
  GouterPlanning,
  MarketplaceConversation,
  MarketplaceConversationSummary,
  MarketplaceListing,
  MarketplaceListingSummary,
  MarketplaceMessage,
  MenuItem,
  Paginated,
  PerfTestDeclaration,
  PerfTestEntryState,
  CapRow,
  CapState,
  CheckInSheet,
  CheckInState,
  StaffCheckInEvent,
  StaffDirectorySeason,
  StaffMember,
  StaffPerfTestSession,
  StaffPerfTestSheet,
  PerfTestsMineResponse,
  PerfTestsResponse,
  PhotoAlbum,
  PhotoAlbumPage,
  PhotoImage,
  PoolBadge,
  RaceProposal,
  RaceProposalInput,
  RaceTypeOption,
  RaceVote,
  StaffAbsenceReason,
  StaffPresence,
  StaffPresenceStatus,
  StaffPresenceTemplateSlot,
  StaffPresenceWeek,
  StaticPage,
  StaticPageNode,
  StaticPageSummary,
  TrainingPlan,
  WeeklySchedule,
} from './types';

export const articles = {
  list: (page = 1) => api.get<Paginated<Article>>(`/api/articles?page=${page}`),
  get: (id: number) => api.get<Article>(`/api/articles/${id}`),
  comments: (id: number, page = 1) =>
    api.get<Paginated<Comment>>(`/api/articles/${id}/comments?page=${page}`),
  addComment: (id: number, content: string, parentId?: number | null) =>
    api.post<Comment>(`/api/articles/${id}/comments`, {
      content,
      ...(parentId ? { parentId } : {}),
    }),
  toggleReaction: (id: number, emoji: string) =>
    api.put<{
      action: 'added' | 'removed';
      emoji: string;
      reactionCounts: Record<string, number>;
      /** Réactions du user courant après l'opération (0 ou 1 emoji — exclusif). */
      myReactions: string[];
    }>(
      `/api/articles/${id}/reactions`,
      { emoji },
    ),
};

export const trainingPlans = {
  list: (page = 1) => api.get<Paginated<TrainingPlan>>(`/api/training-plans?page=${page}`),
  get: (id: number) => api.get<TrainingPlan>(`/api/training-plans/${id}`),
};

/** Tests chronométrés (1500 m, 400 m nage, montée vélo) : temps de tous les adhérents, par saison d'entraînement. */
export const perfTests = {
  /** Mes demandes de temps déclarés (en attente, acceptées, refusées). */
  declarations: () => api.get<{ data: PerfTestDeclaration[] }>('/api/perf-tests/declarations'),
  /** Déclare un temps pris individuellement ; les entraîneurs l'acceptent ou le refusent. date = YYYY-MM-DD. */
  declare: (payload: { test: string; poolLength: number | null; date: string; time: string; comment?: string }) =>
    api.post<PerfTestDeclaration>('/api/perf-tests/declarations', payload),
  /** Annule ma demande tant qu'elle est en attente. */
  cancelDeclaration: (id: number) => api.delete<{ ok: boolean }>(`/api/perf-tests/declarations/${id}`),
  /** « Mon évolution » : tous mes temps, toutes saisons, par épreuve. */
  mine: () => api.get<PerfTestsMineResponse>('/api/perf-tests/mine'),
  /** season = année de début de la saison (2025 → saison 2025-2026) ; défaut : la plus récente avec des temps. */
  list: (season?: number) => api.get<PerfTestsResponse>(`/api/perf-tests${season ? `?season=${season}` : ''}`),
};

/** Émargement de la présence aux événements soumis au vote — entraîneurs et membres du CoDir. */
export const staffCheckIn = {
  events: () => api.get<{ data: StaffCheckInEvent[] }>('/api/staff/check-in/events'),
  sheet: (eventId: number) => api.get<CheckInSheet>(`/api/staff/check-in/events/${eventId}`),
  setChecked: (eventId: number, userId: number, checked: boolean) =>
    api.put<CheckInState>(`/api/staff/check-in/events/${eventId}/members/${userId}`, { checked }),
};

/** Émargement de la remise des bonnets du club — entraîneurs uniquement. */
export const staffCaps = {
  list: () => api.get<{ data: CapRow[]; total: number; received: number }>('/api/staff/caps'),
  give: (userId: number) => api.post<CapState>(`/api/staff/caps/${userId}/give`, {}),
  undo: (userId: number) => api.post<CapState>(`/api/staff/caps/${userId}/undo`, {}),
};

/** Saisie des temps des tests chronométrés en cours ou récents — entraîneurs uniquement. */
export const staffPerfTests = {
  list: () => api.get<{ data: StaffPerfTestSession[] }>('/api/staff/perf-tests'),
  sheet: (sessionId: number) => api.get<StaffPerfTestSheet>(`/api/staff/perf-tests/${sessionId}`),
  /** time vide = efface le temps ; 422 « Temps illisible » si le format n'est pas reconnu. */
  saveTime: (sessionId: number, userId: number, time: string) =>
    api.put<PerfTestEntryState>(`/api/staff/perf-tests/${sessionId}/results/${userId}`, { time }),
};

/** Annuaire des adhérents (nom, prénom, téléphone) — réservé aux profils entraîneur / encadrant. */
export const staffDirectory = {
  list: () => api.get<{ data: StaffMember[]; total: number; season: StaffDirectorySeason | null }>('/api/staff/members'),
};

/** Notifications push web (PWA) : configuration serveur et abonnement de ce navigateur. */
export const webPush = {
  config: () => api.get<{ enabled: boolean; publicKey: string | null }>('/api/me/push/config'),
  subscribe: (subscription: unknown) => api.post<{ ok: boolean }>('/api/me/push/subscriptions', subscription),
  unsubscribe: (endpoint: string) => api.delete<void>('/api/me/push/subscriptions', { body: { endpoint } }),
  /** Envoie tout de suite une notification de test à mes appareils abonnés. */
  test: () => api.post<{ sent: number; failed: number }>('/api/me/push/test', {}),
};

export const trainingSchedule = {
  /** week au format YYYY-MM-DD (n'importe quel jour de la semaine ciblée). */
  week: (week?: string) => {
    const qs = week ? `?week=${encodeURIComponent(week)}` : '';
    return api.get<WeeklySchedule>(`/api/training-schedule${qs}`);
  },
};

export const staffPresence = {
  week: (week?: string) => {
    const qs = week ? `?week=${encodeURIComponent(week)}` : '';
    return api.get<StaffPresenceWeek>(`/api/me/staff-presence${qs}`);
  },
  setForSlot: (params: {
    slotId?: number;
    templateId?: number;
    week?: string;
    status: StaffPresenceStatus;
    notes?: string;
  }) => api.post<StaffPresence>('/api/me/staff-presence/slot', params),
  createCustom: (params: {
    title: string;
    date: string;
    startTime: string;
    durationMinutes: number;
    status?: StaffPresenceStatus;
    notes?: string;
  }) => api.post<StaffPresence>('/api/me/staff-presence/custom', params),
  update: (id: number, patch: Partial<{
    status: StaffPresenceStatus;
    notes: string | null;
    title: string;
    date: string;
    startTime: string;
    durationMinutes: number;
  }>) => api.patch<StaffPresence>(`/api/me/staff-presence/${id}`, patch),
  remove: (id: number) => api.delete<void>(`/api/me/staff-presence/${id}`),

  /** Marque le user comme non-dispo pour la semaine cible (motif obligatoire). */
  setUnavailable: (week: string, reason: StaffAbsenceReason, notes?: string) =>
    api.post<{ ok: boolean; unavailable: boolean; unavailableReason: StaffAbsenceReason; unavailableNotes: string | null }>(
      '/api/me/staff-presence/unavailable',
      { week, reason, notes },
    ),
  /** Retire le marqueur non-dispo. */
  unsetUnavailable: (week: string) =>
    api.delete<{ ok: boolean; unavailable: false }>(
      `/api/me/staff-presence/unavailable?week=${encodeURIComponent(week)}`,
    ),
  /** Marque le user comme non-dispo sur UNE journée précise (motif obligatoire). */
  setDayUnavailable: (date: string, reason: StaffAbsenceReason, notes?: string) =>
    api.post<{ ok: boolean; date: string; reason: StaffAbsenceReason; notes: string | null }>(
      '/api/me/staff-presence/day-unavailable',
      { date, reason, notes },
    ),
  /** Retire la déclaration d'absence d'une journée. */
  unsetDayUnavailable: (date: string) =>
    api.delete<{ ok: boolean; date: string }>(
      `/api/me/staff-presence/day-unavailable?date=${encodeURIComponent(date)}`,
    ),
  /**
   * Pose 'unavailable' UNIQUEMENT sur les slots où l'user n'a pas
   * encore de présence — ne marque pas la semaine entière. Sert au
   * bouton « Je ne suis pas dispo sur les créneaux manquants ».
   */
  setUnavailableMissing: (week: string) =>
    api.post<{ ok: boolean; week: string; markedCount: number }>(
      '/api/me/staff-presence/unavailable-missing',
      { week },
    ),

  // ---- Semaine de présence type (configurable une fois pour la saison) ----
  getTemplate: () => api.get<{ slots: StaffPresenceTemplateSlot[] }>('/api/me/staff-presence/template'),
  setTemplateSlot: (slotTemplateId: number, present: boolean) =>
    api.post<{ ok: boolean; slotTemplateId: number; present: boolean }>(
      '/api/me/staff-presence/template',
      { slotTemplateId, present },
    ),
  /** Positionne ma présence de la semaine cible d'après ma semaine type — écrase les choix déjà posés. */
  applyTemplate: (week: string) =>
    api.post<{ ok: boolean; week: string; scheduledCount: number; unavailableCount: number }>(
      '/api/me/staff-presence/apply-template',
      { week },
    ),
};

export const gouters = {
  planning: (from?: string, to?: string) => {
    const qs = new URLSearchParams();
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);
    const q = qs.toString();
    return api.get<GouterPlanning>(`/api/gouters${q ? '?' + q : ''}`);
  },
  signup: (date: string) =>
    api.post<{ id: number; date: string }>('/api/gouters', { date }),
  cancel: (id: number) => api.delete<void>(`/api/gouters/${id}`),
};

export const pages = {
  list: () => api.get<{ data: StaticPageSummary[] }>('/api/pages'),
  tree: () => api.get<{ data: StaticPageNode[] }>('/api/pages/tree'),
  get: (slug: string) => api.get<StaticPage>(`/api/pages/${slug}`),
};

export const menu = {
  list: () => api.get<{ data: MenuItem[] }>('/api/menu'),
};

/**
 * Édition d'un commentaire — endpoint transverse (indépendant de
 * l'hôte article/événement, cf. CommentController côté backend).
 * Seul l'auteur peut éditer son propre commentaire.
 */
export const comments = {
  edit: (commentId: number, content: string) =>
    api.patch<Comment>(`/api/comments/${commentId}`, { content }),
};

export const events = {
  list: (from?: string, to?: string) => {
    const qs = new URLSearchParams();
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);
    return api.get<{ data: EventItem[]; from: string; to: string }>(`/api/events?${qs.toString()}`);
  },
  get: (id: number) => api.get<EventItem>(`/api/events/${id}`),
  /** status=null retire le vote (l'user redevient indéterminé). */
  setAttendance: (id: number, status: 'yes' | 'no' | 'maybe' | null) =>
    api.post<{ ok: boolean; myVote: string | null; voteCounts: { yes: number; no: number; maybe: number } | null }>(
      `/api/events/${id}/attendance`,
      { status },
    ),
  comments: (id: number) =>
    api.get<{ data: Comment[]; total: number }>(`/api/events/${id}/comments`),
  addComment: (id: number, content: string, parentId?: number | null) =>
    api.post<Comment>(`/api/events/${id}/comments`, {
      content,
      ...(parentId ? { parentId } : {}),
    }),
};

function carpoolPath(kind: CarpoolKind, id: number): string {
  return `/api/${kind === 'event' ? 'events' : 'races'}/${id}/carpool`;
}

export const carpool = {
  get: (kind: CarpoolKind, id: number) => api.get<CarpoolBoard>(carpoolPath(kind, id)),
  upsert: (kind: CarpoolKind, id: number, payload: {
    role: CarpoolRole;
    seatsAvailable?: number | null;
    bikeSlots?: number | null;
    isFull?: boolean;
  }) => api.post<{ ok: boolean; offer: CarpoolOffer }>(carpoolPath(kind, id), payload),
  remove: (kind: CarpoolKind, id: number) => api.delete<{ ok: boolean }>(carpoolPath(kind, id)),
};

export const banner = {
  active: () => api.get<{ data: Banner | null }>('/api/banner/active', { public: true }),
};

export const surveys = {
  /** Sondages ouverts pour l'audience du viewer. */
  list: () => api.get<{ data: SurveySummary[] }>('/api/me/surveys'),
  get: (id: number) => api.get<Survey>(`/api/me/surveys/${id}`),
  /** Soumission ou mise à jour (upsert). */
  submit: (id: number, answers: SurveyAnswers) =>
    api.post<Survey>(`/api/me/surveys/${id}/response`, { answers }),
  /** Compteur pour le badge « non répondus » (titre + onglet Contact) — hors sondages écartés. */
  unansweredCount: () => api.get<{ count: number }>('/api/me/surveys/unanswered-count'),
  /** « Pas concerné » : coche le sondage sans y répondre (il sort du compteur). */
  dismiss: (id: number) => api.put<{ dismissed: boolean }>(`/api/me/surveys/${id}/dismissal`),
  /** Annule le « pas concerné ». */
  undismiss: (id: number) => api.delete<{ dismissed: boolean }>(`/api/me/surveys/${id}/dismissal`),
};

export const notices = {
  /** Notices publiées non-expirées non-acquittées par le viewer, filtrées par audience. */
  pending: () => api.get<{ data: AdminNotice[] }>('/api/me/notices/pending'),
  acknowledge: (id: number) =>
    api.post<{ ok: boolean; acknowledgedAt: string | null; alreadyAcknowledged?: boolean }>(
      `/api/me/notices/${id}/acknowledge`,
      {},
    ),
};

export const poolBadge = {
  current: () => api.get<{ data: PoolBadge | null }>('/api/pool-badge'),
};

export const charter = {
  current: () => api.get<CharterStatus>('/api/charter/current'),
  accept: (answers?: CharterAnswers) =>
    api.post<{ ok: boolean; acceptedAt?: string }>(
      '/api/me/charter/accept',
      answers ? { answers } : undefined,
    ),
};

export const committee = {
  get: () => api.get<CommitteeResponse>('/api/committee'),
};

/**
 * Bourse aux équipements (onglet Club). Les uploads de photos suivent
 * le même idiome multipart que `auth.uploadAvatar` dans client.ts :
 * URI native RN → { uri, type, name } ; blob/data URI web → fetch+blob.
 * Champ `photos[]` (convention PHP pour que Symfony les reçoive comme
 * un tableau côté `$request->files->all('photos')`).
 */
type MarketplacePhotoInput = { uri: string; mimeType: string; name: string };

async function appendMarketplacePhoto(form: FormData, photo: MarketplacePhotoInput): Promise<void> {
  const { uri, mimeType, name } = photo;
  if (uri.startsWith('blob:') || uri.startsWith('data:')) {
    const blob = await (await fetch(uri)).blob();
    form.append('photos[]', blob, name);
  } else {
    // @ts-expect-error - React Native gère cette forme spéciale pour FormData
    form.append('photos[]', { uri, type: mimeType, name });
  }
}

export const marketplace = {
  /** true si le compte courant a accès à la bourse (phase de test réservée à quelques comptes). */
  access: () => api.get<{ enabled: boolean }>('/api/marketplace/access'),
  /** Annonces publiées, plus récentes d'abord. */
  list: () => api.get<{ data: MarketplaceListingSummary[] }>('/api/marketplace/listings'),
  /** Mes annonces (publiées + en pause). */
  mine: () => api.get<{ data: MarketplaceListing[] }>('/api/marketplace/mine'),
  get: (id: number) => api.get<MarketplaceListing>(`/api/marketplace/listings/${id}`),

  /** Création (multipart) — jusqu'à 5 photos. */
  create: async (title: string, description: string, photos: MarketplacePhotoInput[]) => {
    const form = new FormData();
    form.append('title', title);
    form.append('description', description);
    for (const p of photos) await appendMarketplacePhoto(form, p);
    return api.post<MarketplaceListing>('/api/marketplace/listings', form);
  },

  /** Édition titre/texte seul — les photos passent par les endpoints dédiés. */
  update: (id: number, patch: { title?: string; description?: string }) =>
    api.patch<MarketplaceListing>(`/api/marketplace/listings/${id}`, patch),

  addPhotos: async (id: number, photos: MarketplacePhotoInput[]) => {
    const form = new FormData();
    for (const p of photos) await appendMarketplacePhoto(form, p);
    return api.post<MarketplaceListing>(`/api/marketplace/listings/${id}/photos`, form);
  },
  removePhoto: (id: number, photoId: number) =>
    api.delete<MarketplaceListing>(`/api/marketplace/listings/${id}/photos/${photoId}`),

  pause: (id: number) => api.post<MarketplaceListing>(`/api/marketplace/listings/${id}/pause`, {}),
  publish: (id: number) => api.post<MarketplaceListing>(`/api/marketplace/listings/${id}/publish`, {}),
  remove: (id: number) => api.delete<void>(`/api/marketplace/listings/${id}`),

  // ---- Discussions (contact acheteur ↔ vendeur, dans l'app) ----
  /** Mes discussions (acheteur ou vendeur), la plus récente d'abord. */
  conversations: () => api.get<{ data: MarketplaceConversationSummary[] }>('/api/marketplace/conversations'),
  conversation: (id: number) => api.get<MarketplaceConversation>(`/api/marketplace/conversations/${id}`),
  /** Premier message au vendeur : ouvre (ou reprend) la discussion pour cette annonce. */
  startConversation: (listingId: number, content: string) =>
    api.post<MarketplaceConversation>(`/api/marketplace/listings/${listingId}/conversation`, { content }),
  sendMessage: (conversationId: number, content: string) =>
    api.post<{ ok: boolean; message: MarketplaceMessage }>(
      `/api/marketplace/conversations/${conversationId}/messages`,
      { content },
    ),
};

/** Photos du club : albums de la galerie Piwigo, via le backend. */
export const photos = {
  /** enabled=false tant que la galerie n'est pas configurée côté serveur. */
  albums: () => api.get<{ enabled: boolean; data: PhotoAlbum[] }>('/api/photos/albums'),
  album: (id: number, page = 0) => api.get<PhotoAlbumPage>(`/api/photos/albums/${id}?page=${page}`),
  createAlbum: (name: string, comment: string) => api.post<PhotoAlbum>('/api/photos/albums', { name, comment }),
  /** Envoie UNE photo (déjà réduite) — l'appelant boucle pour la progression. */
  upload: async (albumId: number, photo: MarketplacePhotoInput) => {
    const form = new FormData();
    form.append('consent', '1');
    const { uri, mimeType, name } = photo;
    if (uri.startsWith('blob:') || uri.startsWith('data:')) {
      form.append('photo', await (await fetch(uri)).blob(), name);
    } else {
      // @ts-expect-error - React Native gère cette forme spéciale pour FormData
      form.append('photo', { uri, type: mimeType, name });
    }
    return api.post<PhotoImage>(`/api/photos/albums/${albumId}/images`, form);
  },
  remove: (imageId: number) => api.delete<void>(`/api/photos/images/${imageId}`),
};

/** Bourse aux dossards (même accès que la bourse aux équipements). */
export const bibs = {
  /** Offres publiées pour des courses à venir, la plus proche d'abord. */
  list: () => api.get<{ data: BibOffer[] }>('/api/bibs'),
  /** Mes offres (publiées, en pause, passées). */
  mine: () => api.get<{ data: BibOffer[] }>('/api/bibs/mine'),
  get: (id: number) => api.get<BibOffer>(`/api/bibs/${id}`),
  create: (input: BibOfferInput) => api.post<BibOffer>('/api/bibs', input),
  update: (id: number, patch: Partial<BibOfferInput>) => api.patch<BibOffer>(`/api/bibs/${id}`, patch),
  pause: (id: number) => api.post<BibOffer>(`/api/bibs/${id}/pause`, {}),
  publish: (id: number) => api.post<BibOffer>(`/api/bibs/${id}/publish`, {}),
  remove: (id: number) => api.delete<void>(`/api/bibs/${id}`),
  /** Premier message à l'auteur : ouvre (ou reprend) la discussion. */
  startConversation: (id: number, content: string) =>
    api.post<MarketplaceConversation>(`/api/bibs/${id}/conversation`, { content }),
};

/** Courses proposées par les adhérents (onglet Social) + votes d'intérêt. */
export const races = {
  /** Courses à venir, la plus proche d'abord, + liste des types disponibles. */
  list: () => api.get<{ data: RaceProposal[]; types: RaceTypeOption[] }>('/api/races'),
  get: (id: number) => api.get<RaceProposal>(`/api/races/${id}`),
  create: (input: RaceProposalInput) => api.post<RaceProposal>('/api/races', input),
  update: (id: number, patch: Partial<RaceProposalInput>) => api.patch<RaceProposal>(`/api/races/${id}`, patch),
  remove: (id: number) => api.delete<void>(`/api/races/${id}`),
  /** null = retire le vote. */
  vote: (id: number, status: RaceVote | null) => api.post<RaceProposal>(`/api/races/${id}/vote`, { status }),
};

export const staff = {
  get: () => api.get<StaffResponse>('/api/staff'),
};
