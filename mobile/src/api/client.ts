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
};

let onUnauthorizedHandler: (() => void) | null = null;

export function setOnUnauthorized(handler: (() => void) | null): void {
  onUnauthorizedHandler = handler;
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
export type UserProfile = 'jeune' | 'senior' | 'u25' | 'parent' | 'entraineur' | 'encadrant';

/** Provenance du compte. */
export type UserAccountType = 'adherent' | 'externe';

/** Niveau d'accès (gate, pas permission fine). */
export type UserRole = 'user' | 'admin';

export type AuthenticatedUser = {
  id: number;
  email: string;
  nom: string;
  prenom: string;
  fullName: string;
  /** Null pour les comptes externes (parents inscrits via mobile). */
  numLicence: string | null;
  type: UserAccountType;
  profiles: UserProfile[];
  role: UserRole;
  /** Rétrocompat : 'jeune' / 'senior' / null. Dérivé de profiles[]. */
  categorie: 'senior' | 'jeune' | null;
  /** Rétrocompat : tableau de rôles Symfony (ROLE_USER, ROLE_ADMIN). */
  roles: string[];
  hasPassword: boolean;
  /** URL publique de l'avatar carré 400×400. Null si pas d'avatar. */
  avatarUrl: string | null;
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

export const auth = {
  loginWithPassword: (email: string, password: string) =>
    api.post<LoginResponse>('/api/auth/login', { email, password }, { public: true }),
  requestMagicLink: (email: string) =>
    api.post<void>('/api/auth/magic-link/request', { email }, { public: true }),
  verifyMagicLink: (token: string) =>
    api.get<LoginResponse>(`/api/auth/magic-link/verify?token=${encodeURIComponent(token)}`, { public: true }),
  refresh: (refreshToken: string) =>
    api.post<{ token: string; refresh_token?: string }>('/api/auth/refresh', { refresh_token: refreshToken }, { public: true }),
  registerParent: (payload: RegisterParentPayload) =>
    api.post<LoginResponse>('/api/auth/register-parent', payload, { public: true }),
  me: () => api.get<AuthenticatedUser>('/api/me'),
  setPassword: (newPassword: string) =>
    api.post<{ ok: boolean }>('/api/me/password', { new_password: newPassword }),
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
};
