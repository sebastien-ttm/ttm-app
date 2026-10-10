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
 *  3. À chaque « reprise » de l'appli (retour au premier plan, focus, retour du
 *     réseau, prise de contrôle d'un nouveau service worker), puis toutes les 5 min
 *     au premier plan, on fetch `/version.json?ts=…` sans cache. Si le SHA
 *     diffère → un banner discret propose de recharger.
 *
 * Une appli installée (PWA) reste vivante en mémoire des jours : c'est elle qui
 * doit avertir, sinon l'adhérent garde l'ancienne version jusqu'à ce qu'il la
 * ferme complètement. Les événements de reprise varient selon le navigateur
 * (AppState, visibilitychange, focus, pageshow) : on les écoute tous, avec un
 * délai minimal entre deux contrôles pour ne pas spammer le réseau.
 *
 * Ne se monte que sur web — no-op sur natif (le bundle est figé dans
 * l'APK/IPA, on utilise EAS Update pour ça).
 */
/** Délai minimal entre deux contrôles déclenchés par une reprise (jamais deux d'affilée). */
const MIN_CHECK_GAP_MS = 20 * 1000;
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
  const lastCheckAtRef = useRef(0);
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

    const serviceWorker = typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : null;
    const hadController = !!serviceWorker?.controller; // faux à la toute première installation

    /** Demande au navigateur de revérifier sw.js (une version par déploiement) sans attendre ses 24 h. */
    function pingServiceWorker() {
      serviceWorker?.getRegistration('/').then((registration) => registration?.update()).catch(() => undefined);
    }

    async function check() {
      if (checkingRef.current) return;
      checkingRef.current = true;
      lastCheckAtRef.current = Date.now();
      pingServiceWorker();
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

    /** Contrôle déclenché par une reprise de l'appli, au plus toutes les MIN_CHECK_GAP_MS. */
    function onResume() {
      if (Date.now() - lastCheckAtRef.current < MIN_CHECK_GAP_MS) return;
      void check();
    }

    const appStateSub = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'active') onResume();
    });
    const onVisibility = () => {
      if (document.visibilityState === 'visible') onResume();
    };
    document.addEventListener('visibilitychange', onVisibility);
    // Selon le navigateur / l'OS, une appli installée reprise de la mémoire ne déclenche
    // pas toujours visibilitychange : focus, pageshow et online prennent le relais.
    window.addEventListener('focus', onResume);
    window.addEventListener('pageshow', onResume);
    window.addEventListener('online', onResume);
    // Un nouveau service worker prend la main sur une page déjà contrôlée : un déploiement a
    // eu lieu. On vérifie la version au lieu de conclure directement — à un démarrage à froid
    // la page vient justement de se charger à jour (pas de bannière à afficher).
    const onControllerChange = () => { if (hadController) void check(); };
    serviceWorker?.addEventListener('controllerchange', onControllerChange);

    // Poll périodique pour les sessions qui restent au premier plan
    // (l'user reste actif toute la journée sur l'appli).
    // 1er check dans 30 s (couvre le cas où l'user vient d'arriver
    // pile après un déploiement), puis toutes les 5 min.
    const firstTimer = setTimeout(() => { void check(); }, 30 * 1000);
    const pollTimer = setInterval(() => {
      // Skip si onglet en background (le retour via visibilitychange
      // s'en charge, pas la peine de spammer).
      if (document.visibilityState !== 'visible') return;
      void check();
    }, POLL_INTERVAL_MS);

    return () => {
      appStateSub.remove();
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('focus', onResume);
      window.removeEventListener('pageshow', onResume);
      window.removeEventListener('online', onResume);
      serviceWorker?.removeEventListener('controllerchange', onControllerChange);
      clearTimeout(firstTimer);
      clearInterval(pollTimer);
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
