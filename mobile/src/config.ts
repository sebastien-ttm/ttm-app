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
// EXPO_PUBLIC_* env vars sont injectées au build par Expo ; permet de
// pointer vers la prod sans modifier app.json.
const fromEnv = process.env.EXPO_PUBLIC_API_BASE_URL;

function defaultBase(): string {
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000';
  }
  return 'http://127.0.0.1:8000';
}

export const API_BASE_URL = (fromEnv ?? fromConfig ?? defaultBase()).replace(/\/$/, '');

export const APP_NAME = 'Triathlon Toulouse Métropole';

export const COLORS = {
  // Couleurs officielles du club : Bleu / Rouge / Blanc.
  primary: '#D32F2F',     // rouge club
  primaryDark: '#B71C1C',
  primarySoft: '#FFE6E6',
  secondary: '#1d4ed8',   // bleu club
  secondaryDark: '#1e40af',
  secondarySoft: '#DBEAFE',
  brandNavy: '#0d2148',   // bleu marine profond — pour fonds sombres (login, banner)
  background: '#f5f6f8',
  surface: '#ffffff',
  surfaceAlt: '#fafbfc',
  border: '#e5e7eb',
  borderStrong: '#d1d5db',
  text: '#111827',
  textMuted: '#6b7280',
  textSubtle: '#9ca3af',
  success: '#16a34a',
  error: '#dc2626',
  warning: '#f59e0b',
  /** @deprecated alias rétro-compatible — utiliser brandNavy à la place */
  black: '#0d2148',
} as const;

/** Spacing scale (4-pt grid) */
export const SPACING = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

/** Border radius tokens */
export const RADIUS = {
  sm: 6,
  md: 10,
  lg: 14,
  xl: 20,
  full: 9999,
} as const;

/** Shadow presets (web + native). Use spread for ergonomics:
 *  style={[styles.card, SHADOWS.sm]} */
export const SHADOWS = {
  sm: {
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  md: {
    shadowColor: '#000',
    shadowOpacity: 0.08,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
    elevation: 3,
  },
} as const;
