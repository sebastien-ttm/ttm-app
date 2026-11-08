import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { volunteer as volunteerApi } from '@/api/resources';
import type { VolunteerCampaign, VolunteerRoleSummary } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { HoldersBadge, POLE_COLORS, StatusChip, VOL, formatDay, formatDays } from '@/components/volunteer/ui';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

/**
 * Bénévolat d'événement (Breathman…) : page d'accueil de la campagne en cours.
 * Partie 1 : les pôles et leurs activités (référent·es, engagement en amont).
 * Partie 2 : les responsables terrain (encadrement d'une équipe les jours J).
 * Chaque fiche s'ouvre en détail, où l'adhérent se positionne.
 */
export default function VolunteerHomeScreen() {
  const router = useRouter();
  const [campaign, setCampaign] = useState<VolunteerCampaign | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await volunteerApi.current();
      setCampaign(resp.campaign);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  if (loading) return <FullScreenLoading />;
  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!campaign) {
    return (
      <>
        <Stack.Screen options={{ title: 'Bénévolat' }} />
        <EmptyState icon="🙌" title="Aucun appel à bénévoles en cours" message="Les fiches de mission apparaîtront ici." />
      </>
    );
  }

  const open = (r: VolunteerRoleSummary) => router.push(('/volunteer/role/' + r.id) as never);

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: campaign.name }} />
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
      >
        <View style={styles.hero}>
          <Text style={styles.heroPill}>{campaign.name} · Équipe d'organisation</Text>
          <Text style={styles.heroTitle}>Rejoins l'équipe orga</Text>
          <Text style={styles.heroSub}>
            {formatDays(campaign.startsOn, campaign.endsOn)}{campaign.location ? ' · ' + campaign.location : ''}
          </Text>
          {campaign.intro ? <Text style={styles.heroIntro}>{campaign.intro}</Text> : null}
          <View style={styles.stats}>
            <Stat value={campaign.stats.poles} label="pôles" />
            <Stat value={campaign.stats.activities} label="référent·es" />
            <Stat value={campaign.stats.fieldRoles} label="responsables terrain" />
            <Stat value={campaign.stats.tasks} label="tâches réparties" />
          </View>
          {campaign.applicationsDeadline ? (
            <Text style={styles.heroDeadline}>Réponds avant le {formatDay(campaign.applicationsDeadline)}</Text>
          ) : null}
          {!campaign.applicationsOpen ? <Text style={styles.heroDeadline}>Les candidatures sont closes.</Text> : null}
        </View>

        <View style={styles.actions}>
          <Pressable style={styles.actionBtn} onPress={() => router.push('/volunteer/mine' as never)}>
            <Ionicons name="person-circle-outline" size={18} color={VOL.navy} />
            <Text style={styles.actionLabel}>
              Mes candidatures{campaign.myApplicationCount > 0 ? ' · ' + campaign.myApplicationCount : ''}
            </Text>
          </Pressable>
          {campaign.isManager && (
            <Pressable style={[styles.actionBtn, styles.actionBtnManager]} onPress={() => router.push('/volunteer/manage' as never)}>
              <Ionicons name="checkmark-done-outline" size={18} color="#fff" />
              <Text style={[styles.actionLabel, { color: '#fff' }]}>
                Candidatures à traiter{campaign.pendingToReview > 0 ? ' · ' + campaign.pendingToReview : ''}
              </Text>
            </Pressable>
          )}
        </View>

        <View style={styles.card}>
          <Text style={styles.cardTitle}>Référent·e ou responsable terrain : quelle différence ?</Text>
          <View style={styles.two}>
            <View style={styles.twoCol}>
              <Text style={styles.twoTitle}><Text style={{ color: VOL.teal }}>●</Text> Référent·e d'activité</Text>
              <Text style={styles.twoText}>Tu prends en charge une activité d'un pôle, de la préparation jusqu'au jour J.</Text>
              <Bullet text="Engagement sur plusieurs mois, surtout depuis chez toi" />
              <Bullet text="Tu prépares, tu décides et tu rends compte aux responsables de ton pôle" />
              <Bullet text="Le week-end, tu pilotes ton activité" />
            </View>
            <View style={styles.twoCol}>
              <Text style={styles.twoTitle}><Text style={{ color: VOL.coral }}>●</Text> Responsable terrain</Text>
              <Text style={styles.twoText}>Tu encadres une équipe de bénévoles sur un poste précis, les derniers jours.</Text>
              <Bullet text="Engagement court : briefing vers J-7, installation la veille, poste tenu le week-end" />
              <Bullet text="Tu appliques le plan préparé par le ou la référent·e de l'activité" />
              <Bullet text="Tu rends compte à ce ou cette référent·e" />
            </View>
          </View>
        </View>

        <PartHeader
          index={1}
          title="Les pôles et leurs référent·es"
          text={
            'Un pôle regroupe tout un domaine de l\'organisation (les courses, la sécurité, le site, les bénévoles…). '
            + 'Il est piloté par deux responsables de pôle. Une activité est une mission précise au sein d\'un pôle, '
            + 'comme le chronométrage ou la buvette. Elle est confiée à un·e ou plusieurs référent·es. '
            + `${campaign.stats.activities} activités réparties en ${campaign.stats.poles} pôles.`
          }
        />
        {campaign.poles.map((pole) => (
          <View key={pole.id} style={styles.pole}>
            <View style={styles.poleHeader}>
              <View style={[styles.poleDot, { backgroundColor: POLE_COLORS[pole.color] }]} />
              <View style={{ flex: 1 }}>
                <Text style={styles.poleName}>Pôle {pole.name}</Text>
                {pole.managerNames ? <Text style={styles.poleManagers}>Responsables de pôle : {pole.managerNames}</Text> : null}
              </View>
            </View>
            {pole.activities.map((r) => (
              <RoleTile key={r.id} role={r} kicker="Activité" meta={(r.title ?? '') + ' · ' + r.taskCount + ' tâches'} onPress={() => open(r)} />
            ))}
          </View>
        ))}

        {campaign.fieldRoles.length > 0 && (
          <>
            <PartHeader
              index={2}
              title="Les responsables terrain"
              text={`${campaign.stats.fieldRoles} postes d'encadrement pendant les jours J, chacun rattaché à une activité et à son ou sa référent·e.`}
            />
            <View style={styles.pole}>
              <View style={styles.poleHeader}>
                <View style={[styles.poleDot, { backgroundColor: VOL.coral }]} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.poleName}>Responsables terrain · Jours J</Text>
                  <Text style={styles.poleManagers}>Briefing vers J-7, installation la veille, poste tenu le week-end</Text>
                </View>
              </View>
              {campaign.fieldRoles.map((r) => (
                <RoleTile
                  key={r.id}
                  role={r}
                  kicker={'Activité ' + (r.activityName ?? r.poleName)}
                  meta={r.requiredCount ? `${r.requiredCount} poste${r.requiredCount > 1 ? 's' : ''} requis` : 'Nombre requis à définir'}
                  onPress={() => open(r)}
                />
              ))}
            </View>
          </>
        )}
      </ScrollView>
    </View>
  );
}

function Stat({ value, label }: { value: number; label: string }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

function Bullet({ text }: { text: string }) {
  return <Text style={styles.bullet}>•  {text}</Text>;
}

function PartHeader({ index, title, text }: { index: number; title: string; text: string }) {
  return (
    <View style={styles.part}>
      <Text style={styles.partTitle}><Text style={{ color: VOL.coral }}>{index}</Text> · {title}</Text>
      <Text style={styles.partText}>{text}</Text>
    </View>
  );
}

function RoleTile({ role, kicker, meta, onPress }: { role: VolunteerRoleSummary; kicker: string; meta: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.tile, pressed && { opacity: 0.8 }]}>
      <View style={{ flex: 1 }}>
        <Text style={styles.tileKicker}>{kicker}</Text>
        <Text style={styles.tileName}>{role.name}</Text>
        <Text style={styles.tileMeta}>{meta}</Text>
        <HoldersBadge role={role} />
        {role.myStatus ? <View style={{ marginTop: 6 }}><StatusChip status={role.myStatus} prefix="Ma candidature : " /></View> : null}
      </View>
      <Ionicons name="chevron-forward" size={18} color={VOL.muted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: VOL.cream },
  content: { padding: 14, gap: 12, maxWidth: 760, width: '100%', alignSelf: 'center', paddingBottom: 40 },
  hero: { backgroundColor: VOL.navy, borderRadius: 16, padding: 18 },
  heroPill: { alignSelf: 'flex-start', backgroundColor: VOL.coral, color: '#fff', fontSize: 11, fontWeight: '700', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, overflow: 'hidden' },
  heroTitle: { color: '#fff', fontSize: 28, fontWeight: '800', marginTop: 10 },
  heroSub: { color: VOL.sun, fontSize: 14, fontWeight: '600', marginTop: 4 },
  heroIntro: { color: VOL.cream, fontSize: 14, lineHeight: 20, marginTop: 10 },
  heroDeadline: { color: VOL.sun, fontSize: 13, fontWeight: '700', marginTop: 12 },
  stats: { flexDirection: 'row', flexWrap: 'wrap', gap: 18, marginTop: 14 },
  stat: { minWidth: 70 },
  statValue: { color: '#fff', fontSize: 28, fontWeight: '800' },
  statLabel: { color: VOL.cream, fontSize: 12 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  actionBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: '#fff', borderWidth: 1, borderColor: VOL.border, paddingHorizontal: 12, paddingVertical: 10, borderRadius: 10 },
  actionBtnManager: { backgroundColor: VOL.navy, borderColor: VOL.navy },
  actionLabel: { fontSize: 13, fontWeight: '700', color: VOL.navy },
  card: { backgroundColor: '#fff', borderRadius: 14, padding: 14 },
  cardTitle: { fontSize: 16, fontWeight: '800', color: VOL.navy, marginBottom: 10 },
  two: { gap: 10 },
  twoCol: { backgroundColor: VOL.cream, borderRadius: 10, padding: 12 },
  twoTitle: { fontSize: 15, fontWeight: '800', color: VOL.navy, marginBottom: 4 },
  twoText: { fontSize: 13.5, color: VOL.navy, marginBottom: 4 },
  bullet: { fontSize: 13, color: VOL.navy, marginTop: 2 },
  part: { borderTopWidth: 3, borderTopColor: VOL.navy, paddingTop: 12, marginTop: 8 },
  partTitle: { fontSize: 22, fontWeight: '800', color: VOL.navy },
  partText: { fontSize: 13.5, color: VOL.navy, marginTop: 6, lineHeight: 19 },
  pole: { backgroundColor: '#fff', borderRadius: 14, padding: 12, gap: 8 },
  poleHeader: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 2 },
  poleDot: { width: 14, height: 14, borderRadius: 7, borderWidth: 2, borderColor: VOL.navy },
  poleName: { fontSize: 17, fontWeight: '800', color: VOL.navy },
  poleManagers: { fontSize: 12, color: VOL.muted, fontWeight: '600' },
  tile: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: VOL.border, borderRadius: 10, padding: 10, backgroundColor: '#fffdf8' },
  tileKicker: { fontSize: 10.5, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: VOL.muted },
  tileName: { fontSize: 15, fontWeight: '800', color: VOL.navy, marginTop: 1 },
  tileMeta: { fontSize: 12, color: VOL.muted, marginTop: 2 },
});
