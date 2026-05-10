import { useRouter } from 'expo-router';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { ApiError, AuthenticatedUser, LoginResponse, auth, setOnUnauthorized } from '@/api/client';
import { STORAGE_KEYS, storage } from '@/auth/storage';

type AuthState = {
  status: 'loading' | 'authenticated' | 'unauthenticated';
  user: AuthenticatedUser | null;
};

type AuthContextValue = AuthState & {
  loginWithPassword: (email: string, password: string) => Promise<void>;
  consumeMagicLink: (token: string) => Promise<void>;
  signOut: () => Promise<void>;
  refreshMe: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading', user: null });
  const router = useRouter();

  const persist = useCallback(async (resp: LoginResponse) => {
    await Promise.all([
      storage.setItem(STORAGE_KEYS.accessToken, resp.token),
      storage.setItem(STORAGE_KEYS.refreshToken, resp.refresh_token),
      storage.setItem(STORAGE_KEYS.user, JSON.stringify(resp.user)),
    ]);
    setState({ status: 'authenticated', user: resp.user });
  }, []);

  const signOut = useCallback(async () => {
    await Promise.all([
      storage.removeItem(STORAGE_KEYS.accessToken),
      storage.removeItem(STORAGE_KEYS.refreshToken),
      storage.removeItem(STORAGE_KEYS.user),
    ]);
    setState({ status: 'unauthenticated', user: null });
  }, []);

  // Wire 401 → signout
  useEffect(() => {
    setOnUnauthorized(() => {
      void signOut();
    });
    return () => setOnUnauthorized(null);
  }, [signOut]);

  // On mount : restore session
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const [token, userRaw] = await Promise.all([
        storage.getItem(STORAGE_KEYS.accessToken),
        storage.getItem(STORAGE_KEYS.user),
      ]);
      if (cancelled) return;
      if (!token || !userRaw) {
        setState({ status: 'unauthenticated', user: null });
        return;
      }
      try {
        const fresh = await auth.me();
        if (cancelled) return;
        await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
        setState({ status: 'authenticated', user: fresh });
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 401) {
          await signOut();
          return;
        }
        // Network error → trust local cache, will retry later
        setState({ status: 'authenticated', user: JSON.parse(userRaw) as AuthenticatedUser });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [signOut]);

  const loginWithPassword = useCallback(
    async (email: string, password: string) => {
      const resp = await auth.loginWithPassword(email.trim().toLowerCase(), password);
      await persist(resp);
      router.replace('/(tabs)');
    },
    [persist, router],
  );

  const consumeMagicLink = useCallback(
    async (token: string) => {
      const resp = await auth.verifyMagicLink(token);
      await persist(resp);
      router.replace('/(tabs)');
    },
    [persist, router],
  );

  const refreshMe = useCallback(async () => {
    const fresh = await auth.me();
    await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
    setState((s) => ({ ...s, user: fresh }));
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, loginWithPassword, consumeMagicLink, signOut, refreshMe }),
    [state, loginWithPassword, consumeMagicLink, signOut, refreshMe],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
