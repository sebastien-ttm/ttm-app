import Ionicons from '@expo/vector-icons/Ionicons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { StaffMember, TrainingGroup, TrainingGroupSessionOption, TrainingGroupStaffCandidate } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { MemberAvatar } from '@/components/MemberAvatar';
import { CapacityStepper, PaceLevelPicker, speedPlaceholder } from '@/components/groups/GroupFields';
import { MemberPicker } from '@/components/groups/MemberPicker';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canPickFile, pickFile } from '@/lib/pickFile';

function whenLabel(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

type StepKey = 'name' | 'session' | 'capacity' | 'pace' | 'coaches' | 'members';

/** Taille maximale d'une trace GPX (la même qu'au serveur). */
const GPX_MAX_BYTES = 5_000_000;

function notify(message: string): void {
  if (Platform.OS === 'web') window.alert(message);
  else Alert.alert('Groupe', message);
}

function sizeLabel(bytes: number): string {
  return bytes >= 1_000_000 ? `${(bytes / 1_000_000).toFixed(1).replace('.', ',')} Mo` : `${Math.max(1, Math.round(bytes / 1000))} Ko`;
}

/** Les écrans du tunnel, dans l'ordre. `skippable` : l'écran peut être ignoré (valeurs laissées vides). */
const STEPS: { key: StepKey; title: string; hint: string; skippable: boolean }[] = [
  { key: 'session', title: 'Séance', hint: 'Le groupe est adossé à une séance : les adhérents le choisissent depuis le créneau.', skippable: false },
  { key: 'name', title: 'Votre groupe', hint: 'Donnez-lui un nom, des informations pour les adhérents et, si vous le souhaitez, la trace GPX du parcours.', skippable: false },
  { key: 'capacity', title: 'Nombre maximal d\'adhérents', hint: 'Au-delà, le groupe est complet. Vous pouvez ignorer cette étape : pas de limite.', skippable: true },
  { key: 'pace', title: 'Allure du groupe', hint: 'Allure du club et vitesse, tous deux facultatifs.', skippable: true },
  { key: 'coaches', title: 'Autres encadrants', hint: 'Encadrants qui animent le groupe avec vous.', skippable: true },
  { key: 'members', title: 'Adhérents', hint: 'Vous pouvez y ajouter des adhérents dès maintenant ; les autres le rejoindront depuis le créneau.', skippable: false },
];

/**
 * Création d'un groupe d'entraînement, en tunnel d'écrans successifs : séance (parmi celles cochées « Groupes
 * d'entraînement » dans la semaine type), puis nom, informations et trace GPX (facultative, envoyée une fois le groupe
 * créé), nombre maximal (ignorable), allure et vitesse (facultatives), autres encadrants, adhérents. Les adhérents
 * choisissent ensuite un groupe depuis le créneau, jusqu'au début de la séance. Un encadrant a un groupe par séance.
 */
export function GroupCreateForm({ sessions, onCreated, onCancel }: {
  sessions: TrainingGroupSessionOption[];
  onCreated: (group: TrainingGroup) => void;
  /** Présent quand le formulaire s'ouvre à côté de groupes existants : permet de le refermer. */
  onCancel?: () => void;
}) {
  const { user } = useAuth();
  const [step, setStep] = useState(0);
  const [name, setName] = useState(user?.prenom ?? '');
  const [info, setInfo] = useState('');
  // Trace GPX choisie à l'écran du nom : elle part vers le serveur dès que le groupe existe.
  const [gpx, setGpx] = useState<File | null>(null);
  const [occ, setOcc] = useState<string | null>(sessions.length === 1 ? sessions[0].occ : null);
  // null = pas de limite (écran ignoré).
  const [capacity, setCapacity] = useState<number | null>(null);
  const [paceLevel, setPaceLevel] = useState<number | null>(null);
  const [speed, setSpeed] = useState('');
  const [members, setMembers] = useState<StaffMember[]>([]);
  const [coachIds, setCoachIds] = useState<Set<number>>(new Set());
  const [candidates, setCandidates] = useState<TrainingGroupStaffCandidate[]>([]);
  const [picker, setPicker] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const session = sessions.find((x) => x.occ === occ) ?? null;
  const current = STEPS[step];
  const isLast = step === STEPS.length - 1;

  // Les encadrants qui ont déjà un groupe sur la séance choisie ne peuvent pas être ajoutés (un groupe par séance).
  useEffect(() => {
    let cancelled = false;
    trainingGroups.staffCandidates(occ)
      .then((r) => {
        if (cancelled) return;
        setCandidates(r.data);
        setCoachIds((c) => new Set([...c].filter((id) => r.data.some((cand) => cand.id === id && cand.activeGroupId === null))));
      })
      .catch(() => { /* sans la liste, le groupe se crée seul */ });
    return () => { cancelled = true; };
  }, [occ]);

  function toggleCoach(id: number) {
    setCoachIds((c) => {
      const next = new Set(c);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
    // Un encadrant ne peut pas être aussi adhérent du groupe.
    setMembers((list) => list.filter((m) => m.id !== id));
  }

  function goTo(index: number) {
    setError(null);
    setStep(Math.max(0, Math.min(STEPS.length - 1, index)));
  }

  function next() {
    if (current.key === 'session' && !session) {
      setError('Choisissez la séance du groupe.');
      return;
    }
    goTo(step + 1);
  }

  async function chooseGpx() {
    const file = await pickFile('.gpx,application/gpx+xml');
    if (file === null) return;
    if (!/\.gpx$/i.test(file.name)) {
      setError('Choisissez un fichier au format .gpx.');
      return;
    }
    if (file.size > GPX_MAX_BYTES) {
      setError(`Cette trace est trop volumineuse (${sizeLabel(GPX_MAX_BYTES)} maximum).`);
      return;
    }
    setError(null);
    setGpx(file);
  }

  /** « Ignorer » : on vide l'écran et on passe au suivant. */
  function skip() {
    if (current.key === 'capacity') setCapacity(null);
    if (current.key === 'pace') { setPaceLevel(null); setSpeed(''); }
    if (current.key === 'coaches') setCoachIds(new Set());
    goTo(step + 1);
  }

  async function submit() {
    if (busy) return;
    if (!session) {
      setError('Choisissez la séance du groupe.');
      return;
    }
    if (capacity !== null && members.length > capacity) {
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
      // Le groupe existe : si la trace ne part pas, on le dit sans le défaire (elle se rajoute depuis le groupe).
      if (gpx !== null) {
        try {
          await trainingGroups.addAttachment(group.id, gpx, gpx.name);
        } catch (e) {
          notify(`Le groupe est créé, mais la trace GPX n'a pas pu être envoyée${e instanceof ApiError ? ` : ${e.message}` : ''}. Vous pourrez l'ajouter depuis le groupe.`);
        }
      }
      onCreated(group);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Création impossible. Réessayez.');
    } finally {
      setBusy(false);
    }
  }

  if (sessions.length === 0) {
    return (
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.title}>👥 Créer un groupe</Text>
        <Text style={styles.noSession}>
          Aucune séance n'est proposée pour créer un groupe dans les 14 prochains jours (ou vous avez déjà un groupe sur
          chacune). Demandez à un administrateur de cocher les séances concernées dans la semaine type d'entraînement.
        </Text>
        {onCancel && (
          <Pressable onPress={onCancel} accessibilityRole="button" style={styles.linkBtn}>
            <Text style={styles.linkLabel}>← Retour à mes groupes</Text>
          </Pressable>
        )}
      </ScrollView>
    );
  }

  return (
    <View style={styles.root}>
      <View style={styles.header}>
        <View style={styles.headerInner}>
          <Text style={styles.stepCount}>👥 Créer un groupe · étape {step + 1} sur {STEPS.length}</Text>
          <View style={styles.bars} accessibilityRole="progressbar" accessibilityValue={{ min: 1, max: STEPS.length, now: step + 1 }}>
            {STEPS.map((s, i) => <View key={s.key} style={[styles.bar, i <= step && styles.barOn]} />)}
          </View>
        </View>
      </View>

      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <Text style={styles.title}>{current.title}</Text>
        <Text style={styles.hint}>{current.hint}</Text>

        {current.key === 'name' && (
          <>
            <Text style={styles.label}>Nom du groupe</Text>
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
            <Text style={styles.label}>Trace GPX (facultatif)</Text>
            {!canPickFile ? (
              <Text style={styles.small}>L'ajout d'une trace GPX se fait depuis l'appli web (navigateur du téléphone) : vous pourrez l'ajouter depuis le groupe.</Text>
            ) : gpx !== null ? (
              <View style={styles.personRow}>
                <Ionicons name="map" size={22} color={COLORS.primary} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.personName} numberOfLines={1}>{gpx.name}</Text>
                  <Text style={styles.small}>{sizeLabel(gpx.size)} · envoyée à la création du groupe</Text>
                </View>
                <Pressable
                  onPress={() => setGpx(null)}
                  disabled={busy}
                  hitSlop={10}
                  accessibilityRole="button"
                  accessibilityLabel={`Retirer la trace ${gpx.name}`}
                >
                  <Ionicons name="close-circle" size={22} color={COLORS.textSubtle} />
                </Pressable>
              </View>
            ) : (
              <Pressable onPress={() => void chooseGpx()} disabled={busy} style={({ pressed }) => [styles.addBtn, pressed && { opacity: 0.8 }]}>
                <Ionicons name="cloud-upload" size={18} color={COLORS.primary} />
                <Text style={styles.addBtnLabel}>Ajouter une trace GPX</Text>
              </Pressable>
            )}
          </>
        )}

        {current.key === 'session' && sessions.map((x) => {
          const on = x.occ === occ;
          return (
            <Pressable
              key={x.occ}
              onPress={() => { setOcc(x.occ); setError(null); }}
              accessibilityRole="radio"
              accessibilityState={{ checked: on }}
              style={({ pressed }) => [styles.sessionRow, on && styles.sessionRowOn, pressed && { opacity: 0.85 }]}
            >
              <Text style={styles.sessionIcon}>{x.sportIcon}</Text>
              <View style={{ flex: 1, gap: 1 }}>
                <Text style={styles.sessionTitle} numberOfLines={2}>{x.title || x.sportLabel}</Text>
                <Text style={styles.small}>{whenLabel(x.startsAt)}{x.location ? ` · ${x.location}` : ''}</Text>
                {x.groupCount > 0 && <Text style={styles.small}>{x.groupCount} groupe{x.groupCount > 1 ? 's' : ''} déjà pour cette séance</Text>}
              </View>
              <Ionicons name={on ? 'radio-button-on' : 'radio-button-off'} size={22} color={on ? COLORS.primary : COLORS.textMuted} />
            </Pressable>
          );
        })}

        {current.key === 'capacity' && (
          <>
            <Pressable
              onPress={() => setCapacity(null)}
              accessibilityRole="radio"
              accessibilityState={{ checked: capacity === null }}
              style={[styles.choice, capacity === null && styles.choiceOn]}
            >
              <Ionicons name={capacity === null ? 'radio-button-on' : 'radio-button-off'} size={22} color={capacity === null ? COLORS.primary : COLORS.textMuted} />
              <Text style={styles.choiceLabel}>Pas de limite</Text>
            </Pressable>
            <Pressable
              onPress={() => setCapacity((c) => c ?? 10)}
              accessibilityRole="radio"
              accessibilityState={{ checked: capacity !== null }}
              style={[styles.choice, capacity !== null && styles.choiceOn]}
            >
              <Ionicons name={capacity !== null ? 'radio-button-on' : 'radio-button-off'} size={22} color={capacity !== null ? COLORS.primary : COLORS.textMuted} />
              <Text style={styles.choiceLabel}>Limiter le nombre d'adhérents</Text>
            </Pressable>
            {capacity !== null && (
              <View style={styles.stepperBox}>
                <CapacityStepper value={capacity} onChange={setCapacity} disabled={busy} />
              </View>
            )}
          </>
        )}

        {current.key === 'pace' && (
          <>
            <Text style={styles.label}>Allure du groupe (facultatif)</Text>
            <PaceLevelPicker value={paceLevel} onChange={setPaceLevel} disabled={busy} />
            <Text style={styles.label}>Vitesse (facultatif)</Text>
            <TextInput
              value={speed}
              onChangeText={setSpeed}
              placeholder={speedPlaceholder(session?.sport)}
              placeholderTextColor={COLORS.textSubtle}
              maxLength={60}
              style={styles.input}
              editable={!busy}
            />
          </>
        )}

        {current.key === 'coaches' && (candidates.length === 0 ? (
          <Text style={styles.empty}>Aucun autre encadrant n'est disponible pour cette séance.</Text>
        ) : candidates.map((c) => {
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
                {busyElsewhere && <Text style={styles.small}>Gère déjà un groupe actif</Text>}
              </View>
              <Ionicons name={on ? 'checkbox' : 'square-outline'} size={24} color={on ? COLORS.primary : COLORS.textMuted} />
            </Pressable>
          );
        }))}

        {current.key === 'members' && (
          <>
            {session && (
              <Text style={styles.recap} numberOfLines={2}>
                {session.sportIcon} {session.title || session.sportLabel} · {whenLabel(session.startsAt)}
              </Text>
            )}
            <Text style={styles.label}>
              Adhérents du groupe ({members.length}{capacity !== null ? `/${capacity}` : ''})
            </Text>
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
          </>
        )}
      </ScrollView>

      <View style={styles.footer}>
        <View style={styles.footerInner}>
          {error && <Text style={styles.error}>{error}</Text>}
          <Pressable
            onPress={() => (isLast ? void submit() : next())}
            disabled={busy}
            accessibilityRole="button"
            style={({ pressed }) => [styles.primary, (busy || pressed) && { opacity: 0.8 }]}
          >
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryLabel}>{isLast ? 'Créer le groupe' : 'Suivant'}</Text>}
          </Pressable>
          <View style={styles.footerLinks}>
            {step > 0 ? (
              <Pressable onPress={() => goTo(step - 1)} disabled={busy} accessibilityRole="button" hitSlop={8}>
                <Text style={styles.linkLabel}>← Précédent</Text>
              </Pressable>
            ) : onCancel ? (
              <Pressable onPress={onCancel} accessibilityRole="button" hitSlop={8}>
                <Text style={styles.linkLabel}>← Retour à mes groupes</Text>
              </Pressable>
            ) : <View />}
            {current.skippable && (
              <Pressable onPress={skip} disabled={busy} accessibilityRole="button" hitSlop={8}>
                <Text style={styles.linkLabel}>Ignorer</Text>
              </Pressable>
            )}
          </View>
        </View>
      </View>

      <MemberPicker
        visible={picker}
        selectedIds={members.map((m) => m.id)}
        excludeIds={[...coachIds]}
        onConfirm={(_ids, picked) => { setMembers(picked); setPicker(false); }}
        onClose={() => setPicker(false)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  header: { backgroundColor: COLORS.surface, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border },
  headerInner: { maxWidth: 560, width: '100%', alignSelf: 'center', paddingHorizontal: SPACING.md, paddingTop: 10, paddingBottom: 10, gap: 8 },
  stepCount: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted },
  bars: { flexDirection: 'row', gap: 4 },
  bar: { flex: 1, height: 4, borderRadius: 2, backgroundColor: COLORS.border },
  barOn: { backgroundColor: COLORS.primary },
  content: { padding: SPACING.md, paddingBottom: SPACING.lg, maxWidth: 560, width: '100%', alignSelf: 'center' },
  title: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  hint: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18, marginTop: 4, marginBottom: 4 },
  noSession: { marginTop: SPACING.md, fontSize: 14, color: COLORS.textMuted, lineHeight: 20 },
  label: { color: COLORS.text, fontWeight: '700', fontSize: 13, marginTop: SPACING.md, marginBottom: 6 },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  textarea: { minHeight: 90, textAlignVertical: 'top' },
  sessionRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 10, marginTop: 8,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  sessionRowOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  sessionIcon: { fontSize: 24 },
  sessionTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  choice: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, marginTop: 8,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  choiceOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  choiceLabel: { fontSize: 15, fontWeight: '600', color: COLORS.text },
  stepperBox: { marginTop: SPACING.md, paddingLeft: 4 },
  empty: { marginTop: SPACING.md, fontSize: 14, color: COLORS.textMuted, lineHeight: 20 },
  recap: { marginTop: 8, fontSize: 12.5, color: COLORS.textMuted },
  personRow: {
    flexDirection: 'row', alignItems: 'center', gap: 10, padding: 8, marginBottom: 6,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  personRowOn: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary },
  personName: { flex: 1, fontSize: 14, color: COLORS.text },
  nom: { fontWeight: '700' },
  small: { fontSize: 11, color: COLORS.textMuted },
  addBtn: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, paddingVertical: 11,
    borderRadius: RADIUS.md, borderWidth: 1.5, borderColor: COLORS.primary, borderStyle: 'dashed', backgroundColor: COLORS.surface,
  },
  addBtnLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  footer: { backgroundColor: COLORS.surface, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border },
  footerInner: { maxWidth: 560, width: '100%', alignSelf: 'center', padding: SPACING.md, gap: 10 },
  error: { color: COLORS.error, backgroundColor: '#fee2e2', padding: 10, borderRadius: RADIUS.sm, fontSize: 13, fontWeight: '500' },
  primary: { backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 14, alignItems: 'center' },
  primaryLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
  footerLinks: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  linkBtn: { alignSelf: 'flex-start', paddingVertical: 8 },
  linkLabel: { color: COLORS.primary, fontWeight: '700', fontSize: 13 },
});
