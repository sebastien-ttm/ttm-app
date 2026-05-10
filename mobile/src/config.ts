import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * API base URL.
 *
 * On native (iOS / Android emulators or device), `localhost` doesn't point to
 * your dev machine — it points to the device itself. So:
 *  - Web : http://127.0.0.1:8000  (browser & dev machine share localhost)
 *  - iOS simulator : http://127.0.0.1:8000 (works because simulator shares host)
 *  - Android emulator : http://10.0.2.2:8000 (special host alias)
 *  - Physical device : http://<your-LAN-ip>:8000  (configure in app.json `extra`)
 */
const fromConfig = (Constants.expoConfig?.extra as { apiBaseUrl?: string } | undefined)?.apiBaseUrl;

function defaultBase(): string {
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000';
  }
  return 'http://127.0.0.1:8000';
}

export const API_BASE_URL = (fromConfig ?? defaultBase()).replace(/\/$/, '');

export const APP_NAME = 'Triathlon Toulouse Métropole';

export const COLORS = {
  primary: '#D32F2F',     // rouge club
  primaryDark: '#B71C1C',
  black: '#1a1a1a',
  background: '#fafafa',
  surface: '#ffffff',
  border: '#e5e5e5',
  text: '#1a1a1a',
  textMuted: '#666666',
  success: '#2E7D32',
  error: '#C62828',
} as const;
