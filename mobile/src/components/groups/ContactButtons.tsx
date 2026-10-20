import Ionicons from '@expo/vector-icons/Ionicons';
import { Alert, Linking, Platform, Pressable, StyleSheet, View } from 'react-native';

import { COLORS } from '@/config';
import { telHref, whatsappHref } from '@/utils/phone';

async function open(url: string, failure: string) {
  try {
    await Linking.openURL(url);
  } catch {
    if (Platform.OS === 'web') window.alert(failure);
    else Alert.alert('Impossible', failure);
  }
}

/**
 * Boutons pour joindre quelqu'un : appeler, écrire sur WhatsApp et, pour un adhérent, appeler son contact d'urgence.
 * Un bouton n'apparaît que si le numéro correspondant est connu.
 */
export function ContactButtons({
  name,
  telephone,
  telephoneOf,
  telephoneUrgence,
}: {
  name: string;
  telephone?: string | null;
  /** Nom du parent quand `telephone` est le sien (enfant sans numéro). */
  telephoneOf?: string | null;
  telephoneUrgence?: string | null;
}) {
  const via = telephoneOf ? ` (via ${telephoneOf})` : '';
  const wa = telephone ? whatsappHref(telephone) : null;

  return (
    <View style={styles.row}>
      {wa && (
        <Pressable
          onPress={() => void open(wa, `Impossible d'ouvrir WhatsApp pour ${name}.`)}
          accessibilityRole="button"
          accessibilityLabel={`Écrire à ${name} sur WhatsApp${via}`}
          style={({ pressed }) => [styles.btn, styles.wa, pressed && styles.pressed]}
        >
          <Ionicons name="logo-whatsapp" size={20} color="#25D366" />
        </Pressable>
      )}
      {telephone && (
        <Pressable
          onPress={() => void open(telHref(telephone), `Impossible de lancer l'appel vers ${name}.`)}
          accessibilityRole="button"
          accessibilityLabel={`Appeler ${name}${via}`}
          style={({ pressed }) => [styles.btn, styles.call, pressed && styles.pressed]}
        >
          <Ionicons name="call" size={18} color="#fff" />
        </Pressable>
      )}
      {telephoneUrgence && (
        <Pressable
          onPress={() => void open(telHref(telephoneUrgence), `Impossible d'appeler le contact d'urgence de ${name}.`)}
          accessibilityRole="button"
          accessibilityLabel={`Appeler le contact d'urgence de ${name}`}
          style={({ pressed }) => [styles.btn, styles.emergency, pressed && styles.pressed]}
        >
          <Ionicons name="medkit" size={18} color={COLORS.error} />
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  btn: { width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center' },
  wa: { backgroundColor: COLORS.surface, borderWidth: 1.5, borderColor: '#25D366' },
  call: { backgroundColor: COLORS.success },
  emergency: { backgroundColor: '#fef2f2', borderWidth: 1.5, borderColor: COLORS.error },
  pressed: { opacity: 0.7 },
});
