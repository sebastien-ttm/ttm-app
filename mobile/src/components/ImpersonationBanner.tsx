import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { COLORS } from '@/config';

/**
 * Bandeau permanent affiché en haut de l'app quand un admin est connecté
 * par impersonation (magic link avec flag ?impersonate=1). Rappel visuel
 * qu'on ne voit PAS son propre compte + bouton de sortie rapide.
 *
 * Le flag est stocké dans localStorage à la consommation du magic link,
 * effacé par signOut() du AuthContext (voir AuthContext::signOut).
 */
export function ImpersonationBanner() {
  const { user, signOut } = useAuth();

  const active =
    typeof window !== 'undefined' &&
    !!window.localStorage &&
    (() => {
      try { return window.localStorage.getItem('ttm.impersonating') === '1'; } catch { return false; }
    })();

  if (!active || !user) return null;

  return (
    <View style={styles.bar}>
      <Text style={styles.icon}>🎭</Text>
      <Text style={styles.text} numberOfLines={1}>
        Connecté en tant que <Text style={styles.name}>{user.fullName}</Text> (session admin)
      </Text>
      <Pressable
        onPress={() => void signOut()}
        style={({ pressed }) => [styles.exitBtn, pressed && { opacity: 0.7 }]}
      >
        <Text style={styles.exitLabel}>Sortir</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 12,
    paddingVertical: 6,
    backgroundColor: '#fef3c7',
    borderBottomWidth: 1,
    borderBottomColor: '#f59e0b',
  },
  icon: { fontSize: 16 },
  text: { flex: 1, fontSize: 12, color: '#78350f' },
  name: { fontWeight: '700' },
  exitBtn: {
    backgroundColor: COLORS.primary,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 4,
  },
  exitLabel: { color: '#fff', fontSize: 11, fontWeight: '700' },
});
