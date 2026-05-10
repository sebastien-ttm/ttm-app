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

export type Charter = {
  id: number;
  title: string;
  version: string;
  content: string;
  publishedAt: string;
};

export type CharterStatus = {
  charter: Charter | null;
  acceptanceRequired: boolean;
};

export const REACTION_EMOJIS = ['👍', '❤️', '🔥', '😂', '😮', '👏'] as const;
export type ReactionEmoji = (typeof REACTION_EMOJIS)[number];
