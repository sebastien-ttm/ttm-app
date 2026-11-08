import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { volunteer as volunteerApi } from '@/api/resources';
import type { VolunteerApplication, VolunteerApplicationStatus, VolunteerManageResponse, VolunteerRoleSummary } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { HoldersBadge, StatusChip, VOL, showError } from '@/components/volunteer/ui';
import { formatPhoneFr, telHref } from '@/utils/phone';

type Tab = 'pending' | 'done' | 'roles';

/**
 * Espace des responsables de pôle : candidatures sur les fiches de leurs pôles (tous les pôles pour un
 * administrateur). Valider ou décliner (l'adhérent est notifié), revenir sur une décision, et affecter
 * directement un adhérent à une fiche.
 */
export default function VolunteerManageScreen() {
  const [campaignId, setCampaignId] = useState<number | null>(null);
  const [data, setData] = useState<VolunteerManageResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [tab, setTab] = useState<Tab>('pending');
  const [busyId, setBusyId] = useState<number | null>(null);
  const [assigning, setAssigning] = useState<VolunteerRoleSummary | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const { campaign } = await volunteerApi.current();
      if (!campaign || !campaign.isManager) {
        setError('Cet espace est réservé aux responsables de pôle.');
        return;
      }
      setCampaignId(campaign.id);
      setData(await volunteerApi.manage(campaign.id));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  async function decide(a: VolunteerApplication, status: VolunteerApplicationStatus) {
    setBusyId(a.id);
    try {
      await volunteerApi.decide(a.id, status);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!data || campaignId === null) return <FullScreenLoading />;

  const pending = data.applications.filter((a) => a.status === 'pending');
  const done = data.applications.filter((a) => a.status !== 'pending');

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: 'Candidatures à traiter' }} />
      <View style={styles.tabs}>
        <TabBtn label={'À traiter · ' + pending.length} active={tab === 'pending'} onPress={() => setTab('pending')} />
        <TabBtn label={'Traitées · ' + done.length} active={tab === 'done'} onPress={() => setTab('done')} />
        <TabBtn label="Fiches" active={tab === 'roles'} onPress={() => setTab('roles')} />
      </View>
      <ScrollView
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
      >
        <Text style={styles.poles}>Pôle{data.poles.length > 1 ? 's' : ''} : {data.poles.map((p) => p.name).join(', ')}</Text>

        {tab !== 'roles' && (tab === 'pending' ? pending : done).map((a) => (
          <ApplicationCard key={a.id} a={a} busy={busyId === a.id} onDecide={(s) => void decide(a, s)} />
        ))}
        {tab === 'pending' && pending.length === 0 && <Text style={styles.empty}>Aucune candidature en attente.</Text>}
        {tab === 'done' && done.length === 0 && <Text style={styles.empty}>Aucune candidature traitée pour l'instant.</Text>}

        {tab === 'roles' && data.roles.map((r) => (
          <View key={r.id} style={styles.card}>
            <Text style={styles.kicker}>
              {r.kind === 'field' ? 'Responsable terrain' : 'Activité'} · Pôle {r.poleName}{r.published === false ? ' · non publiée' : ''}
            </Text>
            <Text style={styles.name}>{r.name}</Text>
            <HoldersBadge role={r} />
            {assigning?.id === r.id ? (
              <AssignPanel
                campaignId={campaignId}
                role={r}
                onClose={() => setAssigning(null)}
                onDone={async () => { setAssigning(null); await load(); }}
              />
            ) : (
              <Pressable onPress={() => setAssigning(r)} style={styles.linkBtn}>
                <Ionicons name="person-add-outline" size={16} color={VOL.coral} />
                <Text style={styles.linkLabel}>Affecter un adhérent</Text>
              </Pressable>
            )}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

function ApplicationCard({ a, busy, onDecide }: { a: VolunteerApplication; busy: boolean; onDecide: (s: VolunteerApplicationStatus) => void }) {
  const phone = a.user?.telephone ?? null;
  return (
    <View style={[styles.card, busy && { opacity: 0.6 }]}>
      <Text style={styles.kicker}>{a.role.kind === 'field' ? 'Responsable terrain' : 'Activité'} · {a.role.name}</Text>
      <Text style={styles.name}>{a.user?.fullName}</Text>
      <StatusChip status={a.status} />
      {a.message ? <Text style={styles.message}>« {a.message} »</Text> : null}
      {a.assigned ? <Text style={styles.meta}>Affecté(e) directement</Text> : null}
      {a.decidedBy ? <Text style={styles.meta}>Décision : {a.decidedBy}</Text> : null}
      {phone ? (
        <Pressable onPress={() => void Linking.openURL(telHref(phone))}>
          <Text style={styles.phone}>📞 {formatPhoneFr(phone)}</Text>
        </Pressable>
      ) : null}
      <View style={styles.row}>
        {a.status !== 'accepted' && (
          <Pressable disabled={busy} onPress={() => onDecide('accepted')} style={[styles.btn, styles.btnAccept]}>
            <Text style={styles.btnLabelLight}>Valider</Text>
          </Pressable>
        )}
        {a.status !== 'declined' && (
          <Pressable disabled={busy} onPress={() => onDecide('declined')} style={[styles.btn, styles.btnDecline]}>
            <Text style={styles.btnLabel}>Décliner</Text>
          </Pressable>
        )}
        {a.status !== 'pending' && (
          <Pressable disabled={busy} onPress={() => onDecide('pending')} style={[styles.btn, styles.btnDecline]}>
            <Text style={styles.btnLabel}>Remettre en attente</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

function AssignPanel({ campaignId, role, onClose, onDone }: {
  campaignId: number;
  role: VolunteerRoleSummary;
  onClose: () => void;
  onDone: () => Promise<void>;
}) {
  const [q, setQ] = useState('');
  const [results, setResults] = useState<{ id: number; fullName: string }[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (q.trim().length < 2) {
      setResults([]);
      return;
    }
    const t = setTimeout(() => {
      volunteerApi.members(campaignId, q.trim()).then((r) => setResults(r.data)).catch(() => setResults([]));
    }, 300);
    return () => clearTimeout(t);
  }, [q, campaignId]);

  async function pick(userId: number) {
    setBusy(true);
    try {
      await volunteerApi.assign(role.id, userId);
      await onDone();
    } catch (e) {
      showError(e);
      setBusy(false);
    }
  }

  return (
    <View style={styles.assign}>
      <TextInput
        value={q}
        onChangeText={setQ}
        autoFocus
        placeholder="Nom ou prénom de l'adhérent"
        placeholderTextColor={VOL.muted}
        style={styles.input}
      />
      {results.map((u) => (
        <Pressable key={u.id} disabled={busy} onPress={() => void pick(u.id)} style={styles.result}>
          <Text style={styles.resultLabel}>{u.fullName}</Text>
          <Ionicons name="add-circle-outline" size={18} color={VOL.coral} />
        </Pressable>
      ))}
      <Pressable onPress={onClose}>
        <Text style={styles.cancel}>Annuler</Text>
      </Pressable>
    </View>
  );
}

function TabBtn({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.tab, active && styles.tabActive]}>
      <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: VOL.cream },
  tabs: { flexDirection: 'row', gap: 6, paddingHorizontal: 14, paddingTop: 10, maxWidth: 760, width: '100%', alignSelf: 'center' },
  tab: { flex: 1, paddingVertical: 9, borderRadius: 10, alignItems: 'center', backgroundColor: '#fff', borderWidth: 1, borderColor: VOL.border },
  tabActive: { backgroundColor: VOL.navy, borderColor: VOL.navy },
  tabLabel: { fontSize: 12.5, fontWeight: '700', color: VOL.navy },
  tabLabelActive: { color: '#fff' },
  list: { padding: 14, gap: 8, maxWidth: 760, width: '100%', alignSelf: 'center', paddingBottom: 40 },
  poles: { fontSize: 12.5, fontWeight: '700', color: VOL.muted },
  empty: { fontSize: 14, color: VOL.muted, textAlign: 'center', marginTop: 20 },
  card: { backgroundColor: '#fff', borderRadius: 12, padding: 12, gap: 4, borderWidth: 1, borderColor: VOL.border },
  kicker: { fontSize: 10.5, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: VOL.muted },
  name: { fontSize: 16, fontWeight: '800', color: VOL.navy },
  message: { fontSize: 13.5, color: VOL.navy, fontStyle: 'italic' },
  meta: { fontSize: 12, color: VOL.muted },
  phone: { fontSize: 13.5, color: VOL.coral, fontWeight: '700' },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 6 },
  btn: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: 10 },
  btnAccept: { backgroundColor: '#16a34a' },
  btnDecline: { backgroundColor: '#fff', borderWidth: 1, borderColor: VOL.border },
  btnLabelLight: { color: '#fff', fontWeight: '800', fontSize: 13 },
  btnLabel: { color: VOL.navy, fontWeight: '700', fontSize: 13 },
  linkBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 6 },
  linkLabel: { color: VOL.coral, fontWeight: '700', fontSize: 13 },
  assign: { marginTop: 8, gap: 6 },
  input: { borderWidth: 1, borderColor: VOL.border, borderRadius: 10, padding: 10, fontSize: 14, color: VOL.navy, backgroundColor: '#fffdf8' },
  result: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 8, paddingHorizontal: 4, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: VOL.border },
  resultLabel: { fontSize: 14, color: VOL.navy },
  cancel: { fontSize: 13, color: VOL.muted, fontWeight: '600', marginTop: 4 },
});
