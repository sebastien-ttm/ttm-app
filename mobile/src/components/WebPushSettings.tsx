import { useEffect, useState } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { COLORS, RADIUS } from '@/config';
import {
  disableWebPush,
  enableWebPush,
  getWebPushState,
  sendTestWebPush,
  type WebPushState,
} from '@/notifications/webPush';

/**
 * Carte « Notifications push » du profil, pour l'appli web / installée (PWA).
 * Sans effet sur l'appli native, qui gère ses notifications à part.
 */
export function WebPushSettings() {
  const [state, setState] = useState<WebPushState | 'loading'>('loading');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (Platform.OS !== 'web') return;
    let cancelled = false;
    getWebPushState().then((s) => { if (!cancelled) setState(s); });
    return () => { cancelled = true; };
  }, []);

  if (Platform.OS !== 'web') return null;

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setMessage(null);
    try {
      await action();
    } catch (e) {
      setMessage(e instanceof ApiError || e instanceof Error ? e.message : 'Opération impossible.');
      setState(await getWebPushState());
    } finally {
      setBusy(false);
    }
  }

  const enable = () => run(async () => setState(await enableWebPush()));
  const disable = () => run(async () => setState(await disableWebPush()));
  const test = () => run(async () => {
    const r = await sendTestWebPush();
    setMessage(
      r.sent > 0
        ? `Notification envoyée à ${r.sent} appareil${r.sent > 1 ? 's' : ''} — elle doit apparaître dans quelques secondes.`
        : 'Aucun appareil n\'a pu être notifié. Désactivez puis réactivez les notifications.',
    );
  });

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>Notifications push</Text>

      {state === 'loading' && <ActivityIndicator color={COLORS.secondary} />}

      {state === 'unsupported' && (
        <Text style={styles.hint}>Les notifications ne sont pas disponibles sur ce navigateur.</Text>
      )}

      {state === 'needs-install' && (
        <Text style={styles.hint}>
          Sur iPhone et iPad, les notifications ne fonctionnent que dans l'appli installée : touchez le bouton
          Partager de Safari, puis « Sur l'écran d'accueil ». Ouvrez ensuite l'appli depuis son icône pour
          activer les notifications.
        </Text>
      )}

      {state === 'unconfigured' && (
        <Text style={styles.hint}>
          Les notifications push ne sont pas encore activées côté club. Elles le seront prochainement.
        </Text>
      )}

      {state === 'denied' && (
        <Text style={styles.hint}>
          Les notifications sont bloquées pour cette application. Autorisez-les dans les réglages de votre
          navigateur (ou de votre téléphone), puis revenez ici.
        </Text>
      )}

      {state === 'off' && (
        <>
          <Text style={styles.hint}>
            Recevez une alerte sur cet appareil pour les nouveaux plans d'entraînement, les nouveaux articles et
            les messages qui vous sont adressés.
          </Text>
          <Pressable
            onPress={enable}
            disabled={busy}
            style={({ pressed }) => [styles.primaryBtn, (pressed || busy) && { opacity: 0.8 }]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryLabel}>🔔 Activer les notifications</Text>}
          </Pressable>
        </>
      )}

      {state === 'on' && (
        <>
          <Text style={styles.on}>✅ Notifications activées sur cet appareil.</Text>
          <View style={styles.actions}>
            <Pressable
              onPress={test}
              disabled={busy}
              style={({ pressed }) => [styles.secondaryBtn, (pressed || busy) && { opacity: 0.7 }]}
            >
              <Text style={styles.secondaryLabel}>Envoyer une notification de test</Text>
            </Pressable>
            <Pressable
              onPress={disable}
              disabled={busy}
              style={({ pressed }) => [styles.linkBtn, (pressed || busy) && { opacity: 0.7 }]}
            >
              <Text style={styles.linkLabel}>Désactiver</Text>
            </Pressable>
          </View>
        </>
      )}

      {message && <Text style={styles.message}>{message}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    gap: 10,
  },
  cardTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  hint: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  on: { fontSize: 14, fontWeight: '600', color: COLORS.success },
  actions: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' },
  primaryBtn: {
    minHeight: 46, alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingHorizontal: 16,
  },
  primaryLabel: { color: '#fff', fontSize: 15, fontWeight: '700' },
  secondaryBtn: {
    paddingHorizontal: 14, paddingVertical: 10,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  secondaryLabel: { fontSize: 13, fontWeight: '600', color: COLORS.secondaryDark },
  linkBtn: { paddingVertical: 10 },
  linkLabel: { fontSize: 13, fontWeight: '600', color: COLORS.error },
  message: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
});
