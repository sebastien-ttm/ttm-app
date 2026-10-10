import { Image } from 'expo-image';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { COLORS } from '@/config';

type Props = {
  prenom: string;
  nom: string;
  /** URL de la photo de l'adhérent ; ses initiales sont affichées à défaut. */
  avatarUrl: string | null;
  size?: number;
  /** Si fourni, un appui sur la photo (pas sur les initiales) appelle cette fonction (agrandir). */
  onPress?: () => void;
};

/** Photo ronde d'un adhérent, ou ses initiales sur fond bleu marine s'il n'en a pas. */
export function MemberAvatar({ prenom, nom, avatarUrl, size = 52, onPress }: Props) {
  const shape = { width: size, height: size, borderRadius: size / 2 };

  if (!avatarUrl) {
    const initials = ((prenom[0] ?? '') + (nom[0] ?? '')).toUpperCase() || '?';
    return (
      <View
        style={[styles.placeholder, shape]}
        accessibilityElementsHidden
        importantForAccessibility="no"
      >
        <Text style={[styles.initials, { fontSize: Math.round(size * 0.33) }]}>{initials}</Text>
      </View>
    );
  }

  const image = <Image source={{ uri: avatarUrl }} style={[styles.image, shape]} contentFit="cover" />;
  if (!onPress) return image;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="imagebutton"
      accessibilityLabel={`Agrandir la photo de ${prenom} ${nom}`}
      style={({ pressed }) => pressed && { opacity: 0.8 }}
    >
      {image}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  image: { backgroundColor: COLORS.surfaceAlt },
  placeholder: { alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.brandNavy },
  initials: { color: '#fff', fontWeight: '700' },
});
