import { Stack, useRouter, useSegments } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider, consumeIntendedPath, rememberIntendedPath, useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';

function AuthGate({ children }: { children: React.ReactNode }) {
  const { status, charterRequired } = useAuth();
  const router = useRouter();
  const segments = useSegments();

  useEffect(() => {
    if (status === 'loading') return;

    // Routes "auth flow" : on n'y redirige pas le user déjà connecté,
    // et le user non-connecté a le droit d'y rester.
    // Inclut le groupe (auth) ET la route littérale /auth/magic-link
    // utilisée par les liens reçus par e-mail.
    const inAuthGroup = segments[0] === '(auth)' || segments[0] === 'auth';
    const inCharterScreen = segments[0] === 'charter-acceptance';

    if (status === 'unauthenticated') {
      if (!inAuthGroup) {
        // Deep-link : mémorise la route demandée pour y renvoyer le user
        // après login réussi.
        //
        // ⚠️ segments.join('/') renvoie le PATTERN de fichier pour les
        // routes dynamiques (ex: ['page', '[slug]']) — jamais les valeurs
        // résolues. Sur web on utilise donc window.location.pathname qui
        // porte la vraie URL. Fallback segments pour natif (pas de window).
        const path = (typeof window !== 'undefined' && window.location)
          ? window.location.pathname + (window.location.search || '')
          : '/' + segments.join('/');
        rememberIntendedPath(path);
        router.replace('/(auth)/login');
      }
      return;
    }

    // status === 'authenticated' from here on
    if (charterRequired) {
      if (!inCharterScreen) {
        router.replace('/charter-acceptance');
      }
      return;
    }

    // No charter required, but currently stuck on auth or charter screen →
    // reprend la route mémorisée (deep-link) ou tombe sur la home.
    if (inAuthGroup || inCharterScreen) {
      const intended = consumeIntendedPath();
      router.replace((intended ?? '/(tabs)') as never);
    }
  }, [status, charterRequired, segments, router]);

  // Rend un spinner tant que la destination finale n'est pas connue :
  // - status=loading : session en cours de restauration
  // - unauthenticated sur route non-auth : redirect vers login imminent
  // - charter requis mais pas encore accepté et pas sur l'écran charter
  // Empêche les enfants (ex: PageScreen) de monter et de déclencher des
  // fetch API qui échoueront en 401 pendant la fenêtre de transition.
  const inAuthGroup = segments[0] === '(auth)' || segments[0] === 'auth';
  const inCharterScreen = segments[0] === 'charter-acceptance';
  const shouldWait =
    status === 'loading' ||
    (status === 'unauthenticated' && !inAuthGroup) ||
    (status === 'authenticated' && charterRequired && !inCharterScreen);

  if (shouldWait) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: COLORS.background }}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  return <>{children}</>;
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <AuthGate>
          <StatusBar style="auto" />
          <Stack
            screenOptions={{
              headerStyle: { backgroundColor: COLORS.primary },
              headerTintColor: '#fff',
              headerTitleStyle: { fontWeight: '600' },
            }}
          >
            <Stack.Screen name="(auth)" options={{ headerShown: false }} />
            <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
            <Stack.Screen name="charter-acceptance" options={{ headerShown: false, gestureEnabled: false }} />
          </Stack>
        </AuthGate>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
