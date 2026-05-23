import { Stack, useRouter, useSegments } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { AuthProvider, useAuth } from '@/auth/AuthContext';
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

    // No charter required, but currently stuck on auth or charter screen → home
    if (inAuthGroup || inCharterScreen) {
      router.replace('/(tabs)');
    }
  }, [status, charterRequired, segments, router]);

  if (status === 'loading') {
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
