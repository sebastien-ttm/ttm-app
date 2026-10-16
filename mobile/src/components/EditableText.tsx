import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View, type StyleProp, type TextStyle } from 'react-native';

import { ApiError } from '@/api/client';
import { COLORS, RADIUS, SPACING } from '@/config';
import { confirmAction } from '@/utils/confirm';

/**
 * Texte d'un message que son auteur peut corriger : affichage normal avec la mention « modifié » si le texte a été
 * changé, un bouton « Modifier » (si `canEdit`) et, si `onDelete` est fourni, un bouton « Supprimer » (avec
 * confirmation), et un éditeur en ligne (Enregistrer / Annuler) à l'appui. `onSave`
 * renvoie une promesse : en cas d'échec l'éditeur reste ouvert avec l'erreur.
 *
 * `tone="onDark"` : pour un texte sur fond coloré (bulle « moi » de la bourse) — mention et lien en blanc.
 */
export function EditableText({
  text,
  editedAt,
  canEdit,
  onSave,
  maxLength,
  textStyle,
  tone = 'default',
  startEditing: openEditor = false,
  onDelete,
  deleteConfirm = 'Ce message sera supprimé.',
}: {
  text: string;
  /** Date de la dernière modification (ISO) ; null / absent = jamais modifié. */
  editedAt?: string | null;
  canEdit: boolean;
  onSave: (next: string) => Promise<void>;
  maxLength: number;
  textStyle?: StyleProp<TextStyle>;
  tone?: 'default' | 'onDark';
  /** Ouvre directement l'éditeur (ex. bouton « Modifier » de la liste des messages). */
  startEditing?: boolean;
  /** Si fourni (et `canEdit`), un bouton « Supprimer » propose d'effacer ce message après confirmation. */
  onDelete?: () => Promise<void>;
  /** Précise ce qui disparaît avec le message (réponse, conversation…), affiché dans la confirmation. */
  deleteConfirm?: string;
}) {
  const [editing, setEditing] = useState(canEdit && openEditor);
  const [draft, setDraft] = useState(text);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const onDark = tone === 'onDark';
  const metaColor = onDark ? 'rgba(255,255,255,0.8)' : COLORS.textMuted;
  const linkColor = onDark ? '#fff' : COLORS.secondaryDark;

  function startEditing() {
    setDraft(text);
    setError(null);
    setEditing(true);
  }

  async function remove() {
    if (!onDelete || busy) return;
    if (!(await confirmAction('Supprimer ce message ?', deleteConfirm, 'Supprimer'))) return;
    setBusy(true);
    setError(null);
    try {
      await onDelete();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Suppression impossible. Réessayez.');
      setBusy(false);
    }
    // Succès : le parent retire ce message de l'écran (le composant est démonté).
  }

  async function save() {
    const next = draft.trim();
    if (next === '' || busy) return;
    if (next === text) {
      setEditing(false);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await onSave(next);
      setEditing(false);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Modification impossible. Réessayez.');
    } finally {
      setBusy(false);
    }
  }

  if (editing) {
    return (
      <View style={styles.editor}>
        <TextInput
          value={draft}
          onChangeText={setDraft}
          multiline
          autoFocus
          maxLength={maxLength}
          editable={!busy}
          style={styles.input}
          placeholderTextColor={COLORS.textSubtle}
        />
        {error !== null && <Text style={styles.error}>{error}</Text>}
        <View style={styles.actions}>
          <Pressable
            onPress={() => setEditing(false)}
            disabled={busy}
            style={[styles.btn, onDark ? styles.btnGhostOnDark : styles.btnGhost]}
          >
            <Text style={onDark ? styles.btnGhostOnDarkLabel : styles.btnGhostLabel}>Annuler</Text>
          </Pressable>
          <Pressable
            onPress={() => void save()}
            disabled={busy || draft.trim() === ''}
            style={[styles.btn, onDark ? styles.btnPrimaryOnDark : styles.btnPrimary, (busy || draft.trim() === '') && { opacity: 0.45 }]}
          >
            {busy
              ? <ActivityIndicator size="small" color={onDark ? COLORS.primary : '#fff'} />
              : <Text style={onDark ? styles.btnPrimaryOnDarkLabel : styles.btnPrimaryLabel}>Enregistrer</Text>}
          </Pressable>
        </View>
      </View>
    );
  }

  return (
    <View>
      <Text style={textStyle}>{text}</Text>
      {(editedAt || canEdit) && (
        <View style={styles.meta}>
          {editedAt ? <Text style={[styles.metaText, { color: metaColor }]}>modifié</Text> : null}
          {canEdit && (
            <Pressable
              onPress={startEditing}
              accessibilityRole="button"
              accessibilityLabel="Modifier ce message"
              hitSlop={8}
              style={({ pressed }) => [
                styles.editLink,
                { borderColor: onDark ? 'rgba(255,255,255,0.6)' : COLORS.border },
                pressed && { opacity: 0.6 },
              ]}
            >
              <Ionicons name="create-outline" size={15} color={linkColor} />
              <Text style={[styles.editLinkLabel, { color: linkColor }]}>Modifier</Text>
            </Pressable>
          )}
          {canEdit && onDelete && (
            <Pressable
              onPress={() => void remove()}
              disabled={busy}
              accessibilityRole="button"
              accessibilityLabel="Supprimer ce message"
              hitSlop={8}
              style={({ pressed }) => [
                styles.editLink,
                { borderColor: onDark ? 'rgba(255,255,255,0.6)' : COLORS.border },
                (pressed || busy) && { opacity: 0.6 },
              ]}
            >
              {busy
                ? <ActivityIndicator size="small" color={onDark ? '#fff' : COLORS.error} />
                : <Ionicons name="trash-outline" size={15} color={onDark ? '#fff' : COLORS.error} />}
              <Text style={[styles.editLinkLabel, { color: onDark ? '#fff' : COLORS.error }]}>Supprimer</Text>
            </Pressable>
          )}
        </View>
      )}
      {error !== null && <Text style={styles.error}>{error}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  meta: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 6 },
  metaText: { fontSize: 11, fontStyle: 'italic' },
  // Bouton bien visible (un simple lien gris de 11 px passait inaperçu).
  editLink: {
    flexDirection: 'row', alignItems: 'center', gap: 5,
    paddingHorizontal: 10, paddingVertical: 5,
    borderRadius: RADIUS.full, borderWidth: 1,
  },
  editLinkLabel: { fontSize: 13, fontWeight: '700' },
  // minWidth : dans une bulle de discussion (largeur au contenu), l'éditeur doit rester utilisable.
  editor: { gap: 6, minWidth: 240 },
  input: {
    backgroundColor: COLORS.surface,
    color: COLORS.text,
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
    paddingHorizontal: 10,
    paddingVertical: 8,
    fontSize: 14,
    minHeight: 70,
    textAlignVertical: 'top',
  },
  error: { color: COLORS.error, fontSize: 12 },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: SPACING.sm },
  btn: { paddingHorizontal: 12, paddingVertical: 7, borderRadius: RADIUS.sm, alignItems: 'center', justifyContent: 'center', minWidth: 84 },
  btnPrimary: { backgroundColor: COLORS.primary },
  btnPrimaryLabel: { color: '#fff', fontSize: 13, fontWeight: '700' },
  btnGhost: { backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border },
  btnGhostLabel: { color: COLORS.text, fontSize: 13, fontWeight: '600' },
  // Sur fond coloré (bulle « moi ») : bouton plein blanc, bouton secondaire à contour blanc.
  btnPrimaryOnDark: { backgroundColor: '#fff' },
  btnPrimaryOnDarkLabel: { color: COLORS.primary, fontSize: 13, fontWeight: '700' },
  btnGhostOnDark: { backgroundColor: 'transparent', borderWidth: 1, borderColor: 'rgba(255,255,255,0.7)' },
  btnGhostOnDarkLabel: { color: '#fff', fontSize: 13, fontWeight: '600' },
});
