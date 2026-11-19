import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthContext';
import { AdherentsTab, type AdherentsView } from '@/components/gestion/AdherentsTab';
import { CodirTab, type CodirView } from '@/components/gestion/CodirTab';
import { TrainingsTab, type TrainingsView } from '@/components/gestion/TrainingsTab';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canCheckIn, canUseBoard, canUseStaffSpace } from '@/utils/profile';

type GestionTab = 'entrainements' | 'adherents' | 'codir';

const TABS: { key: GestionTab; label: string }[] = [
  { key: 'entrainements', label: 'Entraînements' },
  { key: 'adherents', label: 'Adhérents' },
  { key: 'codir', label: 'Comité directeur' },
];

/**
 * Liens directs : l'onglet (et la vue de l'onglet) qu'ouvre chaque valeur de `?tab=`, y compris les anciennes valeurs
 * d'avant le regroupement en trois onglets (favoris, liens enregistrés).
 */
const LINKS: Record<string, { tab: GestionTab; training?: TrainingsView; adherents?: AdherentsView; codir?: CodirView }> = {
  entrainements: { tab: 'entrainements' },
  presences: { tab: 'entrainements', training: 'presences' },
  groupe: { tab: 'entrainements', training: 'groupes' },
  adherents: { tab: 'adherents', adherents: 'list' },
  emargements: { tab: 'adherents', adherents: 'sheets' },
  codir: { tab: 'codir' },
  reunions: { tab: 'codir', codir: 'reunions' },
  minibus: { tab: 'codir', codir: 'minibus' },
};

/**
 * Espace « Staff », ouvert depuis le bouton de l'en-tête. Route /gestion :
 * /staff est déjà le trombinoscope du Club. Trois onglets, selon le profil ; dans un onglet, un petit sélecteur sous les
 * onglets distingue les vues :
 *  - Entraînements : Présences (entraîneur, encadrant : indiquer / confirmer ses présences, semaine type), Groupes
 *    (entraîneur, encadrant : mon groupe d'entraînement à la volée, créé avant une séance : adhérents, encadrants,
 *    informations, trace GPX, commentaires, position en direct) et Émargement & tests (présence aux entraînements sur
 *    inscription pour le staff, le CoDir et les admins ; temps des tests chronométrés pour les entraîneurs et admins) ;
 *  - Adhérents : Liste (entraîneur, encadrant, administrateur dont gestionnaire externe : annuaire des adhérents, séniors et jeunes ensemble, appel ou message
 *    WhatsApp en un geste, fiche sanitaire et autorisation de rentrer seul des jeunes) et Bonnets & événements (remise des
 *    bonnets pour les entraîneurs et admins ; présence aux événements pour le staff, le CoDir et les admins) ;
 *  - Comité directeur : deux sous-parties — Réunions (membre du CoDir, administrateur, entraîneur : actions en cours et
 *    réunions, avec l'ordre du jour de chacune, ex-section de l'onglet Social) et Emprunt minibus (membres du CoDir :
 *    calendrier du minibus du club, demande de réservation, charte, états des lieux).
 * `?tab=entrainements` / `adherents` / `codir` ouvrent directement l'onglet ; les anciennes valeurs (`presences`,
 *  `groupe`, `emargements`) mènent à la vue correspondante ; `?tab=minibus` ouvre l'Emprunt minibus.
 */
export default function GestionScreen() {
  const { user } = useAuth();
  const { tab: tabParam } = useLocalSearchParams<{ tab?: string }>();
  const link = tabParam ? LINKS[tabParam] : undefined;

  const tabs = useMemo(
    () => TABS.filter((t) => (t.key === 'codir' ? canUseBoard(user) : canCheckIn(user))),
    [user],
  );
  const [chosen, setChosen] = useState<GestionTab | null>(link?.tab ?? null);
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
        // Défilement horizontal : certains libellés pourraient ne plus tenir sur un petit téléphone.
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
        {tab === 'entrainements' ? <TrainingsTab initialView={link?.training} />
          : tab === 'adherents' ? <AdherentsTab initialView={link?.adherents} />
          : <CodirTab initialView={link?.codir} />}
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
