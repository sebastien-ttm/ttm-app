import Ionicons from '@expo/vector-icons/Ionicons';
import { useEffect, useRef, useState } from 'react';
import { AppState, type AppStateStatus, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Détecte les nouveaux déploiements web (nouveau bundle JS servi par
 * l'hébergeur) et propose à l'user un rechargement.
 *
 * Comment ça marche :
 *  1. Au build, `scripts/inject-pwa-meta.mjs` génère `dist/version.json`
 *     (SHA git court + timestamp) et injecte le même SHA dans une
 *     `<meta name="app-version" content="...">` du index.html.
 *  2. Au démarrage, on lit la version « chargée » depuis la meta tag.
 *  3. Au retour de background après > 60 s, on fetch `/version.json?ts=…`
 *     no-cache. Si le SHA diffère → un banner discret propose de recharger.
 *
 * Ne se monte que sur web — no-op sur natif (le bundle est figé dans
 * l'APK/IPA, on utilise EAS Update pour ça).
 */
const INACTIVITY_MS = 60 * 1000;
/**
 * Poll périodique pendant qu'une session reste au premier plan (sans
 * jamais passer en background). 5 min = compromis entre réactivité
 * (l'user voit le banner dans les 5 min qui suivent un push prod) et
 * pression réseau (12 requêtes /version.json par heure et par onglet).
 */
const POLL_INTERVAL_MS = 5 * 60 * 1000;

export function WebUpdateGate() {
  const [updateAvailable, setUpdateAvailable] = useState(false);
  const loadedVersionRef = useRef<string | null>(null);
  const backgroundedAtRef = useRef<number | null>(null);
  const checkingRef = useRef(false);

  useEffect(() => {
    if (Platform.OS !== 'web' || typeof document === 'undefined') return;

    const meta = document.querySelector<HTMLMetaElement>('meta[name="app-version"]');
    const loaded = meta?.content ?? null;
    if (!loaded || loaded === '__APP_VERSION__') {
      // Placeholder non substitué → dev / build local sans meta valide.
      // On désactive tout : pas de check possible.
      return;
    }
    loadedVersionRef.current = loaded;

    async function check() {
      if (checkingRef.current) return;
      checkingRef.current = true;
      try {
        const resp = await fetch('/version.json?ts=' + Date.now(), {
          cache: 'no-store',
          headers: { 'Cache-Control': 'no-cache' },
        });
        if (!resp.ok) return;
        const data = await resp.json() as { sha?: string };
        if (typeof data.sha === 'string' && data.sha !== loadedVersionRef.current) {
          setUpdateAvailable(true);
        }
      } catch { /* silencieux — re-tenté au prochain trigger */ }
      finally { checkingRef.current = false; }
    }

    const sub = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'background' || next === 'inactive') {
        if (backgroundedAtRef.current === null) backgroundedAtRef.current = Date.now();
        return;
      }
      if (next === 'active') {
        const bgAt = backgroundedAtRef.current;
        backgroundedAtRef.current = null;
        if (bgAt === null) return;
        if (Date.now() - bgAt < INACTIVITY_MS) return;
        void check();
      }
    });

    // Web-only : listener visibilitychange (couvre le cas onglet en
    // arrière-plan sans passage en background au sens RN).
    const onVisibility = () => {
      if (typeof document === 'undefined') return;
      if (document.visibilityState !== 'visible') {
        if (backgroundedAtRef.current === null) backgroundedAtRef.current = Date.now();
        return;
      }
      const bgAt = backgroundedAtRef.current;
      backgroundedAtRef.current = null;
      if (bgAt === null) return;
      if (Date.now() - bgAt < INACTIVITY_MS) return;
      void check();
    };
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', onVisibility);
    }

    // Poll périodique pour les sessions qui restent au premier plan
    // (l'user reste actif toute la journée sur l'appli).
    // 1er check dans 30 s (couvre le cas où l'user vient d'arriver
    // pile après un déploiement), puis toutes les 5 min.
    const firstTimer = setTimeout(() => { void check(); }, 30 * 1000);
    const pollTimer = setInterval(() => {
      // Skip si onglet en background (le retour via visibilitychange
      // s'en charge, pas la peine de spammer).
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
      void check();
    }, POLL_INTERVAL_MS);

    return () => {
      sub.remove();
      clearTimeout(firstTimer);
      clearInterval(pollTimer);
      if (typeof document !== 'undefined') {
        document.removeEventListener('visibilitychange', onVisibility);
      }
    };
  }, []);

  if (!updateAvailable) return null;

  return (
    <View style={styles.banner} pointerEvents="box-none">
      <View style={styles.bannerInner}>
        <Ionicons name="cloud-download" size={20} color="#fff" />
        <Text style={styles.bannerLabel}>
          Une nouvelle version est disponible.
        </Text>
        <Pressable
          onPress={() => {
            if (typeof window !== 'undefined') window.location.reload();
          }}
          style={({ pressed }) => [styles.reloadBtn, pressed && { opacity: 0.85 }]}
        >
          <Text style={styles.reloadBtnLabel}>Recharger</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    zIndex: 9999,
    alignItems: 'center',
    padding: SPACING.md,
  },
  bannerInner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: COLORS.brandNavy,
    paddingVertical: 10,
    paddingHorizontal: 14,
    borderRadius: RADIUS.md,
    maxWidth: 520,
    width: '100%',
  },
  bannerLabel: { flex: 1, color: '#fff', fontSize: 13, fontWeight: '600' },
  reloadBtn: {
    backgroundColor: '#fff',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: RADIUS.sm,
  },
  reloadBtnLabel: { color: COLORS.brandNavy, fontWeight: '700', fontSize: 13 },
});
