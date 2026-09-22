import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs, useRouter } from 'expo-router';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { ProfileSwitcher } from '@/components/ProfileSwitcher';
import { COLORS } from '@/config';
import { UnansweredSurveysProvider, useUnansweredSurveys } from '@/lib/useUnansweredSurveys';
import { UnreadMessagesProvider, useUnreadMessages } from '@/lib/useUnreadMessages';
import { canSeeTrainingTab } from '@/utils/profile';

/**
 * Racine des onglets. Fournit les contextes « messages non lus » et
 * « sondages non répondus » à tous les écrans enfants (utile pour
 * rafraîchir les badges après une action côté Contact — archivage,
 * réponse, soumission de sondage, etc.).
 */
export default function TabsLayout() {
  const { user } = useAuth();
  return (
    <UnreadMessagesProvider enabled={user !== null}>
      <UnansweredSurveysProvider enabled={user !== null}>
        <TabsInner />
      </UnansweredSurveysProvider>
    </UnreadMessagesProvider>
  );
}

function TabsInner() {
  const { user } = useAuth();
  const router = useRouter();
  const showTraining = canSeeTrainingTab(user);
  const { total: unreadCount } = useUnreadMessages();
  const { count: unansweredSurveys } = useUnansweredSurveys();

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
        headerRight: () => <ProfileSwitcher />,
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
        name="contact"
        options={{
          title: 'Contact',
          // Pas de tabBarBadge natif (un seul badge supporté) : on
          // superpose 2 badges chiffrés dans tabBarIcon —
          // messages non lus (rouge, réponses reçues non archivées +
          // inbox à traiter pour les staff) et sondages non répondus
          // (ambre, couleur distincte). >99 → « 99+ » sur chacun.
          tabBarIcon: ({ color, focused }) => (
            <View>
              <Ionicons name={focused ? 'chatbubbles' : 'chatbubbles-outline'} color={color} size={22} />
              {unreadCount > 0 && (
                <View style={[styles.badge, styles.badgeMessages]}>
                  <Text style={styles.badgeLabel}>{unreadCount > 99 ? '99+' : unreadCount}</Text>
                </View>
              )}
              {unansweredSurveys > 0 && (
                <View style={[styles.badge, styles.badgeSurveys, unreadCount > 0 && styles.badgeSurveysShifted]}>
                  <Text style={styles.badgeLabel}>{unansweredSurveys > 99 ? '99+' : unansweredSurveys}</Text>
                </View>
              )}
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
  badge: {
    position: 'absolute',
    top: -6,
    right: -10,
    minWidth: 16,
    height: 16,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 3,
    borderWidth: 1.5,
    borderColor: COLORS.surface,
  },
  badgeMessages: { backgroundColor: COLORS.primary },
  badgeSurveys: { backgroundColor: COLORS.warning },
  // Décale le badge sondages à gauche du badge messages quand les 2
  // sont affichés en même temps, pour éviter qu'ils se chevauchent.
  badgeSurveysShifted: { right: -22 },
  badgeLabel: { color: '#fff', fontSize: 10, fontWeight: '700', lineHeight: 13 },
});
