import Ionicons from '@expo/vector-icons/Ionicons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { StaffMember, TrainingGroup, TrainingGroupSessionOption, TrainingGroupStaffCandidate } from '@/api/types';
import { MemberAvatar } from '@/components/MemberAvatar';
import { CapacityStepper, PaceLevelPicker } from '@/components/groups/GroupFields';
import { MemberPicker } from '@/components/groups/MemberPicker';
import { COLORS, RADIUS, SPACING } from '@/config';

function whenLabel(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/**
 * Création d'un groupe d'entraînement, adossé à une séance de vélo ou de course à pied à venir : séance, nombre de
 * places, allure du club (1 à 5) et vitesse, nom, informations, adhérents, autres encadrants. Les adhérents choisissent
 * ensuite un groupe depuis le créneau, jusqu'au début de la séance. Un encadrant a un groupe par séance.
 */
export function GroupCreateForm({ sessions, onCreated, onCancel }: {
  sessions: TrainingGroupSessionOption[];
  onCreated: (group: TrainingGroup) => void;
  /** Présent quand le formulaire s'ouvre à côté de groupes existants : permet de le refermer. */
  onCancel?: () => void;
}) {
  const [occ, setOcc] = useState<string | null>(sessions[0]?.occ ?? null);
  const [capacity, setCapacity] = useState(10);
  const [paceLevel, setPaceLevel] = useState<number | null>(null);
  const [speed, setSpeed] = useState('');
  const [name, setName] = useState('');
  const [info, setInfo] = useState('');
  const [members, setMembers] = useState<StaffMember[]>([]);
  const [coachIds, setCoachIds] = useState<Set<number>>(new Set());
  const [candidates, setCandidates] = useState<TrainingGroupStaffCandidate[]>([]);
  const [picker, setPicker] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const session = sessions.find((x) => x.occ === occ) ?? null;

  // Les encadrants qui ont déjà un groupe sur la séance choisie ne peuvent pas être ajoutés (un groupe par séance).
  useEffect(() => {
    let cancelled = false;
    trainingGroups.staffCandidates(occ)
      .then((r) => {
        if (cancelled) return;
        setCandidates(r.data);
        setCoachIds((current) => new Set([...current].filter((id) => r.data.some((c) => c.id === id && c.activeGroupId === null))));
      })
      .catch(() => { /* sans la liste, le groupe se crée seul */ });
    return () => { cancelled = true; };
  }, [occ]);

  function toggleCoach(id: number) {
    setCoachIds((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function submit() {
    if (busy) return;
    if (!session) {
      setError('Choisissez la séance du groupe.');
      return;
    }
    if (members.length > capacity) {
      setError(`Le groupe est limité à ${capacity} adhérent${capacity > 1 ? 's' : ''} : retirez-en ou ajoutez des places.`);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const group = await trainingGroups.create({
        name: name.trim(),
        info: info.trim(),
        memberIds: members.map((m) => m.id),
        coachIds: [...coachIds],
        slot: {
          week: session.week,
          ...(session.templateId !== null ? { templateId: session.templateId } : { slotId: session.slotId ?? undefined }),
        },
        capacity,
        paceLevel,
        speed: speed.trim(),
      });
      onCreated(group);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Création impossible. Réessayez.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <View style={styles.intro}>
        <Text style={styles.introTitle}>👥 Créer un groupe</Text>
        <Text style={styles.introText}>
          Un groupe est adossé à une séance de vélo ou de course à pied. Les adhérents le choisissent depuis le créneau,
          jusqu'au début de la séance, dans la limite des places ; vous pouvez aussi y ajouter des adhérents. Il reste
          ensuite actif jusqu'au créneau suivant : informations, trace GPX, commentaires et votre position en direct.
        </Text>
        {onCancel && (
          <Pressable onPress={onCancel} accessibilityRole="button" style={({ pressed }) => [styles.cancel, pressed && { opacity: 0.6 }]}>
            <Text style={styles.cancelLabel}>← Retour à mes groupes</Text>
          </Pressable>
        )}
      </View>

      {sessions.length === 0 ? (
        <Text style={styles.noSession}>
          Aucune séance de vélo ou de course à pied à venir (dans les 14 prochains jours) pour laquelle vous n'avez pas déjà
          un groupe.
        </Text>
      ) : (
        <>
      <Text style={styles.label}>Séance</Text>
      {sessions.map((x) => {
        const on = x.occ === occ;
        return (
          <Pressable
            key={x.occ}
            onPress={() => setOcc(x.occ)}
            accessibilityRole="radio"
            accessibilityState={{ checked: on }}
            style={({ pressed }) => [styles.sessionRow, on && styles.sessionRowOn, pressed && { opacity: 0.85 }]}
          >
            <Text style={styles.sessionIcon}>{x.sportIcon}</Text>
            <View style={{ flex: 1, gap: 1 }}>
              <Text style={styles.sessionTitle} numberOfLines={2}>{x.title || x.sportLabel}</Text>
              <Text style={styles.hint}>{whenLabel(x.startsAt)}{x.location ? ` · ${x.location}` : ''}</Text>
              {x.groupCount > 0 && <Text style={styles.hint}>{x.groupCount} groupe{x.groupCount > 1 ? 's' : ''} déjà pour cette séance</Text>}
            </View>
            <Ionicons name={on ? 'radio-button-on' : 'radio-button-off'} size={22} color={on ? COLORS.primary : COLORS.textMuted} />
          </Pressable>
        );
      })}

      <Text style={styles.label}>Nombre maximal d'adhérents</Text>
      <CapacityStepper value={capacity} onChange={setCapacity} disabled={busy} />

      <Text style={styles.label}>Allure du groupe (facultatif)</Text>
      <PaceLevelPicker value={paceLevel} onChange={setPaceLevel} disabled={busy} />

      <Text style={styles.label}>Vitesse (facultatif)</Text>
      <TextInput
        value={speed}
        onChangeText={setSpeed}
        placeholder={session?.sport === 'course' ? "Ex : 5'30 /km" : 'Ex : 28 km/h'}
        placeholderTextColor={COLORS.textSubtle}
        maxLength={60}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Nom du groupe (facultatif)</Text>
      <TextInput
        value={name}
        onChangeText={setName}
        placeholder="Ex : Groupe vélo B"
        placeholderTextColor={COLORS.textSubtle}
        maxLength={80}
        style={styles.input}
        editable={!busy}
      />

      <Text style={styles.label}>Informations (facultatif)</Text>
      <TextInput
        value={info}
        onChangeText={setInfo}
        placeholder="Rendez-vous, programme de la séance, matériel…"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={3000}
        style={[styles.input, styles.textarea]}
        editable={!busy}
      />

      <Text style={styles.label}>Adhérents du groupe ({members.length}/{capacity})</Text>
      {members.map((m) => (
        <View key={m.id} style={styles.personRow}>
          <MemberAvatar prenom={m.prenom} nom={m.nom} avatarUrl={m.avatarUrl} size={36} />
          <Text style={styles.personName} numberOfLines={1}>
            <Text style={styles.nom}>{m.nom.toUpperCase()}</Text> {m.prenom}
          </Text>
          <Pressable
            onPress={() => setMembers((list) => list.filter((x) => x.id !== m.id))}
            hitSlop={10}
            accessibilityRole="button"
            accessibilityLabel={`Retirer ${m.prenom} ${m.nom}`}
          >
            <Ionicons name="close-circle" size={22} color={COLORS.textSubtle} />
          </Pressable>
        </View>
      ))}
      <Pressable onPress={() => setPicker(true)} style={({ pressed }) => [styles.addBtn, pressed && { opacity: 0.8 }]}>
        <Ionicons name="person-add" size={18} color={COLORS.primary} />
        <Text style={styles.addBtnLabel}>{members.length === 0 ? 'Choisir des adhérents' : 'Modifier la sélection'}</Text>
      </Pressable>

      {candidates.length > 0 && (
        <>
          <Text style={styles.label}>Partager avec d'autres encadrants (facultatif)</Text>
          {candidates.map((c) => {
            const busyElsewhere = c.activeGroupId !== null;
            const on = coachIds.has(c.id);
            return (
              <Pressable
                key={c.id}
                disabled={busyElsewhere}
                onPress={() => toggleCoach(c.id)}
                accessibilityRole="checkbox"
                accessibilityState={{ checked: on, disabled: busyElsewhere }}
                style={({ pressed }) => [styles.personRow, on && styles.personRowOn, busyElsewhere && { opacity: 0.5 }, pressed && { opacity: 0.85 }]}
              >
                <MemberAvatar prenom={c.prenom} nom={c.nom} avatarUrl={c.avatarUrl} size={36} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.personName} numberOfLines={1}>
                    <Text style={styles.nom}>{c.nom.toUpperCase()}</Text> {c.prenom}
                  </Text>
                  {busyElsewhere && <Text style={styles.hint}>Gère déjà un groupe actif</Text>}
                </View>
                <Ionicons name={on ? 'checkbox' : 'square-outline'} size={24} color={on ? COLORS.primary : COLORS.textMuted} />
              </Pressable>
            );
          })}
        </>
      )}

      {error && <Text style={styles.error}>{error}</Text>}

      <Pressable
        onPress={() => void submit()}
        disabled={busy}
        style={({ pressed }) => [styles.submit, (busy || pressed) && { opacity: 0.8 }]}
      >
        {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitLabel}>Créer le groupe</Text>}
      </Pressable>
        </>
      )}

      <MemberPicker
        visible={picker}
        selectedIds={members.map((m) => m.id)}
        onConfirm={(_ids, picked) => { setMembers(picked); setPicker(false); }}
        onClose={() => setPicker(false)}
      />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, maxWidth: 560, width: '100%', alignSelf: 'center' },
  intro: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, padding: SPACING.md, gap: 6 },
  introTitle: { fontSize: 17, fontWeight: '800', color: COLORS.text },
  introText: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  cancel: { alignSelf: 'flex-start', paddingVertical: 4 },
  cancelLabel: { color: COLORS.primary, fontWeight: '700', fontSize: 13 },
  noSession: { marginTop: SPACING.lg, fontSize: 14, color: COLORS.textMuted, lineHeight: 20, textAlign: 'center' },
  sessionRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 10, marginBottom: 6,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  sessionRowOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  sessionIcon: { fontSize: 24 },
  sessionTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  label: { color: COLORS.text, fontWeight: '700', fontSize: 13, marginTop: SPACING.lg, marginBottom: 6 },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 90, textAlignVertical: 'top' },
  personRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 8, marginBottom: 6,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  personRowOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  personName: { flex: 1, fontSize: 14, color: COLORS.text },
  nom: { fontWeight: '700' },
  hint: { fontSize: 11, color: COLORS.textMuted },
  addBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 11,
    borderRadius: RADIUS.md, borderWidth: 1.5, borderColor: COLORS.primary, borderStyle: 'dashed', backgroundColor: COLORS.surface,
  },
  addBtnLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  error: { color: COLORS.error, backgroundColor: '#fee2e2', padding: 12, borderRadius: RADIUS.sm, marginTop: SPACING.md, fontSize: 13, fontWeight: '500' },
  submit: { backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 14, alignItems: 'center', marginTop: SPACING.lg },
  submitLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
});
