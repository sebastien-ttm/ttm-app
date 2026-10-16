import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs, useRouter } from 'expo-router';
import { Platform, Pressable, StyleSheet, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { ProfileSwitcher } from '@/components/ProfileSwitcher';
import { StaffMenuButton } from '@/components/StaffMenuButton';
import { COLORS } from '@/config';
import { SocialNewsProvider, useSocialNews } from '@/lib/useSocialNews';
import { UnansweredSurveysProvider, useUnansweredSurveys } from '@/lib/useUnansweredSurveys';
import { UnreadMessagesProvider, useUnreadMessages } from '@/lib/useUnreadMessages';
import { canSeeTrainingTab } from '@/utils/profile';

/**
 * Racine des onglets. Fournit les contextes « messages non lus »,
 * « sondages non répondus » et « nouveautés des listes Social » à tous
 * les écrans enfants (utile pour rafraîchir la pastille de l'onglet Social
 * après une action — ouverture d'un message, archivage, réponse,
 * soumission de sondage, etc.).
 */
export default function TabsLayout() {
  const { user } = useAuth();
  return (
    <UnreadMessagesProvider enabled={user !== null}>
      <UnansweredSurveysProvider enabled={user !== null}>
        <SocialNewsProvider>
          <TabsInner />
        </SocialNewsProvider>
      </UnansweredSurveysProvider>
    </UnreadMessagesProvider>
  );
}

function TabsInner() {
  const { user } = useAuth();
  const router = useRouter();
  const showTraining = canSeeTrainingTab(user);
  const { total: unreadMessages } = useUnreadMessages();
  const { count: unansweredSurveys } = useUnansweredSurveys();
  const { total: socialNews } = useSocialNews();
  const hasSocialUnread = unreadMessages > 0 || unansweredSurveys > 0 || socialNews > 0;

  // Boutons flèche retour manuels : Tabs n'injecte pas de retour
  // automatique sur les écrans hébergés hors barre principale.
  // - article : back historique (retombe sur l'accueil si vide)
  // - page statique : retour explicite vers l'onglet Informations
  //   (les pages sont accédées depuis l'arbre d'Informations, back cohérent)
  const backButton = () => (
    <Pressable
      onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)' as never))}
      hitSlop={12}
      style={{ paddingHorizontal: 12 }}
    >
      <Ionicons name="chevron-back" size={26} color="#fff" />
    </Pressable>
  );
  const backToPractical = () => (
    <Pressable
      onPress={() => router.replace('/(tabs)/practical' as never)}
      hitSlop={12}
      style={{ paddingHorizontal: 12 }}
    >
      <Ionicons name="chevron-back" size={26} color="#fff" />
    </Pressable>
  );

  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: COLORS.secondary,
        tabBarInactiveTintColor: COLORS.textMuted,
        tabBarStyle: {
          backgroundColor: COLORS.surface,
          borderTopColor: COLORS.border,
          height: Platform.OS === 'web' ? 60 : undefined,
        },
        tabBarLabelStyle: {
          fontSize: 11,
          fontWeight: '600',
          marginBottom: Platform.OS === 'web' ? 6 : 0,
        },
        // Header : blanc sur bleu marine (charte club) sur tous les tabs
        // + écrans hébergés dans (tabs).
        headerStyle: {
          backgroundColor: COLORS.brandNavy,
          borderBottomWidth: 0,
          shadowOpacity: 0,
          elevation: 0,
        },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700', fontSize: 17, color: '#fff' },
        // « Staff » (profils entraîneur / encadrant uniquement) à GAUCHE du changement de profil lié.
        headerRight: () => (
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <StaffMenuButton />
            <ProfileSwitcher />
          </View>
        ),
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Actualités',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'newspaper' : 'newspaper-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="training"
        options={{
          title: 'Entraînements',
          // Onglet visible aux licenciés (plans/créneaux) ET aux parents/jeunes
          // non licenciés qui doivent accéder au planning « Goûter du mercredi ».
          href: showTraining ? undefined : null,
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'fitness' : 'fitness-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="practical"
        options={{
          title: 'Club',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'compass' : 'compass-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="social"
        options={{
          title: 'Social',
          // L'onglet réunit messages, sondages, photos, courses, bourses… : plutôt qu'un compteur, une simple
          // pastille signale qu'il y a du non lu — message ou réponse pas encore ouvert (l'ouverture suffit, pas
          // besoin d'archiver), sondage sans réponse, nouveauté dans une liste (détail dans l'onglet).
          tabBarIcon: ({ color, focused }) => (
            <View>
              <Ionicons name={focused ? 'chatbubbles' : 'chatbubbles-outline'} color={color} size={22} />
              {hasSocialUnread && <View style={styles.dot} accessibilityLabel="Éléments non lus" />}
            </View>
          ),
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Profil',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'person' : 'person-outline'} color={color} size={22} />
          ),
        }}
      />

      {/* Routes hébergées ici pour bénéficier de la barre d'onglets, mais
          masquées comme tabs (href:null) : elles sont accessibles via
          router.push('/article/42'), '/page/statuts' — Expo Router ignore
          le préfixe (tabs) dans les URLs. Flèche retour manuelle car
          Tabs n'en injecte pas automatiquement. */}
      <Tabs.Screen
        name="article/[id]"
        options={{ href: null, title: 'Article', headerLeft: backButton }}
      />
      <Tabs.Screen
        name="page/[slug]"
        options={{ href: null, title: '', headerLeft: backToPractical }}
      />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  dot: {
    position: 'absolute',
    top: -3,
    right: -6,
    width: 11,
    height: 11,
    borderRadius: 6,
    backgroundColor: COLORS.primary,
    borderWidth: 1.5,
    borderColor: COLORS.surface,
  },
});
