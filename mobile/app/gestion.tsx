import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthContext';
import { EmargementTab } from '@/components/gestion/EmargementTab';
import { GroupTab } from '@/components/gestion/GroupTab';
import { MembersTab } from '@/components/gestion/MembersTab';
import { PresenceTab } from '@/components/gestion/PresenceTab';
import { YouthTab } from '@/components/gestion/YouthTab';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canCheckIn, canUseStaffSpace, isStaffMember } from '@/utils/profile';

type GestionTab = 'presences' | 'emargements' | 'groupe' | 'jeunes' | 'adherents';

const TABS: { key: GestionTab; label: string }[] = [
  { key: 'presences', label: 'Présences' },
  { key: 'emargements', label: 'Émargements' },
  { key: 'groupe', label: 'Groupe' },
  { key: 'jeunes', label: 'Jeunes' },
  { key: 'adherents', label: 'Adhérents' },
];

/**
 * Espace « Staff », ouvert depuis le bouton de l'en-tête. Route /gestion :
 * /staff est déjà le trombinoscope du Club. Cinq onglets, selon le profil :
 *  - Présences (entraîneur, encadrant) : indiquer / confirmer ses présences,
 *    semaine type ;
 *  - Émargements : présence aux événements (entraîneur, encadrant, CoDir,
 *    admin) ; remise des bonnets et saisie des temps des tests chronométrés
 *    (entraîneur, admin) ;
 *  - Groupe (entraîneur, encadrant) : mon groupe d'entraînement à la volée, créé avant une séance
 *    (adhérents, encadrants, informations, trace GPX, commentaires, position en direct) ;
 *  - Jeunes (entraîneur, encadrant) : fiche sanitaire et autorisation de sortie du territoire de chaque
 *    jeune, avec les documents manquants en évidence et l'ouverture en un appui ;
 *  - Adhérents (entraîneur, encadrant) : annuaire (nom, prénom, téléphone)
 *    avec appel ou message WhatsApp en un geste.
 * `?tab=emargements` / `?tab=adherents` ouvrent directement l'onglet.
 */
export default function GestionScreen() {
  const { user } = useAuth();
  const { tab: tabParam } = useLocalSearchParams<{ tab?: string }>();

  const tabs = useMemo(
    () => TABS.filter((t) => (t.key === 'emargements' ? canCheckIn(user) : isStaffMember(user))),
    [user],
  );
  const [chosen, setChosen] = useState<GestionTab | null>(
    TABS.some((t) => t.key === tabParam) ? (tabParam as GestionTab) : null,
  );
  // L'onglet demandé (ou choisi) doit être permis ; sinon le premier accessible.
  const tab = tabs.find((t) => t.key === chosen)?.key ?? tabs[0]?.key;

  // Garde-fou : un lien direct d'un profil sans accès retombe sur l'accueil.
  if (!canUseStaffSpace(user) || !tab) {
    return <Redirect href="/(tabs)" />;
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Staff' }} />

      {tabs.length > 1 && (
        // Défilement horizontal : à cinq onglets, certains libellés ne tiennent plus sur un petit téléphone.
        <View style={styles.tabsWrap}>
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.tabs}
            keyboardShouldPersistTaps="handled"
          >
            {tabs.map((t) => {
              const active = t.key === tab;
              return (
                <Pressable
                  key={t.key}
                  onPress={() => setChosen(t.key)}
                  accessibilityRole="tab"
                  accessibilityState={{ selected: active }}
                  style={[styles.tab, active && styles.tabActive]}
                >
                  <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{t.label}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
        </View>
      )}

      <View style={{ flex: 1 }}>
        {tab === 'presences' ? <PresenceTab />
          : tab === 'emargements' ? <EmargementTab />
          : tab === 'groupe' ? <GroupTab />
          : tab === 'jeunes' ? <YouthTab />
          : <MembersTab />}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  tabsWrap: { backgroundColor: COLORS.background },
  tabs: {
    flexGrow: 1,
    flexDirection: 'row',
    gap: SPACING.sm,
    padding: SPACING.md,
    paddingBottom: SPACING.sm,
    backgroundColor: COLORS.background,
  },
  tab: {
    flexGrow: 1,
    alignItems: 'center',
    paddingHorizontal: 14,
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
