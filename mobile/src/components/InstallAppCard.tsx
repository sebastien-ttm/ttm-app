import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS, RADIUS } from '@/config';
import {
  getInstallMode,
  requestInstall,
  subscribeInstallMode,
  type InstallMode,
} from '@/lib/installPrompt';

function useInstallMode(): InstallMode {
  const [mode, setMode] = useState<InstallMode>(() => getInstallMode());
  useEffect(() => {
    const update = () => setMode(getInstallMode());
    update();
    return subscribeInstallMode(update);
  }, []);
  return mode;
}

/**
 * Carte « Installer l'application » du profil (appli web uniquement) : un bouton
 * quand le navigateur sait installer l'appli (Chrome), sinon la marche à suivre
 * (Safari sur iPhone / iPad, menu du navigateur sur Android). Disparaît une fois
 * l'appli installée, puisqu'on est alors dans l'appli elle-même.
 */
export function InstallAppCard() {
  const mode = useInstallMode();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  if (mode === 'hidden' && !message) return null;

  async function install() {
    setBusy(true);
    setMessage(null);
    try {
      const outcome = await requestInstall();
      if (outcome === 'accepted') {
        setMessage('✅ Installation lancée : retrouvez bientôt l\'appli TTM sur votre écran d\'accueil et ouvrez-la depuis son icône.');
      } else if (outcome === 'dismissed') {
        setMessage('Installation annulée. Vous pouvez la relancer depuis le menu de votre navigateur (« Installer l\'application »).');
      }
    } catch {
      setMessage('L\'installation n\'a pas pu démarrer. Essayez depuis le menu de votre navigateur (« Installer l\'application »).');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>Installer l'application</Text>

      {mode === 'native' && (
        <>
          <Text style={styles.hint}>
            Ajoutez TTM à votre écran d'accueil : un appui pour l'ouvrir, en plein écran, comme une appli, avec les
            notifications.
          </Text>
          <Pressable
            onPress={install}
            disabled={busy}
            accessibilityRole="button"
            style={({ pressed }) => [styles.primaryBtn, (pressed || busy) && { opacity: 0.8 }]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryLabel}>📲 Installer l'application</Text>}
          </Pressable>
        </>
      )}

      {mode === 'ios' && (
        <>
          <Text style={styles.hint}>
            Ajoutez TTM à votre écran d'accueil pour l'ouvrir comme une appli (et recevoir les notifications) :
          </Text>
          <Text style={styles.step}>1. Dans Safari, touchez le bouton Partager (le carré avec une flèche vers le haut).</Text>
          <Text style={styles.step}>2. Faites défiler et choisissez « Sur l'écran d'accueil ».</Text>
          <Text style={styles.step}>3. Touchez « Ajouter », puis ouvrez l'appli depuis son icône.</Text>
        </>
      )}

      {mode === 'manual' && (
        <>
          <Text style={styles.hint}>
            Ajoutez TTM à votre écran d'accueil pour l'ouvrir comme une appli (et recevoir les notifications) :
          </Text>
          <Text style={styles.step}>1. Ouvrez le menu ⋮ de votre navigateur (en haut à droite).</Text>
          <Text style={styles.step}>2. Choisissez « Installer l'application » (ou « Ajouter à l'écran d'accueil »).</Text>
          <Text style={styles.step}>3. Confirmez, puis ouvrez l'appli depuis son icône.</Text>
          <Text style={styles.note}>
            L'option est absente du menu ? Utilisez Chrome, actualisez la page et naviguez un instant dans l'appli.
          </Text>
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
  step: { fontSize: 14, color: COLORS.text, lineHeight: 20, paddingLeft: 4 },
  note: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17 },
  primaryBtn: {
    minHeight: 46, alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingHorizontal: 16,
  },
  primaryLabel: { color: '#fff', fontSize: 15, fontWeight: '700' },
  message: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
});
