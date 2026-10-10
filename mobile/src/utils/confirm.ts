import { Alert, Platform } from 'react-native';

/** Demande de confirmation (web : boîte du navigateur ; natif : Alert) — résolue à true si l'utilisateur confirme. */
export function confirmAction(title: string, message: string, confirmLabel = 'Confirmer'): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(window.confirm(`${title}\n\n${message}`));
  }
  return new Promise((resolve) => {
    Alert.alert(
      title,
      message,
      [
        { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
        { text: confirmLabel, onPress: () => resolve(true) },
      ],
      { cancelable: true, onDismiss: () => resolve(false) },
    );
  });
}
