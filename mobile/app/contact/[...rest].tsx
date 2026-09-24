import { Redirect, useLocalSearchParams } from 'expo-router';

/**
 * Anciennes adresses /contact/... (l'onglet Contact a été rebaptisé
 * « Social », routes /social/...). Les e-mails de notification déjà
 * envoyés contiennent /contact/sent/12 ou /contact/inbox/12 : on les
 * renvoie vers le nouvel emplacement en conservant le chemin restant
 * et la query string. Un visiteur non connecté passe d'abord par
 * l'AuthGate, qui mémorise l'adresse d'origine et la rejoue après login
 * — donc aussi cette redirection.
 */
export default function LegacyContactRedirect() {
  const { rest, ...query } = useLocalSearchParams<Record<string, string | string[]>>();
  const path = Array.isArray(rest) ? rest.join('/') : (rest ?? '');

  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    for (const v of Array.isArray(value) ? value : [value]) {
      if (v !== undefined) qs.append(key, String(v));
    }
  }
  const suffix = qs.toString();

  return <Redirect href={(`/social/${path}${suffix ? `?${suffix}` : ''}`) as never} />;
}
