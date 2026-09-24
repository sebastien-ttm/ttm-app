import { api } from './client';
import type {
  AdminNotice,
  Article,
  Banner,
  Survey,
  SurveyAnswers,
  SurveySummary,
  CharterAnswers,
  CharterStatus,
  Comment,
  CarpoolBoard,
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
  PoolBadge,
  StaffPresence,
  StaffPresenceStatus,
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

  /** Marque le user comme non-dispo pour la semaine cible. */
  setUnavailable: (week: string, notes?: string) =>
    api.post<{ ok: boolean; unavailable: boolean; unavailableNotes: string | null }>(
      '/api/me/staff-presence/unavailable',
      { week, notes },
    ),
  /** Retire le marqueur non-dispo. */
  unsetUnavailable: (week: string) =>
    api.delete<{ ok: boolean; unavailable: false }>(
      `/api/me/staff-presence/unavailable?week=${encodeURIComponent(week)}`,
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
    api.post<{ ok: boolean; myVote: string | null; voteCounts: { yes: number; no: number; maybe: number } }>(
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

export const carpool = {
  get: (eventId: number) => api.get<CarpoolBoard>(`/api/events/${eventId}/carpool`),
  upsert: (eventId: number, payload: {
    role: CarpoolRole;
    seatsAvailable?: number | null;
    bikeSlots?: number | null;
    isFull?: boolean;
  }) => api.post<{ ok: boolean; offer: CarpoolOffer }>(`/api/events/${eventId}/carpool`, payload),
  remove: (eventId: number) => api.delete<{ ok: boolean }>(`/api/events/${eventId}/carpool`),
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
  /** Compteur pour le badge « non répondus » (titre + onglet Contact). */
  unansweredCount: () => api.get<{ count: number }>('/api/me/surveys/unanswered-count'),
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

export const staff = {
  get: () => api.get<StaffResponse>('/api/staff'),
};
