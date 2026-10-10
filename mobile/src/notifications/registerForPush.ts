import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { api } from '@/api/client';
import { syncWebPush } from '@/notifications/webPush';

/**
 * Ask permission, retrieve Expo push token, register it backend-side.
 * Sur le web : resynchronise l'abonnement Web Push de ce navigateur (voir
 * webPush.ts) s'il a déjà été autorisé — l'activation, elle, se fait depuis le profil.
 */
export async function registerForPushNotifications(): Promise<void> {
  if (Platform.OS === 'web') {
    await syncWebPush();
    return;
  }

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
