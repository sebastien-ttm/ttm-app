import Ionicons from '@expo/vector-icons/Ionicons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { StaffMember, TrainingGroup, TrainingGroupPlan, TrainingGroupStaffCandidate } from '@/api/types';
import { MemberAvatar } from '@/components/MemberAvatar';
import { MemberPicker } from '@/components/groups/MemberPicker';
import { COLORS, RADIUS, SPACING } from '@/config';

function whenLabel(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/**
 * Création du groupe d'un encadrant / entraîneur, juste avant une séance : nom, informations, adhérents, autres
 * encadrants. Le groupe reste actif jusqu'au prochain créneau (`preview`) ; un encadrant n'en a qu'un à la fois.
 */
export function GroupCreateForm({ preview, onCreated }: {
  preview: TrainingGroupPlan | null;
  onCreated: (group: TrainingGroup) => void;
}) {
  const [name, setName] = useState('');
  const [info, setInfo] = useState('');
  const [members, setMembers] = useState<StaffMember[]>([]);
  const [coachIds, setCoachIds] = useState<Set<number>>(new Set());
  const [candidates, setCandidates] = useState<TrainingGroupStaffCandidate[]>([]);
  const [picker, setPicker] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    trainingGroups.staffCandidates()
      .then((r) => { if (!cancelled) setCandidates(r.data); })
      .catch(() => { /* sans la liste, le groupe se crée seul */ });
    return () => { cancelled = true; };
  }, []);

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
    setBusy(true);
    setError(null);
    try {
      const group = await trainingGroups.create({
        name: name.trim(),
        info: info.trim(),
        memberIds: members.map((m) => m.id),
        coachIds: [...coachIds],
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
        <Text style={styles.introTitle}>👥 Créer mon groupe</Text>
        <Text style={styles.introText}>
          Constituez votre groupe juste avant la séance. Les adhérents que vous ajoutez le voient dans leur onglet
          Entraînement : informations, trace GPX, commentaires et votre position en direct.
        </Text>
        {preview && (
          <Text style={styles.plan}>
            {preview.session
              ? `Séance : ${preview.session.title || 'entraînement'} · ${whenLabel(preview.session.startsAt)}. `
              : ''}
            Le groupe restera actif jusqu'au {whenLabel(preview.expiresAt)} (prochain créneau).
          </Text>
        )}
      </View>

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

      <Text style={styles.label}>Adhérents du groupe ({members.length})</Text>
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
  plan: { fontSize: 13, color: COLORS.secondaryDark, fontWeight: '600', lineHeight: 18 },
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
