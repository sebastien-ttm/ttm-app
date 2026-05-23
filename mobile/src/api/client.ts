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

export type AuthenticatedUser = {
  id: number;
  email: string;
  nom: string;
  prenom: string;
  fullName: string;
  numLicence: string;
  categorie: 'senior' | 'jeune';
  roles: string[];
  hasPassword: boolean;
};

export type LoginResponse = {
  token: string;
  refresh_token: string;
  user: AuthenticatedUser;
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
  me: () => api.get<AuthenticatedUser>('/api/me'),
  setPassword: (newPassword: string) =>
    api.post<{ ok: boolean }>('/api/me/password', { new_password: newPassword }),
};
