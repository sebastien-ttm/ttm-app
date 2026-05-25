import { useRouter } from 'expo-router';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { ApiError, AuthenticatedUser, LinkedProfile, LoginResponse, RegisterParentPayload, auth, setOnUnauthorized } from '@/api/client';
import { charter as charterApi } from '@/api/resources';
import type { Charter, CharterAnswers } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { registerForPushNotifications } from '@/notifications/registerForPush';

type AuthState = {
  status: 'loading' | 'authenticated' | 'unauthenticated';
  user: AuthenticatedUser | null;
  charterRequired: boolean;
  pendingCharter: Charter | null;
  linkedProfiles: LinkedProfile[];
};

type AuthContextValue = AuthState & {
  loginWithPassword: (email: string, password: string) => Promise<void>;
  consumeMagicLink: (token: string) => Promise<void>;
  registerParent: (payload: RegisterParentPayload) => Promise<void>;
  signOut: () => Promise<void>;
  refreshMe: () => Promise<void>;
  acknowledgeCharter: (answers?: CharterAnswers) => Promise<void>;
  switchProfile: (numLicence: string) => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

const initialState: AuthState = {
  status: 'loading',
  user: null,
  charterRequired: false,
  pendingCharter: null,
  linkedProfiles: [],
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
        linkedProfiles: resp.linkedProfiles ?? [],
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
        const [fresh, linked] = await Promise.all([
          auth.me(),
          auth.linkedProfiles().catch(() => ({ data: [] })),
        ]);
        if (cancelled) return;
        await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
        const charterStatus = await fetchCharterStatus();
        if (cancelled) return;
        setState({
          status: 'authenticated',
          user: fresh,
          charterRequired: charterStatus.required,
          pendingCharter: charterStatus.charter,
          linkedProfiles: linked.data ?? [],
        });
        void registerForPushNotifications();
      } catch (err) {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 401) {
          await signOut();
          return;
        }
        setState({
          status: 'authenticated',
          user: JSON.parse(userRaw) as AuthenticatedUser,
          charterRequired: false,
          pendingCharter: null,
          linkedProfiles: [],
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

  const registerParent = useCallback(
    async (payload: RegisterParentPayload) => {
      const resp = await auth.registerParent(payload);
      await persist(resp);
    },
    [persist],
  );

  const refreshMe = useCallback(async () => {
    const fresh = await auth.me();
    await storage.setItem(STORAGE_KEYS.user, JSON.stringify(fresh));
    setState((s) => ({ ...s, user: fresh }));
  }, []);

  const acknowledgeCharter = useCallback(
    async (answers?: CharterAnswers) => {
      await charterApi.accept(answers);
      setState((s) => ({ ...s, charterRequired: false, pendingCharter: null }));
      router.replace('/(tabs)');
    },
    [router],
  );

  const switchProfile = useCallback(
    async (numLicence: string) => {
      const resp = await auth.switchProfile(numLicence);
      await persist(resp);
      // Restart navigation to refresh all data (feed, etc. with new user)
      router.replace('/(tabs)');
    },
    [persist, router],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      ...state,
      loginWithPassword,
      consumeMagicLink,
      registerParent,
      signOut,
      refreshMe,
      acknowledgeCharter,
      switchProfile,
    }),
    [state, loginWithPassword, consumeMagicLink, registerParent, signOut, refreshMe, acknowledgeCharter, switchProfile],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}
