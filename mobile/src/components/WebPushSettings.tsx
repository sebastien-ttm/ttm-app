import { useEffect, useState } from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, Switch, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { webPush as webPushApi } from '@/api/resources';
import type { PushTopicSetting } from '@/api/types';
import { COLORS, RADIUS } from '@/config';
import {
  disableWebPush,
  enableWebPush,
  getWebPushState,
  sendTestWebPush,
  type WebPushState,
} from '@/notifications/webPush';

/**
 * Choix des sujets de notification (nouvel événement, nouvel article…). Réglage par adhérent, tout est activé
 * par défaut ; chaque interrupteur est enregistré tout de suite.
 */
function PushTopicList() {
  const [topics, setTopics] = useState<PushTopicSetting[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    webPushApi.topics()
      .then((r) => { if (!cancelled) setTopics(r.data); })
      .catch(() => { if (!cancelled) setError('Impossible de charger vos choix de notifications.'); });
    return () => { cancelled = true; };
  }, []);

  async function toggle(key: string, enabled: boolean) {
    setError(null);
    const apply = (value: boolean) => setTopics((cur) => cur && cur.map((t) => (t.key === key ? { ...t, enabled: value } : t)));
    apply(enabled); // optimiste : l'interrupteur bouge tout de suite
    try {
      await webPushApi.setTopics({ [key]: enabled });
    } catch {
      apply(!enabled);
      setError('Enregistrement impossible, réessayez.');
    }
  }

  if (topics === null) {
    return error ? <Text style={styles.message}>{error}</Text> : <ActivityIndicator color={COLORS.secondary} />;
  }

  return (
    <View style={styles.topics}>
      <Text style={styles.topicsTitle}>Me prévenir pour :</Text>
      {topics.map((t, i) => (
        <View key={t.key} style={[styles.topicRow, i > 0 && styles.topicRowBorder]}>
          <View style={{ flex: 1 }}>
            <Text style={styles.topicLabel}>{t.label}</Text>
            <Text style={styles.topicHint}>{t.description}</Text>
          </View>
          <Switch
            value={t.enabled}
            onValueChange={(v) => toggle(t.key, v)}
            trackColor={{ false: '#d4d4d8', true: COLORS.brandNavy }}
            thumbColor="#fff"
            ios_backgroundColor="#d4d4d8"
            // Boule blanche aussi une fois activé : propriété propre à react-native-web, absente des types de Switch.
            {...({ activeThumbColor: '#fff' } as object)}
          />
        </View>
      ))}
      {error && <Text style={styles.message}>{error}</Text>}
    </View>
  );
}

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
            Recevez une alerte sur cet appareil pour les nouveautés du club (événements, articles, plans
            d'entraînement, sondages…) et les messages qui vous sont adressés. Vous choisirez ensuite les sujets qui
            vous intéressent.
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
          <PushTopicList />
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
  topics: { gap: 0 },
  topicsTitle: { fontSize: 13, fontWeight: '700', color: COLORS.text, marginBottom: 6 },
  topicRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10 },
  topicRowBorder: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border },
  topicLabel: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  topicHint: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17, marginTop: 1 },
});
