import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View, type StyleProp, type TextStyle } from 'react-native';

import { ApiError } from '@/api/client';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Texte d'un message que son auteur peut corriger : affichage normal avec la mention « modifié » si le texte a été
 * changé, un lien « Modifier » (si `canEdit`), et un éditeur en ligne (Enregistrer / Annuler) à l'appui. `onSave`
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
}: {
  text: string;
  /** Date de la dernière modification (ISO) ; null / absent = jamais modifié. */
  editedAt?: string | null;
  canEdit: boolean;
  onSave: (next: string) => Promise<void>;
  maxLength: number;
  textStyle?: StyleProp<TextStyle>;
  tone?: 'default' | 'onDark';
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(text);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const onDark = tone === 'onDark';
  const metaColor = onDark ? 'rgba(255,255,255,0.8)' : COLORS.textMuted;

  function startEditing() {
    setDraft(text);
    setError(null);
    setEditing(true);
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
              accessibilityLabel="Modifier ce message"
              hitSlop={8}
              style={styles.editLink}
            >
              <Ionicons name="create-outline" size={13} color={metaColor} />
              <Text style={[styles.metaText, { color: metaColor }]}>Modifier</Text>
            </Pressable>
          )}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  meta: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 3 },
  metaText: { fontSize: 11, fontStyle: 'italic' },
  editLink: { flexDirection: 'row', alignItems: 'center', gap: 3 },
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
