import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { type ReactNode, createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
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
import { CapacityStepper, PaceBadge, PaceLevelPicker, speedPlaceholder } from '@/components/groups/GroupFields';
import { COACH_COLOR, GroupMap } from '@/components/groups/GroupMap';
import { MemberPicker } from '@/components/groups/MemberPicker';
import { PositionCard } from '@/components/groups/PositionCard';
import { GoHomeAloneButton } from '@/components/youth/GoHomeAloneButton';
import { YouthDocsButton } from '@/components/youth/YouthDocsButton';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useLiveSync } from '@/hooks/useLiveSync';
import { formatPoint, getCurrentPoint, mapUrl } from '@/lib/geo';
import { canPickFile, pickFile } from '@/lib/pickFile';
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

/**
 * Brouillons en cours d'édition dans la vue : chaque éditeur ouvert s'y inscrit (une fonction qui l'enregistre et dit si
 * ça a réussi), pour que « Sauvegarder » enregistre ce qui n'a pas encore été validé avant de quitter l'écran.
 */
const DraftsContext = createContext<Set<() => Promise<boolean>> | null>(null);

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
  const drafts = useContext(DraftsContext);
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [busy, setBusy] = useState(false);

  /** Enregistre le brouillon (rien à envoyer s'il n'a pas changé) ; false si l'enregistrement a échoué. */
  async function save(): Promise<boolean> {
    if (draft.trim() === (value ?? '').trim()) {
      setEditing(false);
      return true;
    }
    setBusy(true);
    try {
      await onSave(draft.trim());
      setEditing(false);
      return true;
    } catch (e) {
      notify(errorText(e, 'Enregistrement impossible.'));
      return false;
    } finally {
      setBusy(false);
    }
  }

  // Tant que l'éditeur est ouvert, « Sauvegarder » (en bas de l'écran) l'enregistre aussi.
  const saveRef = useRef(save);
  saveRef.current = save;
  useEffect(() => {
    if (!editing || !drafts) return;
    const flush = () => saveRef.current();
    drafts.add(flush);
    return () => { drafts.delete(flush); };
  }, [editing, drafts]);

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
          placeholder={speedPlaceholder(group.sport)}
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
        <Text style={styles.addBtnLabel}>Ajouter d'autres encadrants</Text>
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

/** Saisie d'un commentaire (encadrant ou adhérent du groupe) : un texte pour le groupe. */
function CommentComposer({ groupId, onPosted }: { groupId: number; onPosted: () => void }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);

  async function send() {
    if (busy || text.trim() === '') return;
    setBusy(true);
    try {
      await trainingGroups.addComment(groupId, { content: text.trim() });
      setText('');
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
      <View style={styles.actions}>
        <Pressable
          onPress={() => void send()}
          disabled={busy || text.trim() === ''}
          style={[styles.smallBtn, styles.primary, (busy || text.trim() === '') && { opacity: 0.45 }]}
        >
          {busy ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.primaryLabel}>Publier</Text>}
        </Pressable>
      </View>
    </View>
  );
}

function CommentItem({ comment, groupId, byCoach, onDeleted }: {
  comment: TrainingGroupComment;
  groupId: number;
  /** L'auteur est un encadrant du groupe. */
  byCoach: boolean;
  onDeleted: () => void;
}) {
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
        <Text style={styles.commentAuthor} numberOfLines={1}>
          {comment.authorName}
          {byCoach ? <Text style={styles.commentCoach}>  Encadrant</Text> : null}
        </Text>
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
 * commentaires, position, suppression du groupe) ; les adhérents le consultent (informations, encadrants, autres
 * membres avec leurs boutons d'appel et WhatsApp, trace GPX) et y participent : commentaires, et partage de leur
 * position à leur demande, visible sur la carte des encadrants et des adhérents du groupe. L'écran se met à jour tout
 * seul toutes les quelques secondes. `onGone` : appelé quand le groupe n'existe plus (supprimé, expiré ou quitté).
 * `onDone` : si fourni, un bouton « Sauvegarder » (encadrant) enregistre les modifications en cours et l'appelle.
 */
export function GroupView({ groupId, onGone, onDone }: { groupId: number; onGone?: () => void; onDone?: () => void }) {
  const [group, setGroup] = useState<TrainingGroup | null>(null);
  const router = useRouter();
  const [gone, setGone] = useState(false);
  const [left, setLeft] = useState(false);
  const [picker, setPicker] = useState(false);
  const [uploading, setUploading] = useState(false);
  const drafts = useRef(new Set<() => Promise<boolean>>());
  const [savingAll, setSavingAll] = useState(false);
  // Partage de position à la demande (un appui sur la carte) : occupé / dernière erreur de localisation.
  const [sharingBusy, setSharingBusy] = useState(false);
  const [shareError, setShareError] = useState<string | null>(null);

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

  if (left) {
    return (
      <View style={styles.center}>
        <Text style={styles.endedTitle}>Vous avez quitté ce groupe</Text>
        <Text style={styles.muted}>Vous pouvez en choisir un autre depuis le créneau d'entraînement, tant que les groupes de la séance sont actifs.</Text>
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
        <Text style={styles.muted}>Un groupe disparaît 8 heures après le début de la séance, ou quand un encadrant le supprime.</Text>
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
  // Je participe au groupe (encadrant ou adhérent) : je commente et je partage ma position ; un encadrant d'un autre
  // groupe de la séance (lecture seule) ne fait que consulter.
  const isParticipant = isCoach || group.isMember;
  const myId = group.coaches.find((c) => c.isMe)?.id ?? group.members.find((m) => m.isMe)?.id;
  const coachIds = new Set(group.coaches.map((c) => c.id));

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
    if (!(await confirmAction('Quitter ce groupe ?', 'Votre place sera libérée et votre position partagée retirée ; vous pourrez en choisir un autre depuis le créneau.', 'Quitter'))) return;
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

  /** Envoie MA position, une fois : la carte du groupe se met à jour, et reste ainsi jusqu'à la prochaine demande. */
  async function shareMyPosition() {
    if (sharingBusy) return;
    setSharingBusy(true);
    setShareError(null);
    try {
      const p = await getCurrentPoint();
      await trainingGroups.setPosition(groupId, {
        latitude: p.latitude,
        longitude: p.longitude,
        ...(p.accuracy !== null ? { accuracy: Math.round(p.accuracy) } : {}),
      });
      await reload();
    } catch (e) {
      setShareError(e instanceof Error ? e.message : 'Partage de la position impossible.');
    } finally {
      setSharingBusy(false);
    }
  }

  async function removeMyPosition() {
    if (sharingBusy) return;
    setSharingBusy(true);
    setShareError(null);
    try {
      await trainingGroups.stopPosition(groupId);
      await reload();
    } catch (e) {
      setShareError(errorText(e, 'Impossible de retirer votre position.'));
    } finally {
      setSharingBusy(false);
    }
  }

  async function endGroup() {
    if (!(await confirmAction('Supprimer le groupe ?', 'Il disparaîtra pour tous ses membres.', 'Supprimer'))) return;
    try {
      await trainingGroups.close(groupId);
      setGone(true);
      onGone?.();
    } catch (e) {
      notify(errorText(e, 'Impossible de supprimer le groupe.'));
    }
  }

  /** Enregistre les éditeurs encore ouverts (nom, informations, vitesse), puis revient à l'écran précédent. */
  async function saveAndBack() {
    if (savingAll) return;
    setSavingAll(true);
    try {
      for (const flush of [...drafts.current]) {
        if (!(await flush())) return; // l'erreur est déjà affichée : on reste sur l'écran
      }
      onDone?.();
    } finally {
      setSavingAll(false);
    }
  }

  async function leaveGroup() {
    if (!(await confirmAction('Quitter ce groupe ?', 'Les autres encadrants le gardent ; s\'il n\'y en a pas, il se termine.', 'Quitter'))) return;
    try {
      await trainingGroups.leave(groupId);
      setGone(true);
      onGone?.();
    } catch (e) {
      notify(errorText(e, 'Impossible de quitter le groupe.'));
    }
  }

  return (
    <DraftsContext.Provider value={drafts.current}>
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

      {group.isSibling && (
        <View style={styles.siblingBanner}>
          <Text style={styles.siblingBannerText}>
            👀 Groupe de {group.coaches.map((c) => c.fullName).join(', ')} — vous le consultez en lecture seule, car vous
            encadrez un autre groupe de cette séance.
          </Text>
        </View>
      )}

      {(group.positions.length > 0 || isParticipant) && (
        <Section title="📍 Positions du groupe">
          <GroupMap positions={group.positions} myId={myId} />
          {/* Encadrants d'abord ; chacun avec son nom, sa couleur (rouge : encadrant, bleu : adhérent) et son âge. */}
          {[...group.positions].sort((a, b) => Number(b.isCoach) - Number(a.isCoach)).map((p) => (
            <PositionCard
              key={p.userId}
              position={p}
              isMe={p.userId === myId}
              own={isParticipant && p.userId === myId
                ? { busy: sharingBusy, onUpdate: () => void shareMyPosition(), onRemove: () => void removeMyPosition() }
                : undefined}
            />
          ))}
          {group.positions.length === 0 && <Text style={styles.muted}>Personne n'a partagé sa position pour le moment.</Text>}
          {isParticipant && !group.positions.some((p) => p.userId === myId) && (
            <Pressable
              onPress={() => void shareMyPosition()}
              disabled={sharingBusy}
              accessibilityRole="button"
              style={({ pressed }) => [styles.shareBtn, (pressed || sharingBusy) && { opacity: 0.85 }]}
            >
              {sharingBusy ? <ActivityIndicator size="small" color={COLORS.primary} /> : <Ionicons name="navigate-circle" size={20} color={COLORS.primary} />}
              <Text style={styles.shareLabel}>{sharingBusy ? 'Localisation en cours…' : 'Partager ma position'}</Text>
            </Pressable>
          )}
          {isParticipant && (
            <Text style={styles.hint}>
              Votre position n'est envoyée que lorsque vous appuyez sur le bouton : elle ne se met pas à jour toute seule
              (un téléphone en veille ne la suit plus). Elle est visible des encadrants et des adhérents du groupe, avec
              votre nom ; vous pouvez la retirer à tout moment.
            </Text>
          )}
          {shareError && <Text style={styles.errorInline}>{shareError}</Text>}
        </Section>
      )}

      {(isCoach || group.isSibling) && group.siblings.length > 0 && (
        <Section title={'👥 Autres groupes de la séance (' + group.siblings.length + ')'}>
          {group.siblings.map((g) => (
            <Pressable
              key={g.id}
              onPress={() => router.push(('/training-group/' + g.id) as never)}
              accessibilityRole="button"
              style={({ pressed }) => [styles.siblingCard, pressed && { opacity: 0.8 }]}
            >
              <View style={{ flex: 1, gap: 3 }}>
                <Text style={styles.siblingName} numberOfLines={2}>{g.name}{g.isMine ? ' (le vôtre)' : ''}</Text>
                <PaceBadge level={g.paceLevel} speed={g.speed} />
                <Text style={styles.sub} numberOfLines={2}>
                  {g.memberCount}{g.capacity !== null ? '/' + g.capacity : ''} adhérent{g.memberCount > 1 ? 's' : ''}
                  {g.coaches.length > 0 ? ' · ' + g.coaches.join(', ') : ''}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
            </Pressable>
          ))}
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
        {isParticipant && <CommentComposer groupId={groupId} onPosted={() => void reload()} />}
        {group.comments.length === 0 && <Text style={styles.muted}>Aucun commentaire pour le moment.</Text>}
        {group.comments.map((c) => (
          <CommentItem key={c.id} comment={c} groupId={groupId} byCoach={coachIds.has(c.authorId)} onDeleted={() => void reload()} />
        ))}
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
                    <Text style={styles.nom}>{m.nom.toUpperCase()}</Text> {m.prenom}{m.isMe ? ' (vous)' : ''}
                  </Text>
                </View>
                {isCoach && m.telephoneOf ? <Text style={styles.hint}>Joignable via {m.telephoneOf} (parent)</Text> : null}
                {isCoach && !m.telephone ? <Text style={styles.hint}>Numéro non renseigné</Text> : null}
                {/* Adhérent du groupe : appel et WhatsApp vers les autres adhérents (leur propre numéro seulement). */}
                {!isCoach && group.isMember && !m.isMe && m.telephone ? (
                  <View style={styles.memberActions}>
                    <ContactButtons name={label} telephone={m.telephone} />
                  </View>
                ) : null}
                {isCoach && (
                  <View style={styles.memberActions}>
                    {m.isJeune && <YouthDocsButton youthId={m.id} name={label} />}
                    {m.isJeune && <GoHomeAloneButton youthId={m.id} name={label} doc={m.goHomeAlone} />}
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
        {!isCoach && group.isMember && (
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

      {isCoach && onDone && (
        // Les modifications se font sur place ; « Sauvegarder » enregistre celles qui seraient encore en cours de saisie
        // (nom, informations, vitesse) puis revient à l'écran précédent (Entraînements).
        <Pressable
          onPress={() => void saveAndBack()}
          disabled={savingAll}
          accessibilityRole="button"
          style={({ pressed }) => [styles.saveBtn, (pressed || savingAll) && { opacity: 0.8 }]}
        >
          {savingAll ? <ActivityIndicator size="small" color="#fff" /> : <Ionicons name="checkmark-circle" size={20} color="#fff" />}
          <Text style={styles.saveLabel}>{savingAll ? 'Enregistrement…' : 'Sauvegarder'}</Text>
        </Pressable>
      )}

      {isCoach && (
        <View style={styles.dangerZone}>
          {/* Seul encadrant : il n'y a personne pour reprendre le groupe, il ne peut que le supprimer. */}
          {group.coaches.length > 1 && (
            <Pressable onPress={() => void leaveGroup()} style={({ pressed }) => [styles.dangerBtn, pressed && { opacity: 0.7 }]}>
              <Text style={styles.dangerLabel}>Quitter le groupe</Text>
            </Pressable>
          )}
          <Pressable onPress={() => void endGroup()} style={({ pressed }) => [styles.dangerBtn, styles.dangerBtnStrong, pressed && { opacity: 0.7 }]}>
            <Text style={[styles.dangerLabel, { color: '#fff' }]}>Supprimer le groupe</Text>
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
    </DraftsContext.Provider>
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
  siblingBanner: {
    padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: COLORS.secondarySoft,
    borderLeftWidth: 4, borderLeftColor: COLORS.secondary,
  },
  siblingBannerText: { fontSize: 13, color: COLORS.secondaryDark, lineHeight: 19, fontWeight: '600' },
  siblingCard: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surfaceAlt,
  },
  siblingName: { fontSize: 15, fontWeight: '800', color: COLORS.text },
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
  commentCoach: { fontSize: 11, fontWeight: '800', color: COACH_COLOR },
  saveBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, minHeight: 48,
    borderRadius: RADIUS.md, backgroundColor: COLORS.primary, marginTop: SPACING.sm,
  },
  saveLabel: { fontSize: 16, fontWeight: '800', color: '#fff' },
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
