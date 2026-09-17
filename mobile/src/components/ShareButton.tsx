import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Platform, Pressable, Share, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS } from '@/config';

/**
 * Bouton « Partager » unifié web + natif.
 *
 *  - Sur natif (iOS / Android) : ouvre la Share Sheet native
 *    (React Native `Share.share`).
 *  - Sur web mobile compatible (iOS Safari, Chrome Android, PWA
 *    installées) : ouvre le sélecteur natif via Web Share API
 *    (`navigator.share`).
 *  - Fallback web desktop : copie l'URL dans le presse-papier et
 *    affiche brièvement « Lien copié » sur le bouton.
 *
 * `path` doit être un chemin relatif (« /survey/3 »), le composant
 * construit l'URL absolue depuis window.location.origin sur web,
 * et depuis l'URL config sur natif — voir absoluteUrl().
 */
export function ShareButton({ path, title, label = 'Partager' }: {
  path: string;
  title?: string;
  label?: string;
}) {
  const [copied, setCopied] = useState(false);

  async function onPress() {
    const url = absoluteUrl(path);

    // Web : Web Share API (mobile) → clipboard fallback (desktop).
    if (Platform.OS === 'web') {
      const w = typeof window !== 'undefined' ? window : undefined;
      const nav = typeof navigator !== 'undefined' ? navigator : undefined;
      if (nav && typeof nav.share === 'function') {
        try {
          await nav.share({ title, url });
          return;
        } catch {
          /* user a annulé → on ne fait rien de plus */
          return;
        }
      }
      if (nav?.clipboard?.writeText) {
        try {
          await nav.clipboard.writeText(url);
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
          return;
        } catch { /* tombe sur le prompt de secours */ }
      }
      // Ultra-fallback : prompt (l'user sélectionne + copie manuellement).
      if (w) w.prompt('Copier l\'URL :', url);
      return;
    }

    // Natif : Share.share (RN)
    try {
      await Share.share({
        message: title ? `${title}\n${url}` : url,
        url,
        title,
      });
    } catch {
      /* silencieux */
    }
  }

  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.btn, pressed && { opacity: 0.7 }]}>
      <Ionicons name={copied ? 'checkmark' : 'share-outline'} size={16} color={COLORS.primary} />
      <Text style={styles.label}>{copied ? 'Lien copié' : label}</Text>
    </Pressable>
  );
}

/**
 * Reconstruit une URL absolue depuis un chemin relatif.
 *  - Web : `window.location.origin + path` (base courante = domaine
 *    servi par le browser, qui héberge aussi l'appli mobile web).
 *  - Natif : impossible de deviner sans config. On produit une URL
 *    du type `https://app.triathlontoulousemetropole.com{path}` en
 *    dur — modifiable via EXPO_PUBLIC_WEB_APP_URL à terme.
 */
function absoluteUrl(path: string): string {
  const clean = path.startsWith('/') ? path : '/' + path;
  if (Platform.OS === 'web' && typeof window !== 'undefined') {
    return window.location.origin + clean;
  }
  const base = (process.env.EXPO_PUBLIC_WEB_APP_URL ?? 'https://app.triathlontoulousemetropole.com').replace(/\/$/, '');
  return base + clean;
}

const styles = StyleSheet.create({
  btn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: '#fff',
  },
  label: { fontSize: 13, fontWeight: '600', color: COLORS.primary },
});
