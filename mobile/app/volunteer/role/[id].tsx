import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { volunteer as volunteerApi } from '@/api/resources';
import type { VolunteerRoleDetail, VolunteerTask } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { HoldersBadge, POLE_COLORS, StatusChip, VOL, confirmAsync, showError } from '@/components/volunteer/ui';

const PHASES: { key: VolunteerTask['phase']; label: string; dates: string; color: string }[] = [
  { key: 'A', label: 'Amont', dates: 'De J-6 mois à J-1 mois', color: VOL.teal },
  { key: 'P', label: 'Pré-opérationnel', dates: 'De J-15 à la veille', color: VOL.sun },
  { key: 'O', label: 'Jours J', dates: 'Le week-end de course', color: VOL.coral },
];

/**
 * Fiche de mission (activité ou poste terrain) et positionnement de l'adhérent : message facultatif,
 * « Je me positionne », retrait tant que la candidature est en attente.
 */
export default function VolunteerRoleScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const [role, setRole] = useState<VolunteerRoleDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      setRole(await volunteerApi.role(Number(id)));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, [id]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  async function apply() {
    if (!role) return;
    setBusy(true);
    try {
      setRole(await volunteerApi.apply(role.id, message.trim() === '' ? null : message.trim()));
      setMessage('');
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  async function withdraw() {
    if (!role) return;
    const ok = await confirmAsync('Retirer ma candidature ?', `Tu ne seras plus positionné(e) sur « ${role.name} ».`, 'Retirer');
    if (!ok) return;
    setBusy(true);
    try {
      setRole(await volunteerApi.withdraw(role.id));
    } catch (e) {
      showError(e);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!role) return <FullScreenLoading />;

  const c = role.content;
  const isField = role.kind === 'field';
  const canApply = role.applicationsOpen && (role.myStatus === null || role.myStatus === 'declined');

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: role.name }} />
      <ScrollView contentContainerStyle={styles.content}>
        <View style={[styles.hero, isField && styles.heroField]}>
          <Text style={styles.pill}>
            {isField ? 'Responsable terrain · Activité ' + (role.activityName ?? role.pole.name) : 'Pôle ' + role.pole.name + ' · Activité ' + role.name}
          </Text>
          <Text style={[styles.heroTitle, isField && { color: VOL.navy }]}>{role.title ?? role.name}</Text>
          <Text style={[styles.heroSub, isField && { color: VOL.navy }]}>{role.name} · {role.campaignName}</Text>
          {role.pitch ? <Text style={[styles.heroPitch, isField && { color: VOL.navy }]}>{role.pitch}</Text> : null}
          <HoldersBadge role={role} />
        </View>

        <View style={styles.applyCard}>
          {role.myStatus === 'accepted' ? (
            <>
              <StatusChip status="accepted" prefix="Ma candidature : " />
              <Text style={styles.applyText}>Tu fais partie de l'équipe. Merci !</Text>
            </>
          ) : role.myStatus === 'pending' ? (
            <>
              <StatusChip status="pending" prefix="Ma candidature : " />
              <Text style={styles.applyText}>
                Les responsables du pôle {role.pole.name} vont revenir vers toi.
                {role.myMessage ? '\nTon message : « ' + role.myMessage + ' »' : ''}
              </Text>
              <Pressable onPress={() => void withdraw()} disabled={busy} style={[styles.secondaryBtn, busy && { opacity: 0.6 }]}>
                <Text style={styles.secondaryLabel}>Retirer ma candidature</Text>
              </Pressable>
            </>
          ) : canApply ? (
            <>
              {role.myStatus === 'declined' && (
                <Text style={styles.applyText}>Ta précédente candidature n'a pas été retenue. Tu peux te positionner à nouveau.</Text>
              )}
              <Text style={styles.applyTitle}>Partant·e ?</Text>
              <TextInput
                value={message}
                onChangeText={setMessage}
                placeholder="Un mot pour les responsables (disponibilités, expérience…) — facultatif"
                placeholderTextColor={VOL.muted}
                multiline
                maxLength={500}
                style={styles.input}
              />
              <Pressable onPress={() => void apply()} disabled={busy} style={[styles.primaryBtn, busy && { opacity: 0.6 }]}>
                {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryLabel}>Je me positionne</Text>}
              </Pressable>
              <Text style={styles.applyHint}>Tu peux te positionner sur plusieurs fiches. Les responsables du pôle valident.</Text>
            </>
          ) : (
            <Text style={styles.applyText}>Les candidatures sont closes pour cet événement.</Text>
          )}
        </View>

        {!isField ? (
          <>
            <Section title="Ta mission"><List items={c.missions} /></Section>
            {c.tasks && c.tasks.length > 0 && (
              <Section title="Tes tâches, phase par phase">
                {PHASES.map((ph, i) => {
                  const items = (c.tasks ?? []).filter((t) => t.phase === ph.key);
                  if (items.length === 0) return null;
                  return (
                    <View key={ph.key} style={styles.phase}>
                      <Text style={styles.phaseTitle}>
                        <Text style={{ color: ph.color }}>● </Text>{ph.label}
                        <Text style={styles.phaseDates}>  {c.phaseDates?.[i] ?? ph.dates}</Text>
                      </Text>
                      {items.map((t, k) => (
                        <View key={k} style={styles.task}>
                          <Text style={styles.taskText}>{t.text}</Text>
                          <Text style={styles.taskMeta}>{t.due}{t.links ? ' · En lien avec : ' + t.links : ''}</Text>
                        </View>
                      ))}
                    </View>
                  );
                })}
              </Section>
            )}
            <Section title="Ta place dans l'équipe">
              <Text style={styles.p}>Tu es référent·e de l'activité {role.name}.</Text>
              {role.pole.managerNames ? <Text style={styles.p}>Tu rends compte à {role.pole.managerNames}, responsables du pôle {role.pole.name}.</Text> : null}
              <Text style={styles.p}>Tu échanges régulièrement avec tes responsables de pôle.</Text>
            </Section>
            {c.facts && c.facts.length > 0 && (
              <Section title="En chiffres">
                <View style={styles.facts}>
                  {c.facts.map((f, k) => (
                    <View key={k} style={styles.fact}>
                      <Text style={styles.factValue}>{f.value}</Text>
                      <Text style={styles.factLabel}>{f.label}</Text>
                    </View>
                  ))}
                </View>
              </Section>
            )}
            <Section title="Ce qui t'aidera"><List items={c.strengths} /></Section>
            {c.gain ? <Section title="Ce que tu y gagnes"><Text style={styles.p}>{c.gain}</Text></Section> : null}
            {c.eco ? <Section title="Le réflexe éco"><Text style={styles.p}>{c.eco}</Text></Section> : null}
          </>
        ) : (
          <>
            <Section title="Ta mission"><List items={c.missions} /></Section>
            {c.schedule && c.schedule.length > 0 && (
              <Section title="Ton planning">
                {c.schedule.map((s, k) => (
                  <View key={k} style={styles.task}>
                    <Text style={styles.taskText}>{s.when}</Text>
                    <Text style={styles.taskMeta}>{s.what}</Text>
                  </View>
                ))}
              </Section>
            )}
            <Section title="Ton équipe">
              {c.team ? <Text style={styles.p}>Tu encadres : {c.team}</Text> : null}
              {role.activity && role.activity.holders.length > 0 ? (
                <Text style={styles.p}>
                  Tu rends compte à {role.activity.holders.join(', ')}, {role.activity.holderLabel.toLowerCase()} de l'activité {role.activity.name}.
                </Text>
              ) : c.reportsTo ? (
                <Text style={styles.p}>Tu rends compte à : {c.reportsTo}</Text>
              ) : null}
              {role.activity?.id ? (
                <Pressable onPress={() => router.push(('/volunteer/role/' + role.activity?.id) as never)}>
                  <Text style={styles.link}>Voir la fiche de l'activité {role.activity.name}</Text>
                </Pressable>
              ) : null}
            </Section>
            <Section title="Consignes clés"><List items={c.rules} /></Section>
            <Section title="En repères">
              {c.location ? <Text style={styles.p}>Lieu : {c.location}</Text> : null}
              <Text style={styles.p}>
                Postes : {role.requiredCount ? `${role.requiredCount} requis` : 'nombre à définir'}{c.countNote ? ' · ' + c.countNote : ''}
              </Text>
              <Text style={styles.p}>Briefing : vers J-7{role.activity && role.activity.holders.length > 0 ? ', avec ' + role.activity.holders.join(', ') : ', avec ton référent'}</Text>
            </Section>
            <Section title="Ton matériel"><List items={c.equipment} /></Section>
            <Section title="Prérequis"><List items={c.prerequisites} /></Section>
          </>
        )}

        <View style={[styles.poleFooter, { borderLeftColor: POLE_COLORS[role.pole.color] }]}>
          <Text style={styles.p}>
            Pôle {role.pole.name}{role.pole.managerNames ? ' · Responsables de pôle : ' + role.pole.managerNames : ''}
          </Text>
        </View>
      </ScrollView>
    </View>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {children}
    </View>
  );
}

function List({ items }: { items?: string[] }) {
  if (!items || items.length === 0) return null;
  return (
    <>
      {items.map((x, k) => (
        <Text key={k} style={styles.li}>•  {x}</Text>
      ))}
    </>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: VOL.cream },
  content: { padding: 14, gap: 12, maxWidth: 760, width: '100%', alignSelf: 'center', paddingBottom: 40 },
  hero: { backgroundColor: VOL.navy, borderRadius: 16, padding: 18 },
  heroField: { backgroundColor: VOL.teal },
  pill: { alignSelf: 'flex-start', backgroundColor: VOL.coral, color: '#fff', fontSize: 11, fontWeight: '700', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, overflow: 'hidden' },
  heroTitle: { color: '#fff', fontSize: 24, fontWeight: '800', marginTop: 10 },
  heroSub: { color: VOL.sun, fontSize: 13, fontWeight: '600', marginTop: 2 },
  heroPitch: { color: VOL.cream, fontSize: 14, lineHeight: 20, marginTop: 10 },
  applyCard: { backgroundColor: '#fff', borderRadius: 14, padding: 14, gap: 8, borderWidth: 2, borderColor: VOL.sun },
  applyTitle: { fontSize: 18, fontWeight: '800', color: VOL.navy },
  applyText: { fontSize: 14, color: VOL.navy, lineHeight: 20 },
  applyHint: { fontSize: 12, color: VOL.muted },
  input: { minHeight: 70, borderWidth: 1, borderColor: VOL.border, borderRadius: 10, padding: 10, fontSize: 14, color: VOL.navy, backgroundColor: '#fffdf8', textAlignVertical: 'top' },
  primaryBtn: { backgroundColor: VOL.coral, borderRadius: 10, paddingVertical: 12, alignItems: 'center' },
  primaryLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
  secondaryBtn: { alignSelf: 'flex-start', borderWidth: 1, borderColor: VOL.border, borderRadius: 10, paddingVertical: 8, paddingHorizontal: 12 },
  secondaryLabel: { color: VOL.navy, fontWeight: '700', fontSize: 13 },
  section: { backgroundColor: '#fff', borderRadius: 14, padding: 14, gap: 4 },
  sectionTitle: { fontSize: 16, fontWeight: '800', color: VOL.navy, marginBottom: 4 },
  li: { fontSize: 14, color: VOL.navy, lineHeight: 20 },
  p: { fontSize: 14, color: VOL.navy, lineHeight: 20 },
  link: { fontSize: 14, color: VOL.coral, fontWeight: '700', marginTop: 4 },
  phase: { backgroundColor: VOL.cream, borderRadius: 10, padding: 10, marginTop: 6 },
  phaseTitle: { fontSize: 15, fontWeight: '800', color: VOL.navy },
  phaseDates: { fontSize: 12, fontWeight: '600', color: VOL.muted },
  task: { paddingVertical: 6, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: VOL.border },
  taskText: { fontSize: 14, color: VOL.navy, fontWeight: '600' },
  taskMeta: { fontSize: 12, color: VOL.muted, marginTop: 2 },
  facts: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  fact: { backgroundColor: VOL.cream, borderRadius: 10, padding: 10, minWidth: 140, flexGrow: 1 },
  factValue: { fontSize: 18, fontWeight: '800', color: VOL.navy },
  factLabel: { fontSize: 12, color: VOL.muted },
  poleFooter: { borderLeftWidth: 4, paddingLeft: 10, paddingVertical: 4 },
});
