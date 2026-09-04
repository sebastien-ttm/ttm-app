import type { FamilyRelation, FamilyResponse, LinkedChild, LinkedChildrenResponse, Trainer, UserMessage } from '@/api/types';
import { API_BASE_URL } from '@/config';
import { STORAGE_KEYS, storage } from '@/auth/storage';

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public body: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

type FetchOptions = Omit<RequestInit, 'body' | 'headers'> & {
  body?: unknown;
  headers?: Record<string, string>;
  /** if true, do not attach Authorization header */
  public?: boolean;
  /** interne : évite la boucle infinie si le retry post-refresh 401 aussi */
  _retried?: boolean;
};

let onUnauthorizedHandler: (() => void) | null = null;

export function setOnUnauthorized(handler: (() => void) | null): void {
  onUnauthorizedHandler = handler;
}

/**
 * Promesse en vol partagée : si N requêtes 401ent en simultané, on ne
 * déclenche QU'UN seul refresh, toutes attendent le même résultat.
 */
let refreshInFlight: Promise<string | null> | null = null;

/**
 * Renouvelle l'access token en utilisant le refresh_token stocké.
 * Retourne le nouveau access token, ou null si le refresh échoue
 * (refresh_token absent, expiré ou révoqué → signOut requis en aval).
 */
async function refreshAccessToken(): Promise<string | null> {
  if (refreshInFlight !== null) return refreshInFlight;
  refreshInFlight = (async () => {
    try {
      const rt = await storage.getItem(STORAGE_KEYS.refreshToken);
      if (!rt) return null;
      const resp = await fetch(`${API_BASE_URL}/api/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ refresh_token: rt }),
      });
      if (!resp.ok) return null;
      const data = await resp.json().catch(() => null) as { token?: string; refresh_token?: string } | null;
      if (!data?.token) return null;
      await storage.setItem(STORAGE_KEYS.accessToken, data.token);
      if (data.refresh_token) {
        await storage.setItem(STORAGE_KEYS.refreshToken, data.refresh_token);
      }
      return data.token;
    } catch {
      return null;
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}

async function request<T>(method: string, path: string, opts: FetchOptions = {}): Promise<T> {
  const url = path.startsWith('http') ? path : API_BASE_URL + path;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(opts.headers ?? {}),
  };

  if (opts.body !== undefined && !(opts.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  if (!opts.public) {
    const token = await storage.getItem(STORAGE_KEYS.accessToken);
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const init: RequestInit = {
    ...opts,
    method,
    headers,
    body:
      opts.body === undefined
        ? undefined
        : opts.body instanceof FormData
          ? opts.body
          : JSON.stringify(opts.body),
  };

  let response: Response;
  try {
    response = await fetch(url, init);
  } catch (err) {
    throw new ApiError(
      `Connexion impossible à ${url}. Vérifiez votre réseau.`,
      0,
      err instanceof Error ? err.message : err,
    );
  }

  // 204 No Content
  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') ?? '';
  const payload = contentType.includes('application/json') ? await response.json() : await response.text();

  if (!response.ok) {
    if (response.status === 401 && !opts.public) {
      // 1re tentative : renouvelle silencieusement l'access token via
      // le refresh (30 j côté serveur) puis rejoue la requête. Sans ça
      // l'appli déconnectait dès l'expiration du JWT (1 h).
      if (!opts._retried) {
        const newAccess = await refreshAccessToken();
        if (newAccess !== null) {
          return request<T>(method, path, { ...opts, _retried: true });
        }
      }
      // Refresh impossible (expiré, révoqué, absent) → signOut.
      onUnauthorizedHandler?.();
    }
    const message =
      typeof payload === 'object' && payload && 'error' in payload
        ? String((payload as { error: unknown }).error)
        : `${method} ${path} → HTTP ${response.status}`;
    throw new ApiError(message, response.status, payload);
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string, opts?: FetchOptions) => request<T>('GET', path, opts),
  post: <T>(path: string, body?: unknown, opts?: FetchOptions) => request<T>('POST', path, { ...opts, body }),
  put: <T>(path: string, body?: unknown, opts?: FetchOptions) => request<T>('PUT', path, { ...opts, body }),
  patch: <T>(path: string, body?: unknown, opts?: FetchOptions) => request<T>('PATCH', path, { ...opts, body }),
  delete: <T>(path: string, opts?: FetchOptions) => request<T>('DELETE', path, opts),
};

/**
 * Profils utilisateurs — alignés sur backend/src/Enum/Profile.php.
 * Un user peut en cumuler plusieurs.
 */
export type UserProfile = 'jeune' | 'senior' | 'parent' | 'entraineur' | 'encadrant';

/** Provenance du compte. */
export type UserAccountType = 'adherent' | 'externe';

/**
 * Sous-type, précise davantage selon le type :
 *  - adherent : 'club' (licencié au club) | 'autre_club' (licencié ailleurs)
 *  - externe  : 'parent' (parent d'adhérent) | 'ami' (ancien adhérent / soutien)
 */
export type UserSubType = 'club' | 'autre_club' | 'parent' | 'ami';

/**
 * Niveau d'accès au backend (gate, pas permission fine).
 * 4 tiers hiérarchiques depuis Phase B.
 */
export type UserRole = 'user' | 'editeur' | 'entraineur' | 'admin';

export type AuthenticatedUser = {
  id: number;
  email: string;
  nom: string;
  prenom: string;
  fullName: string;
  /** Null pour les comptes externes (parents inscrits via mobile). */
  numLicence: string | null;
  /** Libellé prêt à afficher : 'Non licencié' OU le numéro. */
  licenceLabel: string;
  type: UserAccountType;
  subType: UserSubType;
  profiles: UserProfile[];
  role: UserRole;
  /** True si typeLicence = 'Dirigeant' (filtrage UI spécifique). */
  isDirigeant: boolean;
  /** Catégorie FFTri calculée depuis la date de naissance (Senior, V1, Junior, ...). */
  categorieFFTri: string | null;
  /** Rétrocompat : 'jeune' / 'senior' / null. Dérivé de profiles[]. */
  categorie: 'senior' | 'jeune' | null;
  /** Rétrocompat : tableau de rôles Symfony (ROLE_USER, ROLE_EDITEUR, ROLE_ENTRAINEUR, ROLE_ADMIN). */
  roles: string[];
  hasPassword: boolean;
  /** URL publique de l'avatar carré 400×400. Null si pas d'avatar. */
  avatarUrl: string | null;
  /** Préférence opt-in : recevoir un email à chaque nouveau plan d'entraînement. */
  notifyTrainingPlanEmail: boolean;
};

/** Profil lié (parent ou enfant partageant le même e-mail). */
export type LinkedProfile = {
  id: number;
  numLicence: string | null;
  fullName: string;
  prenom: string;
  type: UserAccountType;
  profiles: UserProfile[];
  categorie: 'senior' | 'jeune' | null;
  categorieAge: string | null;
  isPrimary: boolean;
  isCurrent: boolean;
};

export type LoginResponse = {
  token: string;
  refresh_token: string;
  user: AuthenticatedUser;
  linkedProfiles?: LinkedProfile[];
};

export type RegisterParentPayload = {
  email: string;
  prenom: string;
  nom: string;
  password: string;
  childrenLicences: string[];
};

export type RegisterMemberPayload = {
  email: string;
  prenom: string;
  nom: string;
  password: string;
  /** YYYY-MM-DD */
  dateNaissance: string;
};

export const auth = {
  loginWithPassword: (email: string, password: string) =>
    api.post<LoginResponse>('/api/auth/login', { email, password }, { public: true }),
  requestMagicLink: (email: string, next?: string | null) =>
    api.post<void>('/api/auth/magic-link/request', next ? { email, next } : { email }, { public: true }),
  verifyMagicLink: (token: string) =>
    api.get<LoginResponse>(`/api/auth/magic-link/verify?token=${encodeURIComponent(token)}`, { public: true }),
  refresh: (refreshToken: string) =>
    api.post<{ token: string; refresh_token?: string }>('/api/auth/refresh', { refresh_token: refreshToken }, { public: true }),
  registerParent: (payload: RegisterParentPayload) =>
    api.post<LoginResponse>('/api/auth/register-parent', payload, { public: true }),
  registerMember: (payload: RegisterMemberPayload) =>
    api.post<LoginResponse>('/api/auth/register-member', payload, { public: true }),
  me: () => api.get<AuthenticatedUser>('/api/me'),
  setPassword: (newPassword: string) =>
    api.post<{ ok: boolean }>('/api/me/password', { new_password: newPassword }),

  /** Mise à jour partielle des préférences de notification. */
  updateNotificationPreferences: (prefs: { notifyTrainingPlanEmail?: boolean }) =>
    api.post<{ ok: boolean; notifyTrainingPlanEmail: boolean }>(
      '/api/me/notification-preferences',
      prefs,
    ),
  linkedProfiles: () =>
    api.get<{ data: LinkedProfile[] }>('/api/me/linked-profiles'),
  /** Préférer userId (marche aussi pour les comptes externes sans licence). */
  switchProfile: (params: { userId?: number; numLicence?: string }) =>
    api.post<LoginResponse>('/api/me/switch-profile', {
      user_id: params.userId,
      num_licence: params.numLicence,
    }),

  /**
   * Upload de l'avatar (multipart). `uri` accepte une URI native d'image
   * (file://) ou un Blob web (passé tel quel à FormData).
   */
  uploadAvatar: async (uri: string, mimeType = 'image/jpeg', name = 'avatar.jpg') => {
    const form = new FormData();
    // En natif RN, on passe { uri, type, name } ; en web, on doit fetch le blob.
    if (uri.startsWith('blob:') || uri.startsWith('data:')) {
      const blob = await (await fetch(uri)).blob();
      form.append('avatar', blob, name);
    } else {
      // @ts-expect-error - React Native gère cette forme spéciale pour FormData
      form.append('avatar', { uri, type: mimeType, name });
    }
    return api.post<{ ok: boolean; avatarUrl: string }>('/api/me/avatar', form);
  },

  deleteAvatar: () => api.delete<void>('/api/me/avatar'),

  // ---- Enfants liés (Phase E : self-service parent) ----
  listChildren: () => api.get<LinkedChildrenResponse>('/api/me/children'),
  addChild: (numLicence: string) =>
    api.post<{ ok: boolean; child: LinkedChild; linkedProfiles: LinkedProfile[] }>(
      '/api/me/children',
      { numLicence },
    ),
  removeChild: (id: number) =>
    api.delete<{ ok: boolean; linkedProfiles: LinkedProfile[] }>(`/api/me/children/${id}`),
  family: () => api.get<FamilyResponse>('/api/me/family'),
  setFamilyLink: (targetUserId: number, relation: FamilyRelation) =>
    api.post<FamilyResponse>('/api/me/family-link', { targetUserId, relation }),

  // ---- Messages vers le club ou un entraîneur ----
  listTrainers: () => api.get<{ data: Trainer[] }>('/api/me/trainers'),
  listMessages: () => api.get<{ data: UserMessage[] }>('/api/me/messages'),
  sendMessage: (payload: { recipientId: number | null; subject?: string; body: string }) =>
    api.post<UserMessage>('/api/me/messages', payload),
};
