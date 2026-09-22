import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import type { Comment } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatRelativeFr } from '@/utils/html';

/**
 * Thread de commentaires — reconstitue l'arbre à partir de la liste
 * plate (chaque commentaire porte parentId=null pour un top-level, ou
 * l'id de son parent pour une réponse). Chaque commentaire propose :
 *  - un bouton « Répondre », ouvert à tout le monde, sans limite de
 *    profondeur ;
 *  - un bouton « Modifier », visible uniquement à son propre auteur
 *    (comparaison currentUserId === comment.user.id), qui bascule le
 *    corps du commentaire en formulaire d'édition inline.
 * La hiérarchie est marquée par l'indentation (padding-left cumulatif,
 * capé pour ne pas rogner le texte sur les threads profonds).
 */
type Props = {
  comments: Comment[];
  /** Id de l'user connecté — détermine qui voit le bouton « Modifier ». null = pas encore chargé (aucun bouton affiché). */
  currentUserId: number | null;
  /** Poste un commentaire (racine si parentId absent, réponse sinon). Doit renvoyer le commentaire créé. */
  onSubmit: (content: string, parentId?: number | null) => Promise<Comment>;
  /** Édite un commentaire existant. Doit renvoyer le commentaire mis à jour. */
  onEdit: (commentId: number, content: string) => Promise<Comment>;
};

type Node = {
  comment: Comment;
  children: Node[];
};

function buildTree(comments: Comment[]): Node[] {
  const byId = new Map<number, Node>();
  const roots: Node[] = [];
  // 1re passe : crée un nœud par commentaire.
  for (const c of comments) {
    byId.set(c.id, { comment: c, children: [] });
  }
  // 2e passe : rattache chaque nœud à son parent (ou aux racines).
  for (const c of comments) {
    const node = byId.get(c.id)!;
    if (c.parentId != null && byId.has(c.parentId)) {
      byId.get(c.parentId)!.children.push(node);
    } else {
      roots.push(node);
    }
  }
  // Tri par date au niveau des enfants (le backend renvoie déjà trié
  // globalement mais on garantit ici la stabilité par branche).
  const sortRec = (nodes: Node[]) => {
    nodes.sort((a, b) => a.comment.createdAt.localeCompare(b.comment.createdAt));
    for (const n of nodes) sortRec(n.children);
  };
  sortRec(roots);
  return roots;
}

export function CommentThread({ comments, currentUserId, onSubmit, onEdit }: Props) {
  const tree = useMemo(() => buildTree(comments), [comments]);
  if (tree.length === 0) {
    return <Text style={styles.empty}>Aucun commentaire pour le moment. Soyez le premier !</Text>;
  }
  return (
    <View style={{ gap: SPACING.sm }}>
      {tree.map((n) => (
        <CommentNode key={n.comment.id} node={n} depth={0} currentUserId={currentUserId} onSubmit={onSubmit} onEdit={onEdit} />
      ))}
    </View>
  );
}

const MAX_INDENT_DEPTH = 4;
const INDENT_STEP = 14;

function CommentNode({
  node,
  depth,
  currentUserId,
  onSubmit,
  onEdit,
}: {
  node: Node;
  depth: number;
  currentUserId: number | null;
  onSubmit: Props['onSubmit'];
  onEdit: Props['onEdit'];
}) {
  const [replying, setReplying] = useState(false);
  const [editing, setEditing] = useState(false);
  const effectiveDepth = Math.min(depth, MAX_INDENT_DEPTH);
  const isMine = currentUserId !== null && node.comment.user.id === currentUserId;

  return (
    <View style={{ marginLeft: effectiveDepth * INDENT_STEP }}>
      <View style={styles.comment}>
        <View style={styles.commentHeader}>
          <Text style={styles.commentAuthor}>{node.comment.user.fullName}</Text>
          <Text style={styles.commentTime}>
            {formatRelativeFr(node.comment.createdAt)}
            {node.comment.editedAt ? ' · modifié' : ''}
          </Text>
        </View>

        {editing ? (
          <InlineEditForm
            comment={node.comment}
            onEdit={onEdit}
            onDone={() => setEditing(false)}
          />
        ) : (
          <>
            <Text style={styles.commentBody}>{node.comment.content}</Text>
            <View style={styles.commentActions}>
              <Pressable onPress={() => setReplying((v) => !v)} hitSlop={8}>
                <Text style={styles.replyToggle}>{replying ? 'Annuler' : 'Répondre'}</Text>
              </Pressable>
              {isMine && (
                <Pressable onPress={() => setEditing(true)} hitSlop={8}>
                  <Text style={styles.editToggle}>Modifier</Text>
                </Pressable>
              )}
            </View>
          </>
        )}
      </View>

      {replying && (
        <View style={styles.replyForm}>
          <InlineReplyForm
            parentId={node.comment.id}
            onSubmit={onSubmit}
            onDone={() => setReplying(false)}
          />
        </View>
      )}

      {node.children.length > 0 && (
        <View style={{ marginTop: 8, gap: SPACING.sm }}>
          {node.children.map((child) => (
            <CommentNode
              key={child.comment.id}
              node={child}
              depth={depth + 1}
              currentUserId={currentUserId}
              onSubmit={onSubmit}
              onEdit={onEdit}
            />
          ))}
        </View>
      )}
    </View>
  );
}

function InlineReplyForm({
  parentId,
  onSubmit,
  onDone,
}: {
  parentId: number;
  onSubmit: Props['onSubmit'];
  onDone: () => void;
}) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    const trimmed = text.trim();
    if (trimmed === '' || busy) return;
    setBusy(true);
    setErr(null);
    try {
      await onSubmit(trimmed, parentId);
      setText('');
      onDone();
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Erreur');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View>
      <TextInput
        value={text}
        onChangeText={setText}
        placeholder="Votre réponse…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        style={styles.input}
        editable={!busy}
        maxLength={2000}
      />
      {err && <Text style={styles.err}>{err}</Text>}
      <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
        <Pressable
          onPress={submit}
          disabled={busy || text.trim() === ''}
          style={[styles.submitBtn, (busy || text.trim() === '') && styles.submitBtnDisabled]}
        >
          {busy ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.submitLabel}>Envoyer</Text>}
        </Pressable>
      </View>
    </View>
  );
}

function InlineEditForm({
  comment,
  onEdit,
  onDone,
}: {
  comment: Comment;
  onEdit: Props['onEdit'];
  onDone: () => void;
}) {
  const [text, setText] = useState(comment.content);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function submit() {
    const trimmed = text.trim();
    if (trimmed === '' || busy) return;
    // Pas d'appel réseau si le contenu n'a pas changé — évite un
    // aller-retour inutile et referme simplement le formulaire.
    if (trimmed === comment.content) {
      onDone();
      return;
    }
    setBusy(true);
    setErr(null);
    try {
      await onEdit(comment.id, trimmed);
      onDone();
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Erreur');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View>
      <TextInput
        value={text}
        onChangeText={setText}
        multiline
        style={styles.input}
        editable={!busy}
        maxLength={2000}
        autoFocus
      />
      {err && <Text style={styles.err}>{err}</Text>}
      <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
        <Pressable
          onPress={submit}
          disabled={busy || text.trim() === ''}
          style={[styles.submitBtn, (busy || text.trim() === '') && styles.submitBtnDisabled]}
        >
          {busy ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.submitLabel}>Enregistrer</Text>}
        </Pressable>
        <Pressable onPress={onDone} disabled={busy} hitSlop={8}>
          <Text style={styles.replyToggle}>Annuler</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  empty: {
    fontSize: 14, color: COLORS.textMuted, fontStyle: 'italic',
  },
  comment: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  commentHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'baseline',
    gap: 8,
  },
  commentAuthor: { fontSize: 13, fontWeight: '700', color: COLORS.text, flexShrink: 1 },
  commentTime: { fontSize: 11, color: COLORS.textMuted, flexShrink: 0 },
  commentBody: { fontSize: 14, color: COLORS.text, marginTop: 4, lineHeight: 20 },
  commentActions: { flexDirection: 'row', gap: 12, marginTop: 6 },
  replyToggle: { fontSize: 12, color: COLORS.secondary, fontWeight: '600' },
  editToggle: { fontSize: 12, color: COLORS.textMuted, fontWeight: '600' },
  replyForm: {
    marginTop: 6,
    marginLeft: 12,
    paddingLeft: 8,
    borderLeftWidth: 2,
    borderLeftColor: COLORS.border,
  },
  input: {
    minHeight: 60,
    padding: 8,
    borderWidth: 1,
    borderColor: COLORS.border,
    borderRadius: RADIUS.sm,
    fontSize: 14,
    color: COLORS.text,
    marginBottom: 6,
    textAlignVertical: 'top',
    backgroundColor: COLORS.surface,
  },
  err: { color: COLORS.error, fontSize: 12, marginBottom: 6 },
  submitBtn: {
    backgroundColor: COLORS.primary,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
  },
  submitBtnDisabled: { opacity: 0.5 },
  submitLabel: { color: '#fff', fontSize: 13, fontWeight: '700' },
});
