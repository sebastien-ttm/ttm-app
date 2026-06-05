import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs } from 'expo-router';
import { Platform } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { ProfileSwitcher } from '@/components/ProfileSwitcher';
import { COLORS } from '@/config';
import { canSeeTraining } from '@/utils/profile';

export default function TabsLayout() {
  const { user } = useAuth();
  const showTraining = canSeeTraining(user);

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
        headerStyle: {
          backgroundColor: COLORS.surface,
          borderBottomColor: COLORS.border,
          borderBottomWidth: 1,
          shadowOpacity: 0,
          elevation: 0,
        },
        headerTintColor: COLORS.text,
        headerTitleStyle: { fontWeight: '700', fontSize: 17 },
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
          title: 'Pratique',
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
    </Tabs>
  );
}
