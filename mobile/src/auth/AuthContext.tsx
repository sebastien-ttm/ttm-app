import { useRouter } from 'expo-router';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { ApiError, AuthenticatedUser, LoginResponse, auth, setOnUnauthorized } from '@/api/client';
import { charter as charterApi } from '@/api/resources';
import type { Charter } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { registerForPushNotifications } from '@/notifications/registerForPush';

type AuthState = {
  status: 'loading' | 'authenticated' | 'unauthenticated';
  user: AuthenticatedUser | null;
  charterRequired: boolean;
  pendingCharter: Charter | null;
};

type AuthContextValue = AuthState & {
  loginWithPassword: (email: string, password: string) => Promise<void>;
  consumeMagicLink: (token: string) => Promise<void>;
  signOut: () => Promise<void>;
  refreshMe: () => Promise<void>;
  acknowledgeCharter: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

const initialState: AuthState = {
  status: 'loading',
  user: null,
  charterRequired: false,
  pendingCharter: null,
};

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<AuthState>(initialState);
  const router = useRouter();

  const fetchCharterStatus = useCallback(async (): Promise<{ required: boolean; charter: Charter | null }> => {
    try {
      const resp = await charterApi.current();
      return {
        required: !!resp.acceptanceRequired && !!resp.charter,
        charter: resp.charter,
      };
    } catch {
      // If the call fails (network etc.), don't block the user
      return { required: false, charter: null };
    }
  }, []);

  const persist = useCallback(
    async (resp: LoginResponse) => {
      await Promise.all([
        storage.setItem(STORAGE_KEYS.accessToken, resp.token),
        storage.setItem(STORAGE_KEYS.refreshToken, resp.refresh_token),
        storage.setItem(STORAGE_KEYS.user, JSON.stringify(resp.user)),
      ]);
      const charterStatus = await fetchCharterStatus();
      setState({
        status: 'authenticated',
        user: resp.user,
        charterRequired: charterStatus.required,
        pendingCharter: charterStatus.charter,
      });
      void registerForPushNotifications();
    },
    [fetchCharterStatus],
  );

  const signOut = useCallback(async () => {
    await Promise.all([
      storage.removeItem(STORAGE_KEYS.accessToken),
      storage.removeItem(STORAGE_KEYS.refreshToken),
      storage.removeItem(STORAGE_KEYS.user),
    ]);
    setState({ ...initialState, status: 'unauthenticated' });
  }, []);

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
        setState({ ...initialState, status: 'unauthenticated' });
        return;
      }
      try {
        const fresh = await auth.me();
        if (cancelled) return;
        await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
        const charterStatus = await fetchCharterStatus();
        if (cancelled) return;
        setState({
          status: 'authenticated',
          user: fresh,
          charterRequired: charterStatus.required,
          pendingCharter: charterStatus.charter,
        });
        void registerForPushNotifications();
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 401) {
          await signOut();
          return;
        }
        // Network error → trust cached user, no charter check possible
        setState({
          status: 'authenticated',
          user: JSON.parse(userRaw) as AuthenticatedUser,
          charterRequired: false,
          pendingCharter: null,
        });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [signOut, fetchCharterStatus]);

  const loginWithPassword = useCallback(
    async (email: string, password: string) => {
      const resp = await auth.loginWithPassword(email.trim().toLowerCase(), password);
      await persist(resp);
      // The AuthGate in _layout.tsx handles where to send the user next.
    },
    [persist],
  );

  const consumeMagicLink = useCallback(
    async (token: string) => {
      const resp = await auth.verifyMagicLink(token);
      await persist(resp);
    },
    [persist],
  );

  const refreshMe = useCallback(async () => {
    const fresh = await auth.me();
    await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
    setState((s) => ({ ...s, user: fresh }));
  }, []);

  const acknowledgeCharter = useCallback(async () => {
    await charterApi.accept();
    setState((s) => ({ ...s, charterRequired: false, pendingCharter: null }));
    router.replace('/(tabs)');
  }, [router]);

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, loginWithPassword, consumeMagicLink, signOut, refreshMe, acknowledgeCharter }),
    [state, loginWithPassword, consumeMagicLink, signOut, refreshMe, acknowledgeCharter],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
