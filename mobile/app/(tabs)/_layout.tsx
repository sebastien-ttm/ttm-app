import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs, useRouter } from 'expo-router';
import { Platform, Pressable } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { ProfileSwitcher } from '@/components/ProfileSwitcher';
import { COLORS } from '@/config';
import { canSeeTraining } from '@/utils/profile';

export default function TabsLayout() {
  const { user } = useAuth();
  const router = useRouter();
  const showTraining = canSeeTraining(user);

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
          title: 'Vie du Club',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'newspaper' : 'newspaper-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="training"
        options={{
          title: 'Entraînements',
          // Parent externe non-licencié + Dirigeant : pas d'entraînement à voir.
          href: showTraining ? undefined : null,
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'fitness' : 'fitness-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="practical"
        options={{
          title: 'Informations',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'compass' : 'compass-outline'} color={color} size={22} />
          ),
        }}
      />
      <Tabs.Screen
        name="contact"
        options={{
          title: 'Contact',
          tabBarIcon: ({ color, focused }) => (
            <Ionicons name={focused ? 'chatbubbles' : 'chatbubbles-outline'} color={color} size={22} />
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
