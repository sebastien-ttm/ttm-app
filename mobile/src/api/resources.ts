import { api } from './client';
import type {
  Article,
  Banner,
  CharterAnswers,
  CharterStatus,
  Comment,
  EventItem,
  MenuItem,
  Paginated,
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
  addComment: (id: number, content: string) =>
    api.post<Comment>(`/api/articles/${id}/comments`, { content }),
  toggleReaction: (id: number, emoji: string) =>
    api.put<{ action: 'added' | 'removed'; emoji: string; reactionCounts: Record<string, number> }>(
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
};

export const pages = {
  list: () => api.get<{ data: StaticPageSummary[] }>('/api/pages'),
  tree: () => api.get<{ data: StaticPageNode[] }>('/api/pages/tree'),
  get: (slug: string) => api.get<StaticPage>(`/api/pages/${slug}`),
};

export const menu = {
  list: () => api.get<{ data: MenuItem[] }>('/api/menu'),
};

export const events = {
  list: (from?: string, to?: string) => {
    const qs = new URLSearchParams();
    if (from) qs.set('from', from);
    if (to) qs.set('to', to);
    return api.get<{ data: EventItem[]; from: string; to: string }>(`/api/events?${qs.toString()}`);
  },
};

export const banner = {
  active: () => api.get<{ data: Banner | null }>('/api/banner/active', { public: true }),
};

export const charter = {
  current: () => api.get<CharterStatus>('/api/charter/current'),
  accept: (answers?: CharterAnswers) =>
    api.post<{ ok: boolean; acceptedAt?: string }>(
      '/api/me/charter/accept',
      answers ? { answers } : undefined,
    ),
};
