import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthContext';
import { EmargementTab } from '@/components/gestion/EmargementTab';
import { MembersTab } from '@/components/gestion/MembersTab';
import { PresenceTab } from '@/components/gestion/PresenceTab';
import { COLORS, RADIUS, SPACING } from '@/config';
import { isStaffMember } from '@/utils/profile';

type GestionTab = 'presences' | 'emargements' | 'adherents';

const TABS: { key: GestionTab; label: string }[] = [
  { key: 'presences', label: 'Présences' },
  { key: 'emargements', label: 'Émargements' },
  { key: 'adherents', label: 'Adhérents' },
];

/**
 * Espace « Staff » du staff sportif (profils Entraîneur / Encadrant), ouvert
 * depuis le bouton de l'en-tête. Route /gestion : /staff est déjà le
 * trombinoscope du Club. Trois onglets :
 *  - Présences : indiquer / confirmer ses présences, semaine type ;
 *  - Émargements : présence aux événements (feuille d'émargement) et remise des
 *    bonnets du club ;
 *  - Adhérents : annuaire (nom, prénom, téléphone) avec appel en un geste.
 * `?tab=emargements` / `?tab=adherents` ouvrent directement l'onglet.
 */
export default function GestionScreen() {
  const { user } = useAuth();
  const { tab: tabParam } = useLocalSearchParams<{ tab?: string }>();
  const [tab, setTab] = useState<GestionTab>(
    tabParam === 'adherents' ? 'adherents' : tabParam === 'emargements' ? 'emargements' : 'presences',
  );

  // Garde-fou : un lien direct d'un profil sans accès retombe sur l'accueil.
  if (!isStaffMember(user)) {
    return <Redirect href="/(tabs)" />;
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Staff' }} />

      <View style={styles.tabs}>
        {TABS.map((t) => {
          const active = t.key === tab;
          return (
            <Pressable
              key={t.key}
              onPress={() => setTab(t.key)}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              style={[styles.tab, active && styles.tabActive]}
            >
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{t.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <View style={{ flex: 1 }}>
        {tab === 'presences' ? <PresenceTab /> : tab === 'emargements' ? <EmargementTab /> : <MembersTab />}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  tabs: {
    flexDirection: 'row',
    gap: SPACING.sm,
    padding: SPACING.md,
    paddingBottom: SPACING.sm,
    backgroundColor: COLORS.background,
  },
  tab: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: 10,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  tabActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  tabLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  tabLabelActive: { color: '#fff' },
});
