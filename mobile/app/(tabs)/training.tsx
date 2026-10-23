import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import { trainingSchedule as scheduleApi } from '@/api/resources';
import type { TrainingPlan, TrainingRegistration, TrainingSlot, WeeklySchedule } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MyGroupsCard } from '@/components/groups/MyGroupsCard';
import { MyAllureCard } from '@/components/perf/MyAllureCard';
import { SportBadge } from '@/components/SportBadge';
import { TrainingRegistrationBar } from '@/components/TrainingRegistrationBar';
import { WeekNavigator } from '@/components/WeekNavigator';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { canSeeGouter, canSeePoolBadge, canSeeTraining, canSeeTrainingPlans, canSeeTrainingTab } from '@/utils/profile';
import { addDays, dayLabel, formatDurationHm, fromIsoDate, getMonday, shortDayLabel, toIsoDate } from '@/utils/week';
import { formatDate } from '@/utils/html';

export default function TrainingScreen() {
  const { user } = useAuth();
  // Garde-fou : élargi aux parents/jeunes non-licenciés — ils peuvent
  // n'avoir que la section Goûter à afficher. Un deep link d'un profil
  // sans aucun accès (ni entraînement, ni goûter) retombe sur le feed.
  if (!canSeeTrainingTab(user)) {
    return <Redirect href="/(tabs)" />;
  }
  return <TrainingScreenInner />;
}

function TrainingScreenInner() {
  const router = useRouter();
  const { user } = useAuth();
  const showPool = canSeePoolBadge(user);
  const showGouter = canSeeGouter(user);
  // Vue « entraînement » = plans + créneaux + piscine + staff. Un parent
  // non-licencié qui n'a QUE le goûter à voir n'a pas ce bloc-là.
  const showTrainingSections = canSeeTraining(user);
  const showPlans = canSeeTrainingPlans(user);
  const [weekStart, setWeekStart] = useState<Date>(() => getMonday(new Date()));
  const [data, setData] = useState<WeeklySchedule | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Vue « Créneaux d'entraînement » : chronologique (défaut, liste par
  // jour) ou grille calendrier semaine (Google Calendar simplifié).
  const [slotsView, setSlotsView] = useState<'chrono' | 'grid'>('chrono');

  const load = useCallback(async (mondayIso: string) => {
    try {
      setError(null);
      const resp = await scheduleApi.week(mondayIso);
      setData(resp);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
      setData(null);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setData(null); // évite l'affichage furtif des créneaux du profil précédent au switch
    (async () => {
      await load(toIsoDate(weekStart));
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
    // user?.id dans les deps : quand l'user switche vers un compte lié, la
    // liste d'entraînements dépend du profil (jeune vs sénior) — sans
    // cette dep le state resterait sur les données du profil précédent
    // jusqu'à un pull-to-refresh manuel.
  }, [weekStart, load, user?.id]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(toIsoDate(weekStart));
    setRefreshing(false);
  }, [weekStart, load]);

  useRefreshOnResume(() => { void load(toIsoDate(weekStart)); });

  // Au retour d'un détail de créneau (où l'on a pu s'inscrire ou se désinscrire), la semaine est relue en silence :
  // places et inscription affichées dans la liste doivent être à jour. Un échec réseau garde l'affichage actuel.
  const dataRef = useRef<WeeklySchedule | null>(null);
  dataRef.current = data;
  useFocusEffect(useCallback(() => {
    if (!dataRef.current) return; // 1er affichage : le chargement normal s'en charge
    let cancelled = false;
    scheduleApi.week(toIsoDate(weekStart)).then((resp) => { if (!cancelled) setData(resp); }).catch(() => { /* on garde l'affichage actuel */ });
    return () => { cancelled = true; };
  }, [weekStart]));

  // Inscription / désinscription à un créneau sur inscription : on met la ligne à jour tout de suite, puis on recharge
  // la semaine — l'avertissement « une seule inscription par semaine » des AUTRES créneaux en dépend.
  const onRegistrationChanged = useCallback((slot: TrainingSlot, registration: TrainingRegistration | null) => {
    setData((prev) => prev && {
      ...prev,
      slots: prev.slots.map((s) => (
        s.id === slot.id && s.templateId === slot.templateId && s.date === slot.date ? { ...s, registration } : s
      )),
    });
    void load(toIsoDate(weekStart));
  }, [load, weekStart]);

  // Groupement par jour de la semaine.
  //  - Annulés : affichés en fin de vue Liste (l'adhérent doit voir
  //    qu'un créneau habituel a été supprimé pour cette semaine, sinon
  //    il peut se déplacer sans savoir) mais absents de la vue Semaine.
  //  - Passés (fin dépassée) : masqués en vue Liste (le prochain
  //    créneau non-fini remonte naturellement en tête chronologique)
  //    mais gardés en vue Semaine — la grille sert de vue synoptique
  //    de la semaine, y compris ce qui vient d'avoir lieu.
  const slotsByDayFull = useMemo(() => {
    const map = new Map<number, TrainingSlot[]>();
    (data?.slots ?? []).forEach((s) => {
      const arr = map.get(s.dayOfWeek) ?? [];
      arr.push(s);
      map.set(s.dayOfWeek, arr);
    });
    return map;
  }, [data]);
  // Vue Semaine (grille) : sans les créneaux annulés — en semaine de vacances ils noyaient le planning ; ils restent
  // consultables dans la vue Liste.
  const slotsByDayGrid = useMemo(() => {
    const map = new Map<number, TrainingSlot[]>();
    for (const [day, slots] of slotsByDayFull) {
      const kept = slots.filter((s) => !s.isCancelled);
      if (kept.length > 0) map.set(day, kept);
    }
    return map;
  }, [slotsByDayFull]);
  // Vue Liste : les créneaux maintenus (et les occasionnels) d'abord, par jour ; les créneaux annulés sont tous
  // regroupés à la fin, en lignes compactes — en semaine de vacances, des dizaines de créneaux barrés au milieu du
  // planning le rendaient illisible.
  const { slotsByDay, cancelledSlots } = useMemo(() => {
    const now = Date.now();
    const map = new Map<number, TrainingSlot[]>();
    const cancelled: TrainingSlot[] = [];
    for (const [day, slots] of slotsByDayFull) {
      for (const s of slots) {
        const endMs = new Date(`${s.date}T${s.startTime}:00`).getTime() + s.durationMinutes * 60_000;
        if (Number.isFinite(endMs) && endMs < now) continue;
        if (s.isCancelled) {
          cancelled.push(s);
        } else {
          const arr = map.get(day) ?? [];
          arr.push(s);
          map.set(day, arr);
        }
      }
    }
    cancelled.sort((a, b) => `${a.date} ${a.startTime}`.localeCompare(`${b.date} ${b.startTime}`));
    return { slotsByDay: map, cancelledSlots: cancelled };
  }, [slotsByDayFull]);

  return (
    <View style={styles.root}>
      {showTrainingSections && (
        <WeekNavigator weekStart={weekStart} onChange={setWeekStart} disablePast />
      )}

      {loading && showTrainingSections ? (
        <FullScreenLoading />
      ) : error && showTrainingSections ? (
        <ErrorState message={error} onRetry={() => load(toIsoDate(weekStart))} />
      ) : (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={
            <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />
          }
        >
          {/* Mes groupes d'entraînement à la volée (ajouté par un encadrant, ou que j'encadre) : rien sans groupe. */}
          <MyGroupsCard />

          {/* Goûter du mercredi : positionnement à l'apport du goûter par
              les parents/jeunes. Placé en tête pour être immédiatement
              accessible aux parents non-licenciés qui n'ont QUE ça à
              voir dans cet onglet (auparavant dans le tab Profil). */}
          {showGouter && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>🍪 Goûters du mercredi</Text>
              <Pressable
                onPress={() => router.push('/gouter' as never)}
                style={({ pressed }) => [stylesGouter.card, pressed && { opacity: 0.7 }]}
              >
                <View style={stylesGouter.iconWrap}>
                  <Text style={{ fontSize: 22 }}>📅</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={stylesGouter.title}>Choisir un créneau</Text>
                  <Text style={stylesGouter.sub}>2 places par mercredi</Text>
                </View>
                <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
              </Pressable>
            </View>
          )}

          {/* Accès piscines — QR code présentable à la borne d'entrée.
              Réservé aux comptes licenciés (canSeePoolBadge). Placé en
              haut du contexte Entraînements, puisque c'est l'usage
              naturel du QR. */}
          {showPool && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>🏊 Accès Piscine</Text>
              <Pressable
                onPress={() => router.push('/pool-badge' as never)}
                style={({ pressed }) => [stylesPool.card, pressed && { opacity: 0.7 }]}
              >
                <View style={stylesPool.iconWrap}>
                  <Ionicons name="qr-code" size={22} color="#fff" />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={stylesPool.title}>QR Code</Text>
                  <Text style={stylesPool.sub}>à scanner au portique</Text>
                </View>
                <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
              </Pressable>
            </View>
          )}

          {/* Plans + créneaux : uniquement pour les licenciés. Un parent
              externe non-licencié n'a rien à voir ici — il n'utilise
              l'onglet Entraînements que pour la section Goûter. */}
          {showTrainingSections && (
            <>
              {/* Plans hebdo : pas pour les comptes Jeune. */}
              {showPlans && (
              <View style={styles.section}>
                <Text style={styles.sectionTitle}>📄 Plans d'entraînement</Text>
                {(data?.plans ?? []).length > 0 ? (
                  data!.plans.map((p) => (
                    <PlanRow key={p.id} plan={p} onOpen={() => router.push({ pathname: '/training-plan/[id]', params: { id: String(p.id), title: p.displayTitle } } as never)} />
                  ))
                ) : (
                  <Text style={styles.planEmpty}>Pas de plan publié pour cette semaine.</Text>
                )}
                <Pressable
                  onPress={() => router.push('/training-plans-history' as never)}
                  style={({ pressed }) => [stylesHistory.link, pressed && { opacity: 0.7 }]}
                >
                  <Ionicons name="time-outline" size={16} color={COLORS.secondaryDark} />
                  <Text style={stylesHistory.linkLabel}>Voir l'historique des plans</Text>
                  <Ionicons name="chevron-forward" size={16} color={COLORS.secondaryDark} />
                </Pressable>
              </View>
              )}

              {/* Tests chronométrés : mon temps et ceux de tous les adhérents, par saison. */}
              <View style={styles.section}>
                <Text style={styles.sectionTitle}>⏱️ Tests chronométrés</Text>
                <Pressable
                  onPress={() => router.push('/perf-tests' as never)}
                  style={({ pressed }) => [stylesPerf.card, pressed && { opacity: 0.7 }]}
                >
                  <View style={stylesPerf.iconWrap}>
                    <Text style={{ fontSize: 22 }}>⏱️</Text>
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={stylesPerf.title}>Mes temps et ceux du club</Text>
                    <Text style={stylesPerf.sub}>1500 m course à pied · 400 m natation · montée vélo — par saison</Text>
                  </View>
                  <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
                </Pressable>
                {/* Mon allure du club (1 à 5) par épreuve, d'après mes temps. */}
                <MyAllureCard />
              </View>

              {(() => {
                const maintainedTotal = Array.from(slotsByDay.values()).reduce((n, arr) => n + arr.length, 0);
                const chronoTotal = maintainedTotal + cancelledSlots.length;
                const gridTotal = Array.from(slotsByDayFull.values()).reduce((n, arr) => n + arr.length, 0);
                // Vraiment aucun créneau dans la semaine (ni passé ni futur) → empty state.
                if (gridTotal === 0) {
                  return (
                    <EmptyState
                      icon="📅"
                      title="Aucun créneau cette semaine"
                      message="Les entraîneurs n'ont pas (encore) défini de créneau pour cette semaine."
                    />
                  );
                }
                return (
                  <View style={styles.section}>
                    <View style={styles.slotsHeader}>
                      <Text style={styles.sectionTitle}>📅 Créneaux d'entraînement</Text>
                      <View style={styles.viewToggle}>
                        <Pressable
                          onPress={() => setSlotsView('chrono')}
                          style={[styles.viewToggleBtn, slotsView === 'chrono' && styles.viewToggleBtnActive]}
                        >
                          <Ionicons name="list-outline" size={14} color={slotsView === 'chrono' ? '#fff' : COLORS.textMuted} />
                          <Text style={[styles.viewToggleLabel, slotsView === 'chrono' && styles.viewToggleLabelActive]}>Liste</Text>
                        </Pressable>
                        <Pressable
                          onPress={() => setSlotsView('grid')}
                          style={[styles.viewToggleBtn, slotsView === 'grid' && styles.viewToggleBtnActive]}
                        >
                          <Ionicons name="grid-outline" size={14} color={slotsView === 'grid' ? '#fff' : COLORS.textMuted} />
                          <Text style={[styles.viewToggleLabel, slotsView === 'grid' && styles.viewToggleLabelActive]}>Semaine</Text>
                        </Pressable>
                      </View>
                    </View>
                    {slotsView === 'chrono' ? (
                      chronoTotal === 0 ? (
                        <Text style={styles.planEmpty}>Tous les créneaux de cette semaine sont déjà terminés.</Text>
                      ) : (
                        <>
                          {maintainedTotal === 0 && (
                            <Text style={styles.planEmpty}>Aucun créneau maintenu pour le reste de la semaine.</Text>
                          )}
                          {[1, 2, 3, 4, 5, 6, 7].map((day) => {
                            const slots = slotsByDay.get(day) ?? [];
                            if (slots.length === 0) return null;
                            const dayDate = addDays(weekStart, day - 1);
                            return (
                              <View key={day} style={styles.dayBlock}>
                                <Text style={styles.dayHeader}>
                                  {dayLabel(day)} <Text style={styles.daySub}>· {shortDayLabel(dayDate)}</Text>
                                </Text>
                                {slots.map((s, idx) => (
                                  <SlotRow key={`${s.id ?? 'v'}-${s.templateId ?? 'o'}-${idx}`} slot={s} onRegistrationChanged={onRegistrationChanged} />
                                ))}
                              </View>
                            );
                          })}
                          {/* Tous les créneaux annulés de la semaine, à la fin. */}
                          {cancelledSlots.length > 0 && (
                            <View style={styles.dayBlock}>
                              <Text style={[styles.dayHeader, styles.cancelledHeader]}>
                                🚫 Créneaux annulés <Text style={styles.daySub}>· {cancelledSlots.length}</Text>
                              </Text>
                              {cancelledSlots.map((s, idx) => (
                                <CancelledRow key={`${s.id ?? 'v'}-${s.templateId ?? 'o'}-${idx}`} slot={s} />
                              ))}
                            </View>
                          )}
                        </>
                      )
                    ) : slotsByDayGrid.size === 0 ? (
                      <Text style={styles.planEmpty}>
                        Tous les créneaux de cette semaine sont annulés (voir la vue Liste).
                      </Text>
                    ) : (
                      /* Vue Semaine : les créneaux déjà passés dans la semaine
                         sont gardés pour offrir une vue synoptique du planning
                         hebdo, mais pas les créneaux annulés. */
                      <WeekGrid slotsByDay={slotsByDayGrid} weekStart={weekStart} />
                    )}
                  </View>
                );
              })()}
            </>
          )}
        </ScrollView>
      )}
    </View>
  );
}

/**
 * Répartit les créneaux d'un jour en sous-colonnes parallèles quand
 * ils se chevauchent. Renvoie pour chaque slot :
 *  - colIndex  : sa position horizontale au sein du cluster (0-based)
 *  - totalCols : le nombre total de colonnes du cluster
 *
 * Le WeekGrid utilise ces deux valeurs pour calculer `left` et `width`.
 *
 * Algorithme (Google-Calendar-like) :
 *  1. Regroupe les slots en « clusters » — chaîne transitive de
 *     chevauchements par ordre chronologique.
 *  2. À l'intérieur de chaque cluster, on garnit greedy : pour chaque
 *     slot, on lui donne la 1re sous-colonne libre (celle dont le
 *     slot précédent termine avant/à son démarrage), sinon on ouvre
 *     une nouvelle sous-colonne.
 *  3. Le nombre total de sous-colonnes du cluster est appliqué à tous
 *     ses slots pour un rendu homogène.
 */
function layoutSlotsForDay(slots: TrainingSlot[]): Array<{
  slot: TrainingSlot;
  colIndex: number;
  totalCols: number;
}> {
  if (slots.length === 0) return [];
  // Calcule (startMin, endMin) pour chaque slot et trie par départ.
  const enriched = slots
    .map((s) => {
      const [h, m] = s.startTime.split(':').map(Number);
      const startMin = h * 60 + m;
      return { s, startMin, endMin: startMin + s.durationMinutes };
    })
    .sort((a, b) => a.startMin - b.startMin || b.endMin - a.endMin);

  const out: Array<{ slot: TrainingSlot; colIndex: number; totalCols: number }> = [];
  let cluster: Array<{ s: TrainingSlot; startMin: number; endMin: number; colIndex: number }> = [];
  let colEnds: number[] = []; // pour chaque colonne, l'endMin du dernier slot posé
  let clusterEnd = -1;

  const flush = () => {
    const total = colEnds.length;
    for (const item of cluster) {
      out.push({ slot: item.s, colIndex: item.colIndex, totalCols: Math.max(1, total) });
    }
    cluster = [];
    colEnds = [];
    clusterEnd = -1;
  };

  for (const item of enriched) {
    if (cluster.length > 0 && item.startMin >= clusterEnd) {
      flush();
    }
    // Trouve la 1re sous-colonne dispo.
    let assigned = -1;
    for (let i = 0; i < colEnds.length; i++) {
      if (colEnds[i] <= item.startMin) {
        assigned = i;
        break;
      }
    }
    if (assigned === -1) {
      assigned = colEnds.length;
      colEnds.push(item.endMin);
    } else {
      colEnds[assigned] = item.endMin;
    }
    cluster.push({ ...item, colIndex: assigned });
    if (item.endMin > clusterEnd) clusterEnd = item.endMin;
  }
  if (cluster.length > 0) flush();
  return out;
}

/**
 * Grille calendrier semaine (Google-Calendar-like, en version compacte).
 *  - 7 colonnes (lun-dim), scroll horizontal si l'écran est trop étroit.
 *  - Axe vertical en heures, hauteur d'une heure fixe (HOUR_HEIGHT).
 *  - Chaque slot est positionné en absolu par startTime / durationMinutes.
 *  - Les créneaux qui se chevauchent partagent la largeur du jour en
 *    sous-colonnes parallèles (cf. layoutSlotsForDay).
 *  - Tap → même détail que la vue chrono.
 */
function WeekGrid({ slotsByDay, weekStart }: {
  slotsByDay: Map<number, TrainingSlot[]>;
  weekStart: Date;
}) {
  const router = useRouter();
  const HOUR_HEIGHT = 44;
  const DAY_WIDTH = 88;
  const TIME_COL_WIDTH = 40;

  // Fenêtre horaire : entre l'heure du 1er slot (arrondie au-dessous) et
  // l'heure de fin du dernier (arrondie au-dessus). Fallback 7h-22h.
  const bounds = useMemo(() => {
    let min = Number.POSITIVE_INFINITY;
    let max = Number.NEGATIVE_INFINITY;
    for (const arr of slotsByDay.values()) {
      for (const s of arr) {
        const [h, m] = s.startTime.split(':').map(Number);
        const startMin = h * 60 + m;
        const endMin = startMin + s.durationMinutes;
        if (startMin < min) min = startMin;
        if (endMin > max) max = endMin;
      }
    }
    if (!Number.isFinite(min) || !Number.isFinite(max)) {
      min = 7 * 60;
      max = 22 * 60;
    }
    const startHour = Math.max(0, Math.floor(min / 60));
    const endHour = Math.min(24, Math.ceil(max / 60));
    return { startHour, endHour };
  }, [slotsByDay]);

  const hours = useMemo(
    () => Array.from({ length: bounds.endHour - bounds.startHour }, (_, i) => bounds.startHour + i),
    [bounds],
  );
  const gridHeight = hours.length * HOUR_HEIGHT;

  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingRight: SPACING.md }}>
      <View>
        {/* En-tête jours */}
        <View style={[gridStyles.headerRow, { paddingLeft: TIME_COL_WIDTH }]}>
          {[1, 2, 3, 4, 5, 6, 7].map((day) => {
            const dayDate = addDays(weekStart, day - 1);
            return (
              <View key={day} style={[gridStyles.dayHeader, { width: DAY_WIDTH }]}>
                <Text style={gridStyles.dayHeaderName}>{dayLabel(day)}</Text>
                <Text style={gridStyles.dayHeaderDate}>{dayDate.getDate()}/{dayDate.getMonth() + 1}</Text>
              </View>
            );
          })}
        </View>

        <View style={{ flexDirection: 'row', height: gridHeight }}>
          {/* Colonne des heures */}
          <View style={{ width: TIME_COL_WIDTH }}>
            {hours.map((h) => (
              <View key={h} style={[gridStyles.hourCell, { height: HOUR_HEIGHT }]}>
                <Text style={gridStyles.hourLabel}>{h}h</Text>
              </View>
            ))}
          </View>

          {/* Grille : 7 colonnes de jour */}
          {[1, 2, 3, 4, 5, 6, 7].map((day) => {
            const slots = slotsByDay.get(day) ?? [];
            const laidOut = layoutSlotsForDay(slots);
            return (
              <View key={day} style={[gridStyles.dayCol, { width: DAY_WIDTH, height: gridHeight }]}>
                {/* Lignes horaires (fond) */}
                {hours.map((h) => (
                  <View key={h} style={[gridStyles.hourGridLine, { top: (h - bounds.startHour) * HOUR_HEIGHT }]} />
                ))}

                {/* Slots positionnés en absolu. Les créneaux qui se
                    chevauchent sont placés dans des sous-colonnes
                    parallèles (colIndex/totalCols de leur cluster). */}
                {laidOut.map(({ slot: s, colIndex, totalCols }, idx) => {
                  const [sh, sm] = s.startTime.split(':').map(Number);
                  const startMin = sh * 60 + sm;
                  const top = ((startMin - bounds.startHour * 60) / 60) * HOUR_HEIGHT;
                  const height = Math.max(24, (s.durationMinutes / 60) * HOUR_HEIGHT - 2);
                  const bg = s.sportColor + '22'; // couleur sport + alpha
                  // Répartition horizontale : le container laisse 2 px
                  // à gauche/droite, le reste est partagé entre totalCols
                  // sous-colonnes (1 px de gap intra-cluster).
                  const usable = DAY_WIDTH - 4;
                  const gap = totalCols > 1 ? 1 : 0;
                  const subWidth = (usable - gap * (totalCols - 1)) / totalCols;
                  const left = 2 + colIndex * (subWidth + gap);
                  return (
                    <Pressable
                      key={`${s.id ?? 'v'}-${s.templateId ?? 'o'}-${idx}`}
                      onPress={() => router.push({ pathname: '/training-slot', params: { slot: JSON.stringify(s) } })}
                      style={({ pressed }) => [
                        gridStyles.slotBlock,
                        { top, height, left, width: subWidth, backgroundColor: bg, borderLeftColor: s.sportColor },
                        pressed && { opacity: 0.7 },
                      ]}
                    >
                      <Text style={gridStyles.slotTime} numberOfLines={1}>
                        {s.sportIcon} {s.startTime}{s.registration ? (s.registration.mine ? ' ✅' : ' ✍️') : ''}
                      </Text>
                      <Text style={gridStyles.slotTitle} numberOfLines={2}>
                        {s.title}
                      </Text>
                    </Pressable>
                  );
                })}
              </View>
            );
          })}
        </View>
      </View>
    </ScrollView>
  );
}

function SlotRow({ slot, onRegistrationChanged }: {
  slot: TrainingSlot;
  onRegistrationChanged: (slot: TrainingSlot, registration: TrainingRegistration | null) => void;
}) {
  const router = useRouter();
  const isCancelled = slot.isCancelled;

  return (
    <Pressable
      onPress={() => router.push({ pathname: '/training-slot', params: { slot: JSON.stringify(slot) } })}
      style={({ pressed }) => [
        styles.slot,
        isCancelled && styles.slotCancelled,
        pressed && styles.pressed,
      ]}
    >
      <View style={styles.slotTimeCol}>
        <Text style={[styles.slotTime, isCancelled && styles.cancelledText]}>{slot.startTime}</Text>
        <Text style={[styles.slotDuration, isCancelled && styles.cancelledText]}>
          {formatDurationHm(slot.durationMinutes)}
        </Text>
      </View>
      <View style={styles.slotBody}>
        <Text
          style={[styles.slotTitle, isCancelled && styles.cancelledText]}
          numberOfLines={1}
        >
          {slot.title}
        </Text>
        <View style={styles.slotMeta}>
          <SportBadge
            icon={slot.sportIcon}
            label={slot.sportLabel}
            color={isCancelled ? COLORS.textMuted : slot.sportColor}
            size="sm"
            strikethrough={isCancelled}
          />
          {/* Un créneau annulé n'expose qu'un seul tag « Annulé ».
              Les tags « Occasionnel » / « Modifié » perdent leur sens
              dans ce cas (l'événement n'aura pas lieu). */}
          {isCancelled ? (
            <Tag color="#991B1B" bg="#FEE2E2" label="Annulé" />
          ) : (
            <>
              {slot.isOccasional && <Tag color={COLORS.secondary} label="Occasionnel" />}
              {slot.isOverride && !slot.isOccasional && <Tag color="#92400E" bg="#FEF3C7" label="Modifié" />}
            </>
          )}
          {/* Icône 📎 uniquement — 📝 (description) était redondante avec
              l'ouverture du détail au tap. */}
          {slot.attachments.length > 0 && (
            <View style={styles.extraHint}>
              <Text style={styles.extraHintIcon}>📎</Text>
            </View>
          )}
        </View>
        <Text
          style={[styles.slotLocation, isCancelled && styles.cancelledText]}
          numberOfLines={1}
        >
          📍 {slot.location}
        </Text>
        {/* Créneau sur inscription : places, inscription / désinscription, comme le vote d'un événement. Rien pour un
            créneau annulé (plus d'inscription possible, l'événement n'aura pas lieu). */}
        {slot.registration && !isCancelled && (
          <TrainingRegistrationBar
            slot={slot}
            registration={slot.registration}
            onChanged={(reg) => onRegistrationChanged(slot, reg)}
          />
        )}
      </View>
      <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} style={{ alignSelf: 'center' }} />
    </Pressable>
  );
}

/** Créneau annulé de la liste : une ligne compacte (jour, heure, titre barré), ouvre le détail. */
function CancelledRow({ slot }: { slot: TrainingSlot }) {
  const router = useRouter();
  const d = fromIsoDate(slot.date);
  const when = `${dayLabel(slot.dayOfWeek).slice(0, 3)}. ${d.getDate()}/${d.getMonth() + 1}`;

  return (
    <Pressable
      onPress={() => router.push({ pathname: '/training-slot', params: { slot: JSON.stringify(slot) } })}
      accessibilityLabel={`Annulé : ${slot.title}, ${when} à ${slot.startTime}`}
      style={({ pressed }) => [styles.cancelledRow, pressed && styles.pressed]}
    >
      <Text style={styles.cancelledWhen}>{when}</Text>
      <Text style={styles.cancelledTitle} numberOfLines={1}>
        {slot.startTime} · {slot.sportIcon} {slot.title}
      </Text>
      <Ionicons name="chevron-forward" size={16} color={COLORS.textSubtle} />
    </Pressable>
  );
}

function Tag({ label, color, bg }: { label: string; color: string; bg?: string }) {
  return (
    <View style={[styles.tag, { borderColor: color, backgroundColor: bg ?? 'transparent' }]}>
      <Text style={[styles.tagLabel, { color }]}>{label}</Text>
    </View>
  );
}

function PlanRow({ plan, onOpen }: { plan: TrainingPlan; onOpen: () => void }) {
  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.planRow, pressed && styles.pressed]}>
      <View style={styles.planIcon}>
        <Text style={{ fontSize: 22 }}>📄</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.planTitle}>{plan.displayTitle}</Text>
        {plan.description ? (
          <Text style={styles.planDesc} numberOfLines={2}>
            {plan.description}
          </Text>
        ) : null}
        <Text style={styles.planMeta}>
          Par {plan.postedBy.fullName} · {formatDate(plan.postedAt)}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  section: { marginBottom: SPACING.lg },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.textMuted,
    marginBottom: SPACING.sm,
    marginLeft: 4,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
  slotsHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: 8,
    flexWrap: 'wrap',
  },
  viewToggle: {
    flexDirection: 'row',
    backgroundColor: COLORS.surfaceAlt,
    borderRadius: RADIUS.full,
    padding: 2,
    borderWidth: 1,
    borderColor: COLORS.border,
    marginBottom: SPACING.sm,
  },
  viewToggleBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: RADIUS.full,
  },
  viewToggleBtnActive: {
    backgroundColor: COLORS.secondary,
  },
  viewToggleLabel: {
    fontSize: 11,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.3,
  },
  viewToggleLabelActive: { color: '#fff' },
  planEmpty: {
    fontSize: 13,
    color: COLORS.textMuted,
    fontStyle: 'italic',
    paddingHorizontal: 4,
    paddingVertical: 6,
  },
  dayBlock: { marginBottom: SPACING.md },
  dayHeader: {
    fontSize: 15,
    fontWeight: '700',
    color: COLORS.secondaryDark,
    marginBottom: 6,
    paddingHorizontal: 4,
  },
  daySub: { color: COLORS.textMuted, fontWeight: '500', fontSize: 13 },
  cancelledHeader: { color: '#991B1B' },
  cancelledRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.sm,
    paddingHorizontal: SPACING.md,
    paddingVertical: 9,
    marginBottom: 4,
    borderLeftWidth: 3,
    borderLeftColor: '#FCA5A5',
  },
  cancelledWhen: { width: 66, fontSize: 12, fontWeight: '700', color: COLORS.textMuted },
  cancelledTitle: { flex: 1, fontSize: 13, color: COLORS.textMuted, textDecorationLine: 'line-through' },
  slot: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  slotCancelled: { opacity: 0.7 },
  cancelledText: { textDecorationLine: 'line-through' },
  slotPast: { opacity: 0.55 },
  slotTimeCol: {
    minWidth: 60,
    alignItems: 'flex-start',
    paddingTop: 2,
  },
  slotTime: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  slotDuration: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  slotBody: { flex: 1, gap: 4 },
  slotTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  slotMeta: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center', marginTop: 2 },
  slotLocation: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  extraHint: { flexDirection: 'row', gap: 4, marginLeft: 4 },
  extraHintIcon: { fontSize: 12, opacity: 0.7 },
  tag: {
    borderWidth: 1,
    borderRadius: RADIUS.full,
    paddingHorizontal: 8,
    paddingVertical: 1,
  },
  tagLabel: { fontSize: 11, fontWeight: '600' },
  planRow: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: 8,
    gap: SPACING.md,
    ...SHADOWS.sm,
  },
  pressed: { opacity: 0.85 },
  planIcon: {
    width: 44,
    height: 44,
    borderRadius: RADIUS.md,
    backgroundColor: COLORS.secondarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  planTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  planDesc: { fontSize: 13, color: COLORS.text, marginTop: 4, lineHeight: 18 },
  planMeta: { fontSize: 11, color: COLORS.textMuted, marginTop: 6 },
});

const stylesHistory = StyleSheet.create({
  link: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    marginTop: SPACING.sm,
    paddingVertical: 10,
    paddingHorizontal: SPACING.md,
    backgroundColor: COLORS.secondarySoft,
    borderRadius: RADIUS.md,
  },
  linkLabel: {
    flex: 1,
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.secondaryDark,
  },
});

const stylesPool = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.primary,
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 8,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
});

const stylesPerf = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.secondary,
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 8,
    backgroundColor: COLORS.secondarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2, lineHeight: 16 },
});

const stylesGouter = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    borderLeftWidth: 4,
    borderLeftColor: '#ea580c',
  },
  iconWrap: {
    width: 40,
    height: 40,
    borderRadius: 8,
    backgroundColor: '#fed7aa',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2, lineHeight: 16 },
});

const gridStyles = StyleSheet.create({
  headerRow: {
    flexDirection: 'row',
    marginBottom: 4,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  dayHeader: {
    alignItems: 'center',
    paddingVertical: 6,
  },
  dayHeaderName: {
    fontSize: 12,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  dayHeaderDate: { fontSize: 11, color: COLORS.textSubtle, marginTop: 1 },
  hourCell: {
    justifyContent: 'flex-start',
    paddingRight: 6,
    alignItems: 'flex-end',
  },
  hourLabel: { fontSize: 10, color: COLORS.textMuted, marginTop: -6 },
  dayCol: {
    position: 'relative',
    borderLeftWidth: StyleSheet.hairlineWidth,
    borderLeftColor: COLORS.border,
  },
  hourGridLine: {
    position: 'absolute',
    left: 0,
    right: 0,
    height: StyleSheet.hairlineWidth,
    backgroundColor: COLORS.border,
  },
  slotBlock: {
    // left / width sont posés inline par le layout (partage horizontal
    // en cas de chevauchement — voir layoutSlotsForDay).
    position: 'absolute',
    borderRadius: RADIUS.sm,
    padding: 4,
    borderLeftWidth: 3,
    overflow: 'hidden',
  },
  slotTime: { fontSize: 10, fontWeight: '700', color: COLORS.text },
  slotTitle: { fontSize: 11, color: COLORS.text, lineHeight: 13, marginTop: 1 },
});
