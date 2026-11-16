import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import { CLUB_CONTACT_EMAIL, CLUB_REGISTRATION_URL, COLORS, RADIUS, SPACING } from '@/config';

function open(url: string): void {
  void Linking.openURL(url).catch(() => { /* pas de navigateur / messagerie : rien à faire de plus */ });
}

const openRegistration = () => open(CLUB_REGISTRATION_URL);
const openMail = () => open(`mailto:${CLUB_CONTACT_EMAIL}`);

/**
 * Écran de connexion : le serveur a refusé un compte désactivé (adhésion non renouvelée pour la saison en cours, le plus
 * souvent). On le dit clairement, avec le lien pour se réinscrire et l'adresse du club — au lieu de « mot de passe
 * incorrect », qui envoyait l'adhérent chercher un mot de passe qui n'était pas en cause.
 */
export function AccountDisabledNotice() {
  return (
    <View style={styles.box} accessibilityRole="alert">
      <Text style={styles.title}>Votre compte n'est plus actif</Text>
      <Text style={styles.text}>
        L'accès à l'appli est réservé aux adhérents de la saison en cours : il se ferme lorsque l'adhésion n'est pas
        renouvelée.
      </Text>
      <Text style={styles.text}>Pour retrouver votre accès, réinscrivez-vous au club :</Text>
      <Pressable
        onPress={openRegistration}
        accessibilityRole="link"
        accessibilityLabel="Me réinscrire au club, sur le site du club"
        style={({ pressed }) => [styles.button, pressed && { opacity: 0.85 }]}
      >
        <Text style={styles.buttonLabel}>Me réinscrire au club →</Text>
      </Pressable>
      <Text style={styles.small}>
        Une erreur, une question ? Écrivez-nous :{' '}
        <Text style={styles.link} onPress={openMail} accessibilityRole="link">{CLUB_CONTACT_EMAIL}</Text>
      </Text>
    </View>
  );
}

/**
 * Sous « Vérifiez vos e-mails » (lien de connexion, mot de passe oublié) : le serveur répond pareil que l'adresse soit
 * connue ou non, donc rien n'indique à un ancien adhérent pourquoi il ne reçoit rien. Ce rappel ne révèle rien sur
 * l'adresse saisie : il vaut pour tout le monde.
 */
export function NoMailHint() {
  return (
    <Text style={styles.hint}>
      Rien reçu ? Votre adhésion n'est peut-être pas renouvelée pour la saison en cours :{' '}
      <Text style={styles.link} onPress={openRegistration} accessibilityRole="link">réinscrivez-vous sur le site du club</Text>
      {', '}ou écrivez-nous à{' '}
      <Text style={styles.link} onPress={openMail} accessibilityRole="link">{CLUB_CONTACT_EMAIL}</Text>.
    </Text>
  );
}

const styles = StyleSheet.create({
  box: {
    gap: SPACING.sm, padding: SPACING.md, marginBottom: SPACING.md, borderRadius: RADIUS.md,
    backgroundColor: COLORS.primarySoft, borderWidth: 1, borderColor: '#FFC9C9',
  },
  title: { fontSize: 15, fontWeight: '800', color: COLORS.error },
  text: { fontSize: 13, color: COLORS.text, lineHeight: 19 },
  button: { minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: RADIUS.md, backgroundColor: COLORS.primary },
  buttonLabel: { color: '#fff', fontWeight: '800', fontSize: 14 },
  small: { fontSize: 12.5, color: COLORS.textMuted, lineHeight: 18 },
  link: { color: COLORS.primary, fontWeight: '700', textDecorationLine: 'underline' },
  hint: { fontSize: 13, color: COLORS.textMuted, textAlign: 'center', lineHeight: 19, marginTop: 12 },
});
