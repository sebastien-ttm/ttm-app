import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Linking,
  Platform,
  Pressable,
  ScrollView,
  type StyleProp,
  StyleSheet,
  Text,
  TextInput,
  type TextStyle,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { TrainingGroup, TrainingGroupComment, TrainingGroupStaffCandidate } from '@/api/types';
import { AttachmentLink } from '@/components/AttachmentLink';
import { LiveStatus } from '@/components/LiveStatus';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { ContactButtons } from '@/components/groups/ContactButtons';
import { CapacityStepper, PaceBadge, PaceLevelPicker } from '@/components/groups/GroupFields';
import { MemberPicker } from '@/components/groups/MemberPicker';
import { PositionCard } from '@/components/groups/PositionCard';
import { YouthDocsButton } from '@/components/youth/YouthDocsButton';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useLiveSync } from '@/hooks/useLiveSync';
import { type GeoPoint, formatPoint, getCurrentPoint, mapUrl } from '@/lib/geo';
import { canPickFile, pickFile } from '@/lib/pickFile';
import { startSharing, stopSharing, useSharing } from '@/lib/positionSharing';
import { confirmAction } from '@/utils/confirm';
import { formatRelativeFr } from '@/utils/html';

function notify(message: string): void {
  if (Platform.OS === 'web') window.alert(message);
  else Alert.alert('Groupe', message);
}

function errorText(e: unknown, fallback = 'Erreur inattendue. Réessayez.'): string {
  return e instanceof ApiError ? e.message : fallback;
}

function whenLabel(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

function Section({ title, right, children }: { title: string; right?: ReactNode; children: ReactNode }) {
  return (
    <View style={styles.section}>
      <View style={styles.sectionHead}>
        <Text style={styles.sectionTitle}>{title}</Text>
        {right}
      </View>
      {children}
    </View>
  );
}

/** Texte modifiable par un encadrant : affichage, « Modifier », éditeur en ligne (peut être vidé). */
function InlineEdit({ value, placeholder, empty, multiline, maxLength, canEdit, onSave, textStyle }: {
  value: string | null;
  placeholder: string;
  empty: string;
  multiline?: boolean;
  maxLength: number;
  canEdit: boolean;
  onSave: (next: string) => Promise<void>;
  textStyle?: StyleProp<TextStyle>;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    try {
      await onSave(draft.trim());
      setEditing(false);
    } catch (e) {
      notify(errorText(e, 'Enregistrement impossible.'));
    } finally {
      setBusy(false);
    }
  }

  if (editing) {
    return (
      <View style={{ gap: 8 }}>
        <TextInput
          value={draft}
          onChangeText={setDraft}
          placeholder={placeholder}
          placeholderTextColor={COLORS.textSubtle}
          multiline={multiline}
          maxLength={maxLength}
          autoFocus
          editable={!busy}
          style={[styles.input, multiline && styles.textarea]}
        />
        <View style={styles.actions}>
          <Pressable onPress={() => setEditing(false)} disabled={busy} style={[styles.smallBtn, styles.ghost]}>
            <Text style={styles.ghostLabel}>Annuler</Text>
          </Pressable>
          <Pressable onPress={() => void save()} disabled={busy} style={[styles.smallBtn, styles.primary, busy && { opacity: 0.6 }]}>
            {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.primaryLabel}>Enregistrer</Text>}
          </Pressable>
        </View>
      </View>
    );
  }

  return (
    <View style={{ gap: 6 }}>
      <Text style={[value ? styles.body : styles.muted, textStyle]}>{value || empty}</Text>
      {canEdit && (
        <Pressable
          onPress={() => { setDraft(value ?? ''); setEditing(true); }}
          style={({ pressed }) => [styles.linkBtn, pressed && { opacity: 0.6 }]}
          accessibilityRole="button"
        >
          <Ionicons name="create-outline" size={15} color={COLORS.secondaryDark} />
          <Text style={styles.linkLabel}>Modifier</Text>
        </Pressable>
      )}
    </View>
  );
}

/**
 * Allure du club (1 à 5), vitesse et nombre de places : modifiables par un encadrant du groupe, enregistrés tout de
 * suite (le nombre de places ne peut pas descendre sous le nombre d'adhérents déjà dans le groupe).
 */
function PaceAndPlaces({ group, onChange }: { group: TrainingGroup; onChange: (g: TrainingGroup) => void }) {
  const [busy, setBusy] = useState(false);

  async function save(input: { capacity?: number; paceLevel?: number | null; speed?: string }) {
    if (busy) return;
    setBusy(true);
    try {
      onChange(await trainingGroups.update(group.id, input));
    } catch (e) {
      notify(errorText(e, 'Enregistrement impossible.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={{ gap: 14 }}>
      <View style={{ gap: 6 }}>
        <Text style={styles.fieldLabel}>Allure du groupe</Text>
        <PaceLevelPicker value={group.paceLevel} onChange={(level) => void save({ paceLevel: level })} disabled={busy} />
      </View>
      <View style={{ gap: 6 }}>
        <Text style={styles.fieldLabel}>Vitesse</Text>
        <InlineEdit
          value={group.speed}
          placeholder={group.sport === 'course' ? "Ex : 5'30 /km" : 'Ex : 28 km/h'}
          empty="Non précisée."
          maxLength={60}
          canEdit
          onSave={async (next) => { onChange(await trainingGroups.update(group.id, { speed: next })); }}
        />
      </View>
      <View style={{ gap: 6 }}>
        <Text style={styles.fieldLabel}>Nombre maximal d'adhérents</Text>
        <CapacityStepper
          value={group.capacity ?? Math.max(group.members.length, 1)}
          min={Math.max(group.members.length, 1)}
          onChange={(capacity) => void save({ capacity })}
          disabled={busy}
        />
      </View>
    </View>
  );
}

/** Encadrants du groupe : partage avec d'autres encadrants (un seul groupe par encadrant et par séance). */
function CoachManager({ group, onChange }: { group: TrainingGroup; onChange: (g: TrainingGroup) => void }) {
  const [open, setOpen] = useState(false);
  const [candidates, setCandidates] = useState<TrainingGroupStaffCandidate[] | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState(false);

  async function show() {
    setSelected(new Set(group.coaches.filter((c) => !c.isMe).map((c) => c.id)));
    setOpen(true);
    if (candidates === null) {
      try {
        setCandidates((await trainingGroups.staffCandidates(group.occ)).data);
      } catch (e) {
        setOpen(false);
        notify(errorText(e, 'Liste des encadrants indisponible.'));
      }
    }
  }

  async function save() {
    setBusy(true);
    try {
      onChange(await trainingGroups.setCoaches(group.id, [...selected]));
      setOpen(false);
    } catch (e) {
      notify(errorText(e, 'Enregistrement impossible.'));
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <Pressable onPress={() => void show()} style={({ pressed }) => [styles.addBtn, pressed && { opacity: 0.8 }]}>
        <Ionicons name="people" size={18} color={COLORS.primary} />
        <Text style={styles.addBtnLabel}>Partager avec d'autres encadrants</Text>
      </Pressable>
    );
  }

  return (
    <View style={{ gap: 6 }}>
      {candidates === null ? <ActivityIndicator color={COLORS.primary} /> : candidates.map((c) => {
        const elsewhere = c.activeGroupId !== null && c.activeGroupId !== group.id;
        const on = selected.has(c.id);
        return (
          <Pressable
            key={c.id}
            disabled={elsewhere}
            onPress={() => setSelected((s) => { const n = new Set(s); if (n.has(c.id)) n.delete(c.id); else n.add(c.id); return n; })}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: on, disabled: elsewhere }}
            style={[styles.personRow, on && styles.personRowOn, elsewhere && { opacity: 0.5 }]}
          >
            <MemberAvatar prenom={c.prenom} nom={c.nom} avatarUrl={c.avatarUrl} size={34} />
            <View style={{ flex: 1 }}>
              <Text style={styles.personName} numberOfLines={1}><Text style={styles.nom}>{c.nom.toUpperCase()}</Text> {c.prenom}</Text>
              {elsewhere && <Text style={styles.hint}>Gère déjà un autre groupe actif</Text>}
            </View>
            <Ionicons name={on ? 'checkbox' : 'square-outline'} size={22} color={on ? COLORS.primary : COLORS.textMuted} />
          </Pressable>
        );
      })}
      <View style={styles.actions}>
        <Pressable onPress={() => setOpen(false)} disabled={busy} style={[styles.smallBtn, styles.ghost]}>
          <Text style={styles.ghostLabel}>Annuler</Text>
        </Pressable>
        <Pressable onPress={() => void save()} disabled={busy || candidates === null} style={[styles.smallBtn, styles.primary, busy && { opacity: 0.6 }]}>
          {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.primaryLabel}>Valider</Text>}
        </Pressable>
      </View>
    </View>
  );
}

/** Saisie d'un commentaire d'encadrant : texte et / ou point GPS (position de l'appareil à l'instant). */
function CommentComposer({ groupId, onPosted }: { groupId: number; onPosted: () => void }) {
  const [text, setText] = useState('');
  const [point, setPoint] = useState<GeoPoint | null>(null);
  const [locating, setLocating] = useState(false);
  const [busy, setBusy] = useState(false);

  async function attachPoint() {
    setLocating(true);
    try {
      setPoint(await getCurrentPoint());
    } catch (e) {
      notify(e instanceof Error ? e.message : 'Localisation impossible.');
    } finally {
      setLocating(false);
    }
  }

  async function send() {
    if (busy || (text.trim() === '' && point === null)) return;
    setBusy(true);
    try {
      await trainingGroups.addComment(groupId, {
        content: text.trim() || undefined,
        ...(point ? { latitude: point.latitude, longitude: point.longitude } : {}),
      });
      setText('');
      setPoint(null);
      onPosted();
    } catch (e) {
      notify(errorText(e, 'Envoi impossible.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={{ gap: 8 }}>
      <TextInput
        value={text}
        onChangeText={setText}
        placeholder="Un commentaire pour le groupe…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={1000}
        style={[styles.input, styles.textareaSmall]}
        editable={!busy}
      />
      {point && (
        <View style={styles.pointChip}>
          <Ionicons name="location" size={16} color={COLORS.primary} />
          <Text style={styles.pointText} numberOfLines={1}>
            Point GPS joint · {formatPoint(point.latitude, point.longitude)}{point.accuracy !== null ? ` (±${Math.round(point.accuracy)} m)` : ''}
          </Text>
          <Pressable onPress={() => setPoint(null)} hitSlop={10} accessibilityLabel="Retirer le point GPS">
            <Ionicons name="close-circle" size={18} color={COLORS.textSubtle} />
          </Pressable>
        </View>
      )}
      <View style={styles.actions}>
        <Pressable
          onPress={() => void attachPoint()}
          disabled={locating || busy}
          style={({ pressed }) => [styles.smallBtn, styles.ghost, { flexDirection: 'row', gap: 6 }, pressed && { opacity: 0.7 }]}
          accessibilityRole="button"
        >
          {locating ? <ActivityIndicator size="small" color={COLORS.primary} /> : <Ionicons name="location-outline" size={16} color={COLORS.primary} />}
          <Text style={styles.ghostLabel}>{point ? 'Actualiser le point' : 'Joindre ma position'}</Text>
        </Pressable>
        <Pressable
          onPress={() => void send()}
          disabled={busy || (text.trim() === '' && point === null)}
          style={[styles.smallBtn, styles.primary, (busy || (text.trim() === '' && point === null)) && { opacity: 0.45 }]}
        >
          {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.primaryLabel}>Publier</Text>}
        </Pressable>
      </View>
    </View>
  );
}

function CommentItem({ comment, groupId, onDeleted }: { comment: TrainingGroupComment; groupId: number; onDeleted: () => void }) {
  async function remove() {
    if (!(await confirmAction('Supprimer ce commentaire ?', 'Il disparaîtra pour tout le groupe.', 'Supprimer'))) return;
    try {
      await trainingGroups.deleteComment(groupId, comment.id);
      onDeleted();
    } catch (e) {
      notify(errorText(e, 'Suppression impossible.'));
    }
  }

  return (
    <View style={styles.comment}>
      <View style={styles.commentHead}>
        <Text style={styles.commentAuthor} numberOfLines={1}>{comment.authorName}</Text>
        <Text style={styles.commentTime}>{formatRelativeFr(comment.createdAt)}</Text>
        {comment.canDelete && (
          <Pressable onPress={() => void remove()} hitSlop={10} accessibilityRole="button" accessibilityLabel="Supprimer ce commentaire">
            <Ionicons name="trash-outline" size={16} color={COLORS.error} />
          </Pressable>
        )}
      </View>
      {comment.content && <Text style={styles.body}>{comment.content}</Text>}
      {comment.latitude !== null && comment.longitude !== null && (
        <Pressable
          onPress={() => void Linking.openURL(mapUrl(comment.latitude as number, comment.longitude as number))}
          accessibilityRole="link"
          style={({ pressed }) => [styles.pointLink, pressed && { opacity: 0.7 }]}
        >
          <Ionicons name="location" size={16} color={COLORS.primary} />
          <Text style={styles.pointLinkLabel}>
            Point GPS · {formatPoint(comment.latitude, comment.longitude)} · Ouvrir la carte
          </Text>
        </Pressable>
      )}
    </View>
  );
}

/**
 * Un groupe d'entraînement. Les encadrants du groupe le gèrent (nom, informations, adhérents, encadrants, trace GPX,
 * commentaires, position en direct, fin du groupe) ; les adhérents le consultent (informations, encadrants, autres
 * membres, trace GPX, commentaires, position des encadrants). L'écran se met à jour tout seul toutes les quelques
 * secondes. `onGone` : appelé quand le groupe n'existe plus (terminé, expiré ou quitté).
 */
export function GroupView({ groupId, onGone }: { groupId: number; onGone?: () => void }) {
  const [group, setGroup] = useState<TrainingGroup | null>(null);
  const router = useRouter();
  const [gone, setGone] = useState(false);
  const [left, setLeft] = useState(false);
  const [picker, setPicker] = useState(false);
  const [uploading, setUploading] = useState(false);
  const { sharing, error: shareError } = useSharing(groupId);

  const { online, resync } = useLiveSync<TrainingGroup>({
    enabled: !gone,
    intervalMs: 5000,
    fetchState: async (version) => {
      try {
        return await trainingGroups.get(groupId, version);
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) setGone(true);
        throw e;
      }
    },
    onState: (state) => setGroup(state),
  });

  // Recharge l'état complet après une action locale.
  const reload = useCallback(async () => {
    resync();
    try {
      const state = await trainingGroups.get(groupId, null);
      if (!state.unchanged) setGroup(state);
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setGone(true);
    }
  }, [groupId, resync]);

  // Groupe terminé : plus de partage de position.
  useEffect(() => {
    if (gone) void stopSharing(false);
  }, [gone]);

  if (left) {
    return (
      <View style={styles.center}>
        <Text style={styles.endedTitle}>Vous avez quitté ce groupe</Text>
        <Text style={styles.muted}>Vous pouvez en choisir un autre depuis le créneau d'entraînement, tant que la séance n'a pas commencé.</Text>
        {onGone && (
          <Pressable onPress={onGone} style={[styles.smallBtn, styles.primary, { marginTop: SPACING.md }]}>
            <Text style={styles.primaryLabel}>Retour</Text>
          </Pressable>
        )}
      </View>
    );
  }
  if (gone) {
    return (
      <View style={styles.center}>
        <Text style={styles.endedTitle}>Ce groupe est terminé</Text>
        <Text style={styles.muted}>Un groupe se termine au prochain créneau d'entraînement, ou quand un encadrant le termine.</Text>
        {onGone && (
          <Pressable onPress={onGone} style={[styles.smallBtn, styles.primary, { marginTop: SPACING.md }]}>
            <Text style={styles.primaryLabel}>Retour</Text>
          </Pressable>
        )}
      </View>
    );
  }
  if (group === null) {
    return online ? <FullScreenLoading /> : <ErrorState message="Impossible de charger le groupe : vérifiez votre connexion." onRetry={() => void reload()} />;
  }

  const isCoach = group.isCoach;

  async function addGpx() {
    if (!canPickFile) {
      notify('L\'ajout d\'une trace GPX se fait depuis l\'appli web (navigateur du téléphone).');
      return;
    }
    const file = await pickFile('.gpx,application/gpx+xml');
    if (file === null) return;
    if (!/\.gpx$/i.test(file.name)) {
      notify('Choisissez un fichier au format .gpx.');
      return;
    }
    setUploading(true);
    try {
      await trainingGroups.addAttachment(groupId, file, file.name);
      await reload();
    } catch (e) {
      notify(errorText(e, 'Envoi de la trace impossible.'));
    } finally {
      setUploading(false);
    }
  }

  async function removeGpx(id: number, name: string) {
    if (!(await confirmAction('Supprimer cette trace ?', `« ${name} » ne sera plus téléchargeable.`, 'Supprimer'))) return;
    try {
      await trainingGroups.deleteAttachment(groupId, id);
      await reload();
    } catch (e) {
      notify(errorText(e, 'Suppression impossible.'));
    }
  }

  async function removeMember(id: number, label: string) {
    if (!(await confirmAction('Retirer du groupe ?', `${label} ne verra plus ce groupe.`, 'Retirer'))) return;
    try {
      setGroup(await trainingGroups.setMembers(groupId, (group as TrainingGroup).members.filter((m) => m.id !== id).map((m) => m.id)));
    } catch (e) {
      notify(errorText(e, 'Modification impossible.'));
    }
  }

  async function leaveAsMember() {
    if (!(await confirmAction('Quitter ce groupe ?', 'Votre place sera libérée ; vous pourrez en choisir un autre depuis le créneau tant que la séance n\'a pas commencé.', 'Quitter'))) return;
    try {
      await trainingGroups.leaveAsMember(groupId);
      setLeft(true);
    } catch (e) {
      notify(errorText(e, 'Impossible de quitter le groupe.'));
    }
  }

  function openSlot() {
    const slot = (group as TrainingGroup).slot;
    if (!slot) return;
    router.push({
      pathname: '/training-slot',
      params: { date: slot.date, time: slot.time, ...(slot.templateId !== null ? { templateId: String(slot.templateId) } : { slotId: String(slot.slotId) }) },
    } as never);
  }

  async function endGroup() {
    if (!(await confirmAction('Terminer le groupe ?', 'Il disparaîtra pour tous ses membres.', 'Terminer'))) return;
    try {
      await stopSharing(true);
      await trainingGroups.close(groupId);
      setGone(true);
      onGone?.();
    } catch (e) {
      notify(errorText(e, 'Impossible de terminer le groupe.'));
    }
  }

  async function leaveGroup() {
    if (!(await confirmAction('Quitter ce groupe ?', 'Les autres encadrants le gardent ; s\'il n\'y en a pas, il se termine.', 'Quitter'))) return;
    try {
      await stopSharing(true);
      await trainingGroups.leave(groupId);
      setGone(true);
      onGone?.();
    } catch (e) {
      notify(errorText(e, 'Impossible de quitter le groupe.'));
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.content}>
      <View style={styles.header}>
        <View style={{ flex: 1, gap: 4 }}>
          {isCoach ? (
            <InlineEdit
              value={group.rawName}
              placeholder="Nom du groupe"
              empty={group.name}
              maxLength={80}
              canEdit
              textStyle={styles.groupName}
              onSave={async (next) => { setGroup(await trainingGroups.update(groupId, { name: next })); }}
            />
          ) : (
            <Text style={styles.groupName}>{group.name}</Text>
          )}
          {group.session && (
            <Text style={styles.sub}>
              {group.sportIcon ? `${group.sportIcon} ` : ''}Séance : {group.session.title || 'entraînement'}{group.session.startsAt ? ` · ${whenLabel(group.session.startsAt)}` : ''}
            </Text>
          )}
          <PaceBadge level={group.paceLevel} speed={group.speed} />
          <Text style={styles.sub}>
            {group.members.length}{group.capacity !== null ? `/${group.capacity}` : ''} adhérent{group.members.length > 1 ? 's' : ''}
            {group.placesLeft !== null ? ` · ${group.placesLeft === 0 ? 'complet' : `${group.placesLeft} place${group.placesLeft > 1 ? 's' : ''} libre${group.placesLeft > 1 ? 's' : ''}`}` : ''}
          </Text>
          <Text style={styles.sub}>Actif jusqu'au {whenLabel(group.expiresAt)}</Text>
          {group.slot && (
            <Pressable onPress={openSlot} accessibilityRole="link" style={({ pressed }) => [styles.linkBtn, pressed && { opacity: 0.6 }]}>
              <Ionicons name="calendar-outline" size={15} color={COLORS.secondaryDark} />
              <Text style={styles.linkLabel}>Voir le créneau</Text>
            </Pressable>
          )}
        </View>
        <LiveStatus online={online} />
      </View>

      {(group.positions.length > 0 || isCoach) && (
        <Section title="📍 Position en direct">
          {group.positions.map((p) => <PositionCard key={p.userId} position={p} />)}
          {group.positions.length === 0 && <Text style={styles.muted}>Aucun encadrant ne partage sa position pour le moment.</Text>}
          {isCoach && (
            <>
              <Pressable
                onPress={() => (sharing ? void stopSharing(true) : startSharing(groupId))}
                accessibilityRole="button"
                style={({ pressed }) => [styles.shareBtn, sharing && styles.shareBtnOn, pressed && { opacity: 0.85 }]}
              >
                <Ionicons name={sharing ? 'stop-circle' : 'navigate-circle'} size={20} color={sharing ? '#fff' : COLORS.primary} />
                <Text style={[styles.shareLabel, sharing && { color: '#fff' }]}>
                  {sharing ? 'Arrêter le partage de ma position' : 'Partager ma position en direct'}
                </Text>
              </Pressable>
              {sharing && !shareError && (
                <Text style={styles.hint}>Votre position est mise à jour pour le groupe toutes les 15 secondes. Gardez l'appli ouverte.</Text>
              )}
              {shareError && <Text style={styles.errorInline}>{shareError}</Text>}
            </>
          )}
        </Section>
      )}

      {isCoach && (
        <Section title="🏁 Allure et places">
          <PaceAndPlaces group={group} onChange={setGroup} />
        </Section>
      )}

      <Section title="ℹ️ Informations">
        <InlineEdit
          value={group.info}
          placeholder="Rendez-vous, programme de la séance, matériel…"
          empty="Aucune information pour le moment."
          multiline
          maxLength={3000}
          canEdit={isCoach}
          onSave={async (next) => { setGroup(await trainingGroups.update(groupId, { info: next })); }}
        />
      </Section>

      <Section title="🗺️ Trace GPX">
        {group.attachments.length === 0 && <Text style={styles.muted}>Aucune trace pour le moment.</Text>}
        {group.attachments.map((a) => (
          <View key={a.id} style={styles.attachmentRow}>
            <View style={{ flex: 1 }}><AttachmentLink attachment={a} /></View>
            {isCoach && (
              <Pressable onPress={() => void removeGpx(a.id, a.name)} hitSlop={10} accessibilityRole="button" accessibilityLabel={`Supprimer ${a.name}`}>
                <Ionicons name="trash-outline" size={20} color={COLORS.error} />
              </Pressable>
            )}
          </View>
        ))}
        {isCoach && (
          <Pressable
            onPress={() => void addGpx()}
            disabled={uploading}
            style={({ pressed }) => [styles.addBtn, (pressed || uploading) && { opacity: 0.7 }]}
          >
            {uploading ? <ActivityIndicator size="small" color={COLORS.primary} /> : <Ionicons name="cloud-upload" size={18} color={COLORS.primary} />}
            <Text style={styles.addBtnLabel}>{uploading ? 'Envoi en cours…' : 'Ajouter une trace GPX'}</Text>
          </Pressable>
        )}
      </Section>

      <Section title="💬 Commentaires">
        {isCoach && <CommentComposer groupId={groupId} onPosted={() => void reload()} />}
        {group.comments.length === 0 && <Text style={styles.muted}>Aucun commentaire pour le moment.</Text>}
        {group.comments.map((c) => <CommentItem key={c.id} comment={c} groupId={groupId} onDeleted={() => void reload()} />)}
      </Section>

      <Section title={`🧢 Encadrants (${group.coaches.length})`}>
        {group.coaches.map((c) => (
          <View key={c.id} style={styles.personRow}>
            <MemberAvatar prenom={c.prenom} nom={c.nom} avatarUrl={c.avatarUrl} size={40} />
            <Text style={styles.personName} numberOfLines={1}>
              {c.fullName}{c.isMe ? ' (vous)' : ''}
            </Text>
            {!c.isMe && <ContactButtons name={c.fullName} telephone={c.telephone} />}
          </View>
        ))}
        {isCoach && <CoachManager group={group} onChange={setGroup} />}
      </Section>

      <Section title={`👥 Adhérents (${group.members.length})`}>
        {group.members.length === 0 && <Text style={styles.muted}>Aucun adhérent dans ce groupe pour le moment.</Text>}
        {group.members.map((m) => {
          const label = `${m.prenom} ${m.nom}`;
          return (
            // Deux niveaux pour l'encadrant : le nom a toute la largeur (sur 2 lignes si besoin), les boutons d'action
            // passent dessous. Pas de numéro affiché : les boutons appel / WhatsApp / urgence suffisent.
            <View key={m.id} style={styles.memberRow}>
              <MemberAvatar prenom={m.prenom} nom={m.nom} avatarUrl={m.avatarUrl} size={40} />
              <View style={styles.memberBody}>
                <View style={styles.memberNameBox}>
                  <Text style={styles.memberName} numberOfLines={2}>
                    <Text style={styles.nom}>{m.nom.toUpperCase()}</Text> {m.prenom}
                  </Text>
                </View>
                {isCoach && m.telephoneOf ? <Text style={styles.hint}>Joignable via {m.telephoneOf} (parent)</Text> : null}
                {isCoach && !m.telephone ? <Text style={styles.hint}>Numéro non renseigné</Text> : null}
                {isCoach && (
                  <View style={styles.memberActions}>
                    {m.isJeune && <YouthDocsButton youthId={m.id} name={label} />}
                    <ContactButtons
                      name={label}
                      telephone={m.telephone}
                      telephoneOf={m.telephoneOf}
                      telephoneUrgence={m.telephoneUrgence}
                    />
                  </View>
                )}
              </View>
              {isCoach && (
                <View style={styles.memberRemoveBox}>
                  <Pressable onPress={() => void removeMember(m.id, label)} hitSlop={10} accessibilityRole="button" accessibilityLabel={`Retirer ${label}`}>
                    <Ionicons name="close-circle" size={22} color={COLORS.textSubtle} />
                  </Pressable>
                </View>
              )}
            </View>
          );
        })}
        {!isCoach && group.isMember && !group.started && (
          <Pressable onPress={() => void leaveAsMember()} accessibilityRole="button" style={({ pressed }) => [styles.dangerBtn, pressed && { opacity: 0.7 }]}>
            <Text style={styles.dangerLabel}>Quitter ce groupe</Text>
          </Pressable>
        )}
        {isCoach && (
          <Pressable onPress={() => setPicker(true)} style={({ pressed }) => [styles.addBtn, pressed && { opacity: 0.8 }]}>
            <Ionicons name="person-add" size={18} color={COLORS.primary} />
            <Text style={styles.addBtnLabel}>Ajouter ou retirer des adhérents</Text>
          </Pressable>
        )}
      </Section>

      {isCoach && (
        <View style={styles.dangerZone}>
          <Pressable onPress={() => void leaveGroup()} style={({ pressed }) => [styles.dangerBtn, pressed && { opacity: 0.7 }]}>
            <Text style={styles.dangerLabel}>Quitter le groupe</Text>
          </Pressable>
          <Pressable onPress={() => void endGroup()} style={({ pressed }) => [styles.dangerBtn, styles.dangerBtnStrong, pressed && { opacity: 0.7 }]}>
            <Text style={[styles.dangerLabel, { color: '#fff' }]}>Terminer le groupe</Text>
          </Pressable>
        </View>
      )}

      {isCoach && (
        <MemberPicker
          visible={picker}
          selectedIds={group.members.map((m) => m.id)}
          excludeIds={group.coaches.map((c) => c.id)}
          title="Adhérents du groupe"
          onConfirm={async (ids) => {
            setPicker(false);
            try {
              setGroup(await trainingGroups.setMembers(groupId, ids));
            } catch (e) {
              notify(errorText(e, 'Modification impossible.'));
            }
          }}
          onClose={() => setPicker(false)}
        />
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, gap: SPACING.md, maxWidth: 640, width: '100%', alignSelf: 'center' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: SPACING.xl, gap: 8 },
  endedTitle: { fontSize: 18, fontWeight: '800', color: COLORS.text },
  header: {
    flexDirection: 'row', alignItems: 'flex-start', gap: 10, backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, padding: SPACING.md,
  },
  groupName: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  sub: { fontSize: 13, color: COLORS.textMuted },
  section: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    padding: SPACING.md, gap: 10,
  },
  sectionHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  sectionTitle: { fontSize: 14, fontWeight: '800', color: COLORS.text },
  body: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  muted: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic' },
  hint: { fontSize: 12, color: COLORS.textMuted },
  errorInline: { fontSize: 12, color: COLORS.error, fontWeight: '600' },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 12, paddingVertical: 10,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 100, textAlignVertical: 'top' },
  textareaSmall: { minHeight: 64, textAlignVertical: 'top' },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8 },
  smallBtn: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: RADIUS.sm, alignItems: 'center', justifyContent: 'center' },
  primary: { backgroundColor: COLORS.primary },
  primaryLabel: { color: '#fff', fontSize: 13, fontWeight: '700' },
  ghost: { backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border },
  ghostLabel: { color: COLORS.text, fontSize: 13, fontWeight: '600' },
  linkBtn: { flexDirection: 'row', alignItems: 'center', gap: 4, alignSelf: 'flex-start' },
  linkLabel: { fontSize: 13, fontWeight: '700', color: COLORS.secondaryDark },
  addBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 10,
    borderRadius: RADIUS.md, borderWidth: 1.5, borderColor: COLORS.primary, borderStyle: 'dashed',
  },
  addBtnLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  shareBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 11,
    borderRadius: RADIUS.md, borderWidth: 1.5, borderColor: COLORS.primary, backgroundColor: COLORS.surface,
  },
  shareBtnOn: { backgroundColor: COLORS.primary },
  shareLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  attachmentRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  personRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 6,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surfaceAlt,
  },
  personRowOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  personName: { flex: 1, fontSize: 14, color: COLORS.text },
  nom: { fontWeight: '700' },
  fieldLabel: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  // Adhérent du groupe : avatar à gauche, nom + boutons au centre (en colonne), retrait à droite.
  memberRow: {
    flexDirection: 'row', alignItems: 'flex-start', gap: 10, padding: 8,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surfaceAlt,
  },
  memberBody: { flex: 1, minWidth: 0, gap: 6 },
  // Hauteur de l'avatar : le nom reste centré dessus, les boutons s'alignent dessous.
  memberNameBox: { minHeight: 40, justifyContent: 'center' },
  memberName: { fontSize: 15, color: COLORS.text },
  memberActions: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 8 },
  memberRemoveBox: { minHeight: 40, justifyContent: 'center' },
  comment: { gap: 4, padding: 10, borderRadius: RADIUS.md, backgroundColor: COLORS.surfaceAlt, borderWidth: 1, borderColor: COLORS.border },
  commentHead: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  commentAuthor: { flex: 1, fontSize: 13, fontWeight: '700', color: COLORS.text },
  commentTime: { fontSize: 11, color: COLORS.textMuted },
  pointLink: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  pointLinkLabel: { flex: 1, fontSize: 13, fontWeight: '600', color: COLORS.secondaryDark },
  pointChip: {
    flexDirection: 'row', alignItems: 'center', gap: 6, padding: 8,
    backgroundColor: COLORS.primarySoft, borderRadius: RADIUS.sm,
  },
  pointText: { flex: 1, fontSize: 12, color: COLORS.text },
  dangerZone: { flexDirection: 'row', gap: 10, justifyContent: 'center', flexWrap: 'wrap', marginTop: SPACING.sm },
  dangerBtn: { paddingHorizontal: 16, paddingVertical: 11, borderRadius: RADIUS.md, borderWidth: 1.5, borderColor: COLORS.error },
  dangerBtnStrong: { backgroundColor: COLORS.error },
  dangerLabel: { fontSize: 14, fontWeight: '700', color: COLORS.error },
});
