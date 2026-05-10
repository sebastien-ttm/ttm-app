import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { api } from '@/api/client';

/**
 * Ask permission, retrieve Expo push token, register it backend-side.
 * No-op on web (browser-based push needs a different stack).
 */
export async function registerForPushNotifications(): Promise<void> {
  if (Platform.OS === 'web') return;

  try {
    const settings = await Notifications.getPermissionsAsync();
    let granted = settings.granted || settings.status === 'granted';
    if (!granted) {
      const req = await Notifications.requestPermissionsAsync();
      granted = req.granted || req.status === 'granted';
    }
    if (!granted) return; // user declined — silent

    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'Plans et actualités',
        importance: Notifications.AndroidImportance.DEFAULT,
        lightColor: '#D32F2F',
      });
    }

    const tokenResult = await Notifications.getExpoPushTokenAsync();
    const expoToken = tokenResult.data;

    await api.post('/api/me/devices', {
      expo_push_token: expoToken,
      platform: Platform.OS === 'ios' ? 'ios' : 'android',
    });
  } catch {
    // Best-effort. We don't want push registration failures to block the user.
  }
}
