import { Linking, Platform, StyleSheet, Text, type StyleProp, type TextStyle } from 'react-native';

import { COLORS } from '@/config';
import { decodeEntities } from '@/utils/html';

/**
 * Texte libre avec liens cliquables, pour les descriptions saisies par les adhérents (points d'ordre du jour, commentaires,
 * descriptions de compétition…) : on peut y renvoyer vers un fichier Google Drive, un site, une adresse e-mail.
 *
 * Trois écritures sont reconnues (seul ce qui suit devient un lien, tout le reste reste du texte brut — jamais de HTML
 * interprété) :
 *  - le lien HTML       : `<a href="https://drive.google.com/…">Compte rendu</a>`
 *  - le lien Markdown   : `[Compte rendu](https://drive.google.com/…)`
 *  - l'adresse collée   : `https://drive.google.com/…` (affichée sans « https:// », raccourcie si elle est longue)
 * Seuls les liens http, https et mailto sont acceptés : `javascript:` et consorts restent du texte.
 */
type Piece = { kind: 'text'; value: string } | { kind: 'link'; label: string; url: string };

// Alternatives, dans l'ordre : <a href="…">…</a> · [libellé](adresse) · adresse nue.
const LINK_PATTERN =
  /<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|'([^']*)')[^>]*>([\s\S]*?)<\/a\s*>|\[([^\]\n]+)\]\(\s*(\S+?)\s*\)|(https?:\/\/[^\s<>"']+)/gi;

const MAX_BARE_LABEL = 42;

/** Adresse acceptée (http, https, mailto) ou null. Les entités HTML d'un href (`&amp;`) sont décodées. */
function safeUrl(raw: string): string | null {
  const url = decodeEntities(raw).trim();
  return /^(https?:\/\/|mailto:)[^\s]+$/i.test(url) ? url : null;
}

function bareLabel(url: string): string {
  const shown = url.replace(/^https?:\/\//i, '').replace(/^mailto:/i, '').replace(/\/$/, '');
  return shown.length > MAX_BARE_LABEL ? shown.slice(0, MAX_BARE_LABEL - 1) + '…' : shown;
}

/** Découpe le texte en morceaux de texte et de liens. Exporté pour les tests manuels. */
export function splitLinks(text: string): Piece[] {
  const pieces: Piece[] = [];
  let last = 0;
  for (const m of text.matchAll(LINK_PATTERN)) {
    const index = m.index ?? 0;
    let matched = m[0];
    let url: string | null;
    let label: string;

    if (m[6] !== undefined) {
      // Adresse nue : la ponctuation finale d'une phrase n'en fait pas partie.
      const trailing = /[.,;:!?)\]]+$/.exec(matched)?.[0] ?? '';
      matched = trailing ? matched.slice(0, -trailing.length) : matched;
      url = safeUrl(matched);
      label = bareLabel(matched);
    } else if (m[5] !== undefined) {
      url = safeUrl(m[5]);
      label = m[4].trim();
    } else {
      url = safeUrl(m[1] ?? m[2] ?? '');
      // Texte du lien HTML : sans balises ni entités ; à défaut, l'adresse elle-même.
      label = decodeEntities(m[3].replace(/<[^>]*>/g, '')).trim();
      if (label === '' && url) label = bareLabel(url);
    }

    if (url === null) continue; // adresse refusée (javascript:…) : reste du texte brut
    if (index > last) pieces.push({ kind: 'text', value: text.slice(last, index) });
    pieces.push({ kind: 'link', label: label || bareLabel(url), url });
    last = index + matched.length;
  }
  if (last < text.length) pieces.push({ kind: 'text', value: text.slice(last) });
  return pieces;
}

function open(url: string): void {
  if (Platform.OS === 'web' && typeof window !== 'undefined') {
    window.open(url, '_blank', 'noopener,noreferrer');
    return;
  }
  void Linking.openURL(url).catch(() => { /* pas de navigateur / messagerie : rien à faire de plus */ });
}

/**
 * `<Text>` dont les liens sont cliquables. Peut être imbriqué dans un autre `<Text>` (le style hérite) : passer alors
 * `style` seulement si nécessaire.
 */
export function LinkedText({ text, style, linkStyle }: {
  text: string;
  style?: StyleProp<TextStyle>;
  linkStyle?: StyleProp<TextStyle>;
}) {
  const pieces = splitLinks(text);
  return (
    <Text style={style}>
      {pieces.map((p, i) => p.kind === 'text'
        ? p.value
        : (
          <Text
            key={i}
            onPress={() => open(p.url)}
            accessibilityRole="link"
            style={[styles.link, linkStyle]}
          >
            {p.label}
          </Text>
        ))}
    </Text>
  );
}

const styles = StyleSheet.create({
  link: { color: COLORS.secondary, textDecorationLine: 'underline', fontWeight: '600' },
});
