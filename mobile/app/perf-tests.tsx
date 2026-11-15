import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError, auth } from '@/api/client';
import { perfTests as perfTestsApi } from '@/api/resources';
import type {
  PerfTestDeclaration,
  PerfTestGroup,
  PerfTestInterval,
  PerfTestMineGroup,
  PerfTestResultRow,
  PerfTestSessionView,
  PerfTestsResponse,
} from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { PaceBadge } from '@/components/groups/GroupFields';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { DeclareTimeBox } from '@/components/perf/DeclareTimeBox';
import { SeasonRecap } from '@/components/perf/SeasonRecap';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { canSeeTraining } from '@/utils/profile';
import { fromIsoDate } from '@/utils/week';

/**
 * Tests chronométrés (onglet Entraînements) : pour une saison d'entraînement
 * (sept. → août), un sous-onglet par épreuve avec mes temps et ceux de tous
 * les adhérents séance par séance, un formulaire de rectification adressé
 * aux entraîneurs, et mon évolution sur toutes les saisons. Les temps sont
 * saisis par les entraîneurs côté backend.
 */
export default function PerfTestsScreen() {
  const { user } = useAuth();
  const canSee = canSeeTraining(user);

  const [season, setSeason] = useState<number | undefined>(undefined);
  // Onglet principal : mes temps, ou ceux du club.
  const [view, setView] = useState<'mine' | 'club'>('mine');
  // « Temps du club › Par séance » : épreuve choisie (clé de groupe) ; retombe sur la première disponible.
  const [tab, setTab] = useState<string | null>(null);
  // « Mes temps » : épreuve choisie (clé de groupe) ; retombe sur la première disponible.
  const [mineTab, setMineTab] = useState<string | null>(null);
  // « Temps du club » : récap de la saison par épreuve (allures du club) ou détail séance par séance.
  const [mode, setMode] = useState<'season' | 'session'>('season');
  const [data, setData] = useState<PerfTestsResponse | null>(null);
  // « Mon évolution » : mes temps sur toutes les saisons (indépendant de la saison affichée).
  const [mine, setMine] = useState<PerfTestMineGroup[]>([]);
  // Mes temps déclarés (prise de temps individuelle) et leur état de validation.
  const [declarations, setDeclarations] = useState<PerfTestDeclaration[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Séances dépliées (« groupe:séance ») : la plus récente de chaque épreuve par défaut.
  const [open, setOpen] = useState<Set<string>>(new Set());

  const load = useCallback(async (s: number | undefined) => {
    try {
      setError(null);
      // « Mon évolution » est secondaire : son échec ne doit pas masquer le classement.
      const [resp, mineResp, declResp] = await Promise.all([
        perfTestsApi.list(s),
        perfTestsApi.mine().catch(() => null),
        perfTestsApi.declarations().catch(() => null),
      ]);
      setData(resp);
      if (mineResp) setMine(mineResp.groups);
      if (declResp) setDeclarations(declResp.data);
      setOpen(new Set(resp.groups.flatMap((g) => (g.sessions[0] ? [`${g.key}:${g.sessions[0].id}`] : []))));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    if (!canSee) return;
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load(season);
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [canSee, season, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(season);
    setRefreshing(false);
  }, [load, season]);

  const reloadDeclarations = useCallback(async () => {
    try {
      setDeclarations((await perfTestsApi.declarations()).data);
    } catch {
      // Rafraîchissement de confort : on garde la liste affichée.
    }
  }, []);

  function toggle(key: string) {
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  if (!canSee) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Mes temps et ceux du club' }} />
        <EmptyState
          icon="🔒"
          title="Accès réservé"
          message="Cette page est réservée aux adhérents licenciés."
        />
      </SafeAreaView>
    );
  }

  const tabs = data ? buildTabs(data.groups) : [];
  const activeTab = tabs.find((t) => t.key === tab) ?? tabs[0];
  const activeGroup = data && activeTab ? data.groups.find((g) => g.key === activeTab.key) : undefined;
  // « Mes temps » : une épreuve (et un bassin en natation) par onglet, toutes saisons confondues.
  const mineTabs = buildMineTabs(mine);
  const activeMineTab = mineTabs.find((t) => t.key === mineTab) ?? mineTabs[0];
  const activeMine = activeMineTab ? mine.find((g) => g.key === activeMineTab.key) : undefined;
  // Récap : seulement les épreuves qui ont au moins un temps sur la saison affichée.
  const recaps = (data?.recaps ?? []).filter((r) => r.rows.length > 0);
  const showRecap = mode === 'season' && recaps.length > 0;
  // Allure du club de l'épreuve affichée (intervalles) et la mienne : sert à regrouper les temps d'une séance par allure
  // et à ouvrir mon allure. Sans temps de ma part, aucune allure n'est ouverte.
  const activeRecap = activeGroup ? recaps.find((r) => r.test === activeGroup.test) : undefined;

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Mes temps et ceux du club' }} />
      {loading ? (
        <FullScreenLoading />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={() => load(season)} />
      ) : (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        >
          {data && (
            <>
              <Text style={styles.seasonTitle}>Saison {seasonLabel(data)}</Text>
              {data.seasons.length > 1 && (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.seasons}>
                  {data.seasons.map((s) => {
                    const active = s.year === data.season;
                    return (
                      <Pressable
                        key={s.year}
                        onPress={() => setSeason(s.year)}
                        style={[styles.seasonChip, active && styles.seasonChipActive]}
                        accessibilityRole="button"
                        accessibilityState={{ selected: active }}
                      >
                        <Text style={[styles.seasonLabel, active && styles.seasonLabelActive]}>{s.label}</Text>
                      </Pressable>
                    );
                  })}
                </ScrollView>
              )}

              <ModeSwitch
                options={[['mine', '👤 Mes temps'], ['club', '👥 Temps du club']]}
                value={view}
                onChange={setView}
              />

              {view === 'mine' ? (
                !activeMineTab || !activeMine ? (
                  <>
                    <EmptyState
                      icon="⏱️"
                      title="Aucun temps enregistré"
                      message="Vos temps apparaîtront ici après une prise de temps des entraîneurs, ou une fois un temps déclaré accepté."
                    />
                    <DeclareTimeBox declarations={declarations} onChanged={reloadDeclarations} currentSeasonOnly />
                  </>
                ) : (
                  <>
                    {mineTabs.length > 1 && <EpreuveTabs tabs={mineTabs} activeKey={activeMineTab.key} onSelect={setMineTab} />}

                    <SeasonTimesCard group={activeMine} season={data.season} label={seasonLabel(data)} />

                    <Text style={styles.sectionTitle}>📈 Mon évolution</Text>
                    <EvolutionCard group={activeMine} />

                    <CorrectionBox
                      key={`${activeMineTab.key}:${data.season}`}
                      label={activeMineTab.label}
                      season={seasonLabel(data)}
                    />

                    <DeclareTimeBox
                      key={`declare:${activeMineTab.key}`}
                      target={{ test: activeMineTab.test, label: activeMineTab.label, pool: activeMineTab.pool }}
                      declarations={declarations.filter((d) => d.test === activeMineTab.test && d.poolLength === activeMineTab.pool)}
                      onChanged={reloadDeclarations}
                    />
                  </>
                )
              ) : (
                <>
                  {recaps.length > 0 && (
                    <ModeSwitch
                      options={[['season', '🏅 Récap de la saison'], ['session', '📋 Par séance']]}
                      value={mode}
                      onChange={setMode}
                    />
                  )}

                  {showRecap ? (
                    <SeasonRecap recaps={recaps} seasonLabel={seasonLabel(data)} />
                  ) : tabs.length === 0 || !activeTab || !activeGroup ? (
                    <EmptyState
                      icon="⏱️"
                      title={`Aucun test chronométré sur la saison ${seasonLabel(data)}`}
                      message="Les temps saisis par les entraîneurs apparaîtront ici."
                    />
                  ) : (
                    <>
                      {tabs.length > 1 && <EpreuveTabs tabs={tabs} activeKey={activeTab.key} onSelect={setTab} />}

                      <GroupCard
                        key={activeGroup.key}
                        group={activeGroup}
                        open={open}
                        onToggle={toggle}
                        intervals={activeRecap?.intervals ?? null}
                        myLevel={activeRecap?.mine?.level ?? null}
                      />
                    </>
                  )}
                </>
              )}
            </>
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

/** Deux boutons (ou plus) exclusifs en haut d'un écran : « Mes temps / Temps du club », « Récap / Par séance ». */
function ModeSwitch<T extends string>({ options, value, onChange }: {
  options: readonly (readonly [T, string])[];
  value: T;
  onChange: (next: T) => void;
}) {
  return (
    <View style={styles.modeSwitch} accessibilityRole="tablist">
      {options.map(([key, label]) => {
        const active = value === key;
        return (
          <Pressable
            key={key}
            onPress={() => onChange(key)}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            style={[styles.modeBtn, active && styles.modeBtnActive]}
          >
            <Text style={[styles.modeLabel, active && styles.modeLabelActive]}>{label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Onglets d'épreuves (course / nage / vélo ; en natation un onglet par bassin). */
function EpreuveTabs({ tabs, activeKey, onSelect }: { tabs: PerfTab[]; activeKey: string; onSelect: (key: string) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
      {tabs.map((t) => {
        const active = t.key === activeKey;
        return (
          <Pressable
            key={t.key}
            onPress={() => onSelect(t.key)}
            style={[styles.tabChip, active && styles.tabChipActive]}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
          >
            <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{t.icon} {t.short}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

/** Mes temps de la saison affichée sur une épreuve : meilleur temps, puis chaque prise de temps (rang et écart). */
function SeasonTimesCard({ group, season, label }: { group: PerfTestMineGroup; season: number; label: string }) {
  const results = group.results.filter((r) => r.season === season).reverse(); // du plus récent au plus ancien
  const best = results.length > 0 ? results.reduce((a, b) => (b.timeSeconds < a.timeSeconds ? b : a)) : null;

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{group.icon} {group.label}</Text>
      <Text style={styles.sectionTitle}>Saison {label}</Text>

      {best === null ? (
        <Text style={styles.noMine}>Vous n'avez pas de temps enregistré sur cette épreuve cette saison.</Text>
      ) : (
        <>
          <View style={styles.mineBox}>
            <Text style={styles.mineLabel}>Mon meilleur temps de la saison</Text>
            <Text style={styles.mineTime}>{best.time}</Text>
            <Text style={styles.mineMeta}>
              prise de temps {best.endDate ? '' : 'du '}{formatPeriod(best.date, best.endDate)} · {results.length} test{results.length > 1 ? 's' : ''} cette saison
            </Text>
          </View>

          {results.map((r) => (
            <View key={r.sessionId} style={styles.evoRow}>
              <View style={{ flex: 1 }}>
                <Text style={styles.evoDate}>{formatPeriod(r.date, r.endDate)}</Text>
                <Text style={styles.evoMeta}>{rankLabel(r.rank)} sur {r.participants}</Text>
              </View>
              <Text style={[styles.evoTime, r.isBest && styles.evoTimeBest]}>{r.time}</Text>
              <Text
                style={[
                  styles.evoDelta,
                  r.deltaSeconds !== null && r.deltaSeconds < 0 && styles.evoDeltaBetter,
                  r.deltaSeconds !== null && r.deltaSeconds > 0 && styles.evoDeltaWorse,
                ]}
              >
                {formatDelta(r.deltaSeconds)}
              </Text>
            </View>
          ))}
        </>
      )}
    </View>
  );
}

type PerfTab = { key: string; label: string; short: string; icon: string; test: string; pool: number | null };

const TEST_ORDER = ['run_1500', 'run_2000', 'swim_400', 'bike_climb_2k'];

/**
 * Sous-onglets : une épreuve (et un bassin en natation) par onglet, seulement
 * celles qui ont eu au moins une prise de temps sur la saison affichée, dans
 * l'ordre course / nage / vélo.
 */
function buildTabs(groups: PerfTestGroup[]): PerfTab[] {
  const byKey = new Map<string, PerfTab>();
  for (const g of groups) {
    if (g.sessions.length > 0 && !byKey.has(g.key)) {
      byKey.set(g.key, {
        key: g.key,
        label: g.label,
        short: g.shortLabel ?? g.label,
        icon: g.icon,
        test: g.test,
        pool: g.poolLength,
      });
    }
  }
  return sortTabs(Array.from(byKey.values()));
}

/** Ordre course / nage / vélo, puis bassin croissant. */
function sortTabs(tabs: PerfTab[]): PerfTab[] {
  const rank = (t: PerfTab) => {
    const i = TEST_ORDER.indexOf(t.test);
    return i === -1 ? TEST_ORDER.length : i;
  };
  return [...tabs].sort((a, b) => rank(a) - rank(b) || (a.pool ?? 0) - (b.pool ?? 0));
}

/** Onglets de « Mes temps » : les épreuves où j'ai au moins un temps (toutes saisons), dans le même ordre. */
function buildMineTabs(groups: PerfTestMineGroup[]): PerfTab[] {
  return sortTabs(
    groups
      .filter((g) => g.results.length > 0)
      .map((g) => ({ key: g.key, label: g.label, short: g.shortLabel ?? g.label, icon: g.icon, test: g.test, pool: g.poolLength })),
  );
}

/**
 * Rectification : l'adhérent décrit l'erreur dans une zone de texte ; le
 * message part vers TOUS les entraîneurs (messagerie de l'appli + e-mail),
 * avec l'épreuve et la saison en objet.
 */
function CorrectionBox({ label, season }: { label: string; season: string }) {
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send() {
    const body = text.trim();
    if (!body || sending) return;
    setSending(true);
    setError(null);
    try {
      await auth.sendMessage({
        scope: 'all_trainers',
        subject: `Rectification de temps — ${label} · saison ${season}`.slice(0, 200),
        body: `Épreuve : ${label}\nSaison : ${season}\n\n${body}`,
      });
      setSent(true);
      setOpen(false);
      setText('');
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Envoi impossible. Réessayez plus tard.');
    } finally {
      setSending(false);
    }
  }

  if (sent) {
    return (
      <View style={styles.sentBox}>
        <Text style={styles.sentLabel}>✅ Message envoyé aux entraîneurs. Merci !</Text>
        <Pressable onPress={() => setSent(false)} hitSlop={8}>
          <Text style={styles.correctionLink}>Envoyer un autre message</Text>
        </Pressable>
      </View>
    );
  }

  if (!open) {
    return (
      <Pressable
        onPress={() => setOpen(true)}
        style={({ pressed }) => [styles.correctionLinkBox, pressed && { opacity: 0.7 }]}
      >
        <Ionicons name="create-outline" size={18} color={COLORS.secondaryDark} />
        <Text style={[styles.correctionLink, { flex: 1 }]}>Un temps est incorrect ? Signaler une rectification</Text>
        <Ionicons name="chevron-forward" size={16} color={COLORS.secondaryDark} />
      </Pressable>
    );
  }

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>✏️ Rectification — {label}</Text>
      <Text style={styles.correctionHint}>
        Décrivez l'erreur (séance concernée, temps attendu…). Votre message est envoyé aux entraîneurs.
      </Text>
      <TextInput
        value={text}
        onChangeText={setText}
        placeholder="Ex : mon temps du 12 mars est 5:38 et non 5:48"
        placeholderTextColor={COLORS.textSubtle}
        multiline
        maxLength={2000}
        editable={!sending}
        style={styles.correctionInput}
      />
      {error && <Text style={styles.correctionError}>{error}</Text>}
      <View style={styles.correctionActions}>
        <Pressable
          onPress={() => { setOpen(false); setError(null); }}
          disabled={sending}
          style={({ pressed }) => [styles.cancelBtn, pressed && { opacity: 0.7 }]}
        >
          <Text style={styles.cancelBtnLabel}>Annuler</Text>
        </Pressable>
        <Pressable
          onPress={send}
          disabled={sending || text.trim() === ''}
          style={({ pressed }) => [
            styles.sendBtn,
            (sending || text.trim() === '') && { opacity: 0.5 },
            pressed && { opacity: 0.8 },
          ]}
        >
          {sending ? <ActivityIndicator color="#fff" /> : <Text style={styles.sendBtnLabel}>Envoyer aux entraîneurs</Text>}
        </Pressable>
      </View>
    </View>
  );
}

/** Mes temps sur une épreuve, saison après saison : record, mini-graphique, liste avec écarts. */
function EvolutionCard({ group }: { group: PerfTestMineGroup }) {
  const [showAll, setShowAll] = useState(false);
  const results = group.results; // du plus ancien au plus récent
  const newestFirst = [...results].reverse();
  const visible = showAll ? newestFirst : newestFirst.slice(0, 4);

  const chart = results.slice(-8);
  const times = chart.map((r) => r.timeSeconds);
  const min = Math.min(...times);
  const max = Math.max(...times);

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{group.icon} {group.label}</Text>

      <View style={styles.mineBox}>
        <Text style={styles.mineLabel}>🏅 Mon record</Text>
        <Text style={styles.mineTime}>{group.best.time}</Text>
        <Text style={styles.mineMeta}>
          saison {group.best.seasonLabel} · {results.length} test{results.length > 1 ? 's' : ''} au total
        </Text>
      </View>

      {chart.length > 1 && (
        <View>
          <View style={styles.chart}>
            {chart.map((r) => {
              const height = 14 + (max === min ? 18 : ((r.timeSeconds - min) / (max - min)) * 46);
              return (
                <View key={r.sessionId} style={styles.chartCol}>
                  <Text style={styles.chartTime} numberOfLines={1}>{r.time}</Text>
                  <View style={[styles.chartBar, { height }, r.isBest && styles.chartBarBest]} />
                  <Text style={styles.chartSeason}>{shortSeason(r.seasonLabel)}</Text>
                </View>
              );
            })}
          </View>
          <Text style={styles.chartHint}>Barre plus courte = temps plus rapide · en vert : mon record</Text>
        </View>
      )}

      {visible.map((r) => (
        <View key={r.sessionId} style={styles.evoRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.evoDate}>{formatPeriod(r.date, r.endDate)}</Text>
            <Text style={styles.evoMeta}>
              {rankLabel(r.rank)} sur {r.participants} · saison {r.seasonLabel}
            </Text>
          </View>
          <Text style={[styles.evoTime, r.isBest && styles.evoTimeBest]}>{r.time}</Text>
          <Text
            style={[
              styles.evoDelta,
              r.deltaSeconds !== null && r.deltaSeconds < 0 && styles.evoDeltaBetter,
              r.deltaSeconds !== null && r.deltaSeconds > 0 && styles.evoDeltaWorse,
            ]}
          >
            {formatDelta(r.deltaSeconds)}
          </Text>
        </View>
      ))}

      {newestFirst.length > 4 && (
        <Pressable onPress={() => setShowAll((v) => !v)} style={({ pressed }) => [styles.showAll, pressed && { opacity: 0.7 }]}>
          <Text style={styles.showAllLabel}>
            {showAll ? 'Réduire' : `Voir tous mes temps (${newestFirst.length})`}
          </Text>
        </Pressable>
      )}
    </View>
  );
}

function GroupCard({
  group, open, onToggle, intervals, myLevel,
}: {
  group: PerfTestGroup;
  open: Set<string>;
  onToggle: (key: string) => void;
  /** Allures de l'épreuve (null = indisponibles : liste à plat) et la mienne (null = pas d'allure : tout reste fermé). */
  intervals: PerfTestInterval[] | null;
  myLevel: number | null;
}) {
  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{group.icon} {group.label}</Text>

      {group.mine ? (
        <View style={styles.mineBox}>
          <Text style={styles.mineLabel}>Mon meilleur temps</Text>
          <Text style={styles.mineTime}>{group.mine.best.time}</Text>
          <Text style={styles.mineMeta}>
            prise de temps {group.mine.best.endDate ? '' : 'du '}{formatPeriod(group.mine.best.date, group.mine.best.endDate)} · {group.mine.count} test{group.mine.count > 1 ? 's' : ''} cette saison
          </Text>
        </View>
      ) : (
        <Text style={styles.noMine}>Vous n'avez pas de temps enregistré sur cette épreuve cette saison.</Text>
      )}

      {group.sessions.map((s) => {
        const key = `${group.key}:${s.id}`;
        return <SessionBlock key={key} session={s} isOpen={open.has(key)} onToggle={() => onToggle(key)} intervals={intervals} myLevel={myLevel} />;
      })}
    </View>
  );
}

function SessionBlock({
  session, isOpen, onToggle, intervals, myLevel,
}: {
  session: PerfTestSessionView;
  isOpen: boolean;
  onToggle: () => void;
  intervals: PerfTestInterval[] | null;
  myLevel: number | null;
}) {
  const mine = session.results.find((r) => r.mine);
  return (
    <View style={styles.session}>
      <Pressable onPress={onToggle} style={({ pressed }) => [styles.sessionHeader, pressed && { opacity: 0.7 }]}>
        <View style={{ flex: 1 }}>
          <Text style={styles.sessionDate}>{formatPeriod(session.date, session.endDate)}</Text>
          <Text style={styles.sessionMeta} numberOfLines={2}>
            {session.participants} participant{session.participants > 1 ? 's' : ''}
            {session.notes ? ` · ${session.notes}` : ''}
          </Text>
          {mine && (
            <Text style={styles.sessionMine}>
              Mon temps : {mine.time} · {rankLabel(mine.rank)} sur {session.participants}
            </Text>
          )}
        </View>
        <Ionicons name={isOpen ? 'chevron-up' : 'chevron-down'} size={18} color={COLORS.textMuted} />
      </Pressable>

      {isOpen && <SessionResults session={session} intervals={intervals} myLevel={myLevel} />}
    </View>
  );
}

/** Une ligne de classement d'une séance. */
function ResultLine({ r }: { r: PerfTestResultRow }) {
  return (
    <View style={[styles.row, r.mine && styles.rowMine]}>
      <Text style={[styles.rank, r.mine && styles.rowMineText]}>{r.rank}</Text>
      <Text style={[styles.name, r.mine && styles.rowMineText]} numberOfLines={1}>
        {r.fullName}{r.mine ? ' (moi)' : ''}
      </Text>
      <Text style={[styles.time, r.mine && styles.rowMineText]}>{r.time}</Text>
    </View>
  );
}

/**
 * Temps d'une séance regroupés par allure du club (1 à 5), un accordéon par allure : celui de MON allure est ouvert, avec
 * les autres adhérents de la même allure (et celui où je me trouve dans cette séance, s'il diffère). Sans allure de ma
 * part, tout reste fermé. Si le serveur ne fournit pas les allures, liste à plat classée par temps.
 */
function SessionResults({ session, intervals, myLevel }: {
  session: PerfTestSessionView;
  intervals: PerfTestInterval[] | null;
  myLevel: number | null;
}) {
  const grouped = intervals !== null && session.results.some((r) => typeof r.level === 'number');
  const myRowLevel = session.results.find((r) => r.mine)?.level ?? null;
  const [openLevels, setOpenLevels] = useState<Set<number>>(
    () => new Set([myLevel, myRowLevel].filter((l): l is number => typeof l === 'number')),
  );

  if (!grouped || intervals === null) {
    return (
      <View>
        {session.results.map((r, i) => <ResultLine key={r.userId ?? `ancien-${i}`} r={r} />)}
      </View>
    );
  }

  function toggleLevel(level: number) {
    setOpenLevels((prev) => {
      const next = new Set(prev);
      if (next.has(level)) next.delete(level);
      else next.add(level);
      return next;
    });
  }

  return (
    <View>
      {intervals.map((iv) => {
        const rows = session.results.filter((r) => r.level === iv.level);
        if (rows.length === 0) return null;
        const expanded = openLevels.has(iv.level);
        const mineHere = rows.some((r) => r.mine) || myLevel === iv.level;
        return (
          <View key={iv.level} style={styles.levelBlock}>
            <Pressable
              onPress={() => toggleLevel(iv.level)}
              accessibilityRole="button"
              accessibilityState={{ expanded }}
              style={({ pressed }) => [styles.levelHead, mineHere && styles.levelHeadMine, pressed && { opacity: 0.8 }]}
            >
              <PaceBadge level={iv.level} speed={null} />
              <Text style={styles.levelInterval}>{iv.label}</Text>
              <Text style={styles.levelCount}>{rows.length} adhérent{rows.length > 1 ? 's' : ''}</Text>
              <Ionicons name={expanded ? 'chevron-up' : 'chevron-down'} size={18} color={COLORS.textMuted} />
            </Pressable>
            {expanded && rows.map((r, i) => <ResultLine key={r.userId ?? `ancien-${i}`} r={r} />)}
          </View>
        );
      })}
    </View>
  );
}

/** « 2025-2026 » → « 25-26 » (légende du graphique). */
function shortSeason(label: string): string {
  return label.replace(/^\d{2}(\d{2})-\d{2}(\d{2})$/, '$1-$2');
}

function formatSeconds(total: number): string {
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/** Écart avec le test précédent : « −12 s », « +1:05 », « = », « — » pour le premier test. */
function formatDelta(delta: number | null): string {
  if (delta === null) return '—';
  if (delta === 0) return '=';
  const abs = Math.abs(delta);
  return `${delta < 0 ? '−' : '+'}${abs < 60 ? `${abs} s` : formatSeconds(abs)}`;
}

function seasonLabel(data: PerfTestsResponse): string {
  return data.seasons.find((s) => s.year === data.season)?.label ?? String(data.season);
}

/**
 * Période d'une prise de temps, en français : « 12 mars 2026 » (un jour),
 * « du 12 au 14 mars 2026 », « du 28 février au 2 mars 2026 », « du 28 décembre
 * 2025 au 2 janvier 2026 » (mois et année donnés une seule fois si communs).
 */
function formatPeriod(startIso: string, endIso: string | null): string {
  const start = fromIsoDate(startIso);
  const day = (d: Date) => (d.getDate() === 1 ? '1er' : String(d.getDate()));
  const month = (d: Date) => d.toLocaleDateString('fr-FR', { month: 'long' });
  if (!endIso || endIso === startIso) return `${day(start)} ${month(start)} ${start.getFullYear()}`;

  const end = fromIsoDate(endIso);
  if (start.getFullYear() !== end.getFullYear()) {
    return `du ${day(start)} ${month(start)} ${start.getFullYear()} au ${day(end)} ${month(end)} ${end.getFullYear()}`;
  }
  if (start.getMonth() !== end.getMonth()) {
    return `du ${day(start)} ${month(start)} au ${day(end)} ${month(end)} ${end.getFullYear()}`;
  }
  return `du ${day(start)} au ${day(end)} ${month(end)} ${end.getFullYear()}`;
}

function rankLabel(rank: number): string {
  return rank === 1 ? '1er' : `${rank}e`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, gap: SPACING.md },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  chart: { flexDirection: 'row', alignItems: 'flex-end', gap: 6, paddingTop: 4 },
  chartCol: { flex: 1, alignItems: 'center', justifyContent: 'flex-end', gap: 2 },
  chartTime: { fontSize: 10, color: COLORS.textMuted, fontVariant: ['tabular-nums'] },
  chartBar: { width: '70%', borderRadius: 4, backgroundColor: COLORS.secondary },
  chartBarBest: { backgroundColor: COLORS.success },
  chartSeason: { fontSize: 10, color: COLORS.textSubtle },
  chartHint: { fontSize: 11, color: COLORS.textSubtle, marginTop: 6 },
  evoRow: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border,
  },
  evoDate: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  evoMeta: { fontSize: 12, color: COLORS.textMuted },
  evoTime: { fontSize: 16, fontWeight: '700', color: COLORS.text, fontVariant: ['tabular-nums'] },
  evoTimeBest: { color: COLORS.success },
  evoDelta: { width: 62, textAlign: 'right', fontSize: 13, fontWeight: '600', color: COLORS.textMuted, fontVariant: ['tabular-nums'] },
  evoDeltaBetter: { color: COLORS.success },
  evoDeltaWorse: { color: COLORS.error },
  showAll: { alignItems: 'center', paddingVertical: 8 },
  showAllLabel: { fontSize: 14, fontWeight: '600', color: COLORS.secondaryDark },
  tabs: { gap: SPACING.sm, paddingVertical: 2 },
  tabChip: {
    paddingHorizontal: 14, paddingVertical: 9,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  tabChipActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  tabLabel: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  tabLabelActive: { color: '#fff' },
  correctionLinkBox: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: COLORS.secondarySoft,
  },
  correctionLink: { fontSize: 14, fontWeight: '600', color: COLORS.secondaryDark },
  correctionHint: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  correctionInput: {
    minHeight: 100, textAlignVertical: 'top',
    borderWidth: 1, borderColor: COLORS.border, borderRadius: RADIUS.md,
    padding: SPACING.md, fontSize: 15, color: COLORS.text, backgroundColor: COLORS.surfaceAlt,
  },
  correctionError: { fontSize: 13, color: COLORS.error },
  correctionActions: { flexDirection: 'row', gap: SPACING.sm, justifyContent: 'flex-end' },
  cancelBtn: { paddingHorizontal: 14, paddingVertical: 11, borderRadius: RADIUS.md },
  cancelBtnLabel: { fontSize: 14, fontWeight: '600', color: COLORS.textMuted },
  sendBtn: {
    paddingHorizontal: 16, paddingVertical: 11, borderRadius: RADIUS.md,
    backgroundColor: COLORS.primary, minWidth: 150, alignItems: 'center',
  },
  sendBtnLabel: { fontSize: 14, fontWeight: '700', color: '#fff' },
  sentBox: {
    padding: SPACING.md, borderRadius: RADIUS.md, backgroundColor: '#d1fae5',
    gap: SPACING.xs,
  },
  sentLabel: { fontSize: 14, fontWeight: '600', color: '#065f46' },
  levelBlock: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border },
  levelHead: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: SPACING.md, paddingVertical: 10 },
  levelHeadMine: { backgroundColor: COLORS.secondarySoft },
  levelInterval: { flex: 1, fontSize: 13, fontWeight: '600', color: COLORS.text },
  levelCount: { fontSize: 12, color: COLORS.textMuted },
  modeSwitch: { flexDirection: 'row', gap: 6, backgroundColor: COLORS.border, borderRadius: RADIUS.md, padding: 3 },
  modeBtn: { flex: 1, paddingVertical: 9, borderRadius: RADIUS.md - 2, alignItems: 'center' },
  modeBtnActive: { backgroundColor: COLORS.surface },
  modeLabel: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted },
  modeLabelActive: { color: COLORS.text },
  seasonTitle: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  seasons: { gap: SPACING.sm, paddingVertical: 2 },
  seasonChip: {
    paddingHorizontal: 16, paddingVertical: 8,
    borderRadius: RADIUS.full, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  seasonChipActive: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  seasonLabel: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  seasonLabelActive: { color: '#fff' },
  card: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    padding: SPACING.lg, gap: SPACING.md,
    ...SHADOWS.sm,
  },
  cardTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  mineBox: {
    backgroundColor: COLORS.secondarySoft, borderRadius: RADIUS.md,
    padding: SPACING.md, gap: 2,
  },
  mineLabel: { fontSize: 12, fontWeight: '700', color: COLORS.secondaryDark, textTransform: 'uppercase', letterSpacing: 0.5 },
  mineTime: { fontSize: 30, fontWeight: '800', color: COLORS.secondaryDark },
  mineMeta: { fontSize: 13, color: COLORS.secondaryDark },
  noMine: { fontSize: 14, color: COLORS.textMuted, fontStyle: 'italic' },
  session: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border, paddingTop: SPACING.sm },
  sessionHeader: { flexDirection: 'row', alignItems: 'center', gap: SPACING.sm, paddingVertical: 4 },
  sessionDate: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sessionMeta: { fontSize: 13, color: COLORS.textMuted },
  sessionMine: { fontSize: 13, fontWeight: '600', color: COLORS.secondaryDark, marginTop: 2 },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    paddingVertical: 7, paddingHorizontal: 6, borderRadius: RADIUS.sm,
  },
  rowMine: { backgroundColor: COLORS.secondarySoft },
  rowMineText: { fontWeight: '700', color: COLORS.secondaryDark },
  rank: { width: 28, fontSize: 14, color: COLORS.textMuted, textAlign: 'right' },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  time: { fontSize: 15, fontWeight: '600', color: COLORS.text, fontVariant: ['tabular-nums'] },
});
