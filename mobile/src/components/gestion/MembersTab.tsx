import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import {
  Modal,
  Pressable,
  RefreshControl,
  SectionList,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import { staffDirectory, youthDocuments } from '@/api/resources';
import type { StaffDirectorySeason, StaffMember, StaffYouth, YouthDocument, YouthPermanentKind } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { ContactButtons } from '@/components/groups/ContactButtons';
import { COLORS, RADIUS, SPACING } from '@/config';
import { openAttachment, withBearer } from '@/lib/openAttachment';
import { formatPhoneFr } from '@/utils/phone';

/** Pour la recherche : sans accents ni casse (« élodie » trouve « Elodie »). */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

// L'autorisation de sortie du territoire n'est pas suivie ici : elle se dépose pour chaque stage.
const KINDS: { kind: YouthPermanentKind; icon: string; label: string }[] = [
  { kind: 'health_form', icon: '🩺', label: 'Fiche sanitaire' },
  { kind: 'go_home_alone', icon: '🏠', label: 'Rentrer seul' },
];

function isOk(doc: YouthDocument | null): boolean {
  return doc !== null && !doc.expired;
}

/** Jeune dont la fiche sanitaire ou l'autorisation de rentrer seul manque ou a expiré. */
function hasMissingDocs(youth: StaffYouth | undefined): boolean {
  return youth !== undefined && KINDS.some((k) => !isOk(youth.documents[k.kind] ?? null));
}

/** Qui afficher : tout le monde, les adultes ou les jeunes (profil Jeune). */
type MemberKind = 'all' | 'senior' | 'youth';
/** Filtre complémentaire : appartenance à la saison en cours, adhérents externes, documents de jeune à compléter. */
type StatusFilter = 'all' | 'in' | 'out' | 'external' | 'missing';

const KIND_OPTIONS: { key: MemberKind; label: string }[] = [
  { key: 'all', label: 'Tous' },
  { key: 'senior', label: 'Séniors' },
  { key: 'youth', label: 'Jeunes' },
];

/**
 * Onglet « Adhérents » de l'espace Staff : les adhérents actifs, séniors et jeunes ensemble, avec nom, prénom et
 * téléphone, un bouton d'appel (urgences), un bouton WhatsApp pour leur écrire facilement et, s'il est connu, le
 * numéro de leur contact d'urgence (affiché et appelable). Un sélecteur Tous / Séniors / Jeunes permet de les
 * différencier. Regroupés par initiale du nom, avec une recherche. Chaque adhérent porte une pastille indiquant s'il
 * est dans la liste des adhérents de la saison en cours (import FFTri), avec un filtre.
 *
 * Pour un jeune : âge, état de sa fiche sanitaire et de son autorisation de rentrer seul (les documents manquants ou
 * expirés sont en évidence, filtre « À compléter »). Un appui sur une pastille ouvre directement le document, un
 * appui sur la ligne ouvre la fiche du jeune (documents, contacts, dépôt).
 *
 * Réservé au staff (le serveur revérifie le profil).
 */
export function MembersTab() {
  const router = useRouter();
  const [members, setMembers] = useState<StaffMember[]>([]);
  // Documents des jeunes par identifiant ; null tant qu'ils n'ont pas pu être lus (l'annuaire reste alors affiché).
  const [youth, setYouth] = useState<Map<number, StaffYouth> | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [season, setSeason] = useState<StaffDirectorySeason | null>(null);
  const [kind, setKind] = useState<MemberKind>('all');
  const [status, setStatus] = useState<StatusFilter>('all');
  // Adhérent dont la photo est affichée en grand (appui sur la photo d'une ligne).
  const [zoomed, setZoomed] = useState<StaffMember | null>(null);

  const load = useCallback(async () => {
    const [dir, docs] = await Promise.allSettled([staffDirectory.list(), youthDocuments.overview()]);
    if (dir.status === 'rejected') {
      setError(dir.reason instanceof ApiError ? dir.reason.message : 'Erreur de chargement');
      return;
    }
    setError(null);
    setMembers(dir.value.data);
    setSeason(dir.value.season);
    // Sans les documents, les jeunes restent listés (coordonnées) mais sans leurs pastilles.
    if (docs.status === 'fulfilled') setYouth(new Map(docs.value.data.map((y) => [y.id, y])));
  }, []);

  // Relu à chaque retour sur l'onglet (un document vient peut-être d'être déposé depuis la fiche d'un jeune).
  useFocusEffect(
    useCallback(() => {
      void load().then(() => setLoading(false));
    }, [load]),
  );

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  // Marquage possible seulement si la liste de la saison est déjà importée ; sinon tout le
  // monde paraîtrait « hors liste ».
  const seasonKnown = season !== null && season.memberCount > 0;
  const youthCount = members.filter((m) => m.isJeune).length;

  // Les compteurs des filtres complémentaires portent sur le public choisi (Séniors / Jeunes).
  const ofKind = useMemo(
    () => members.filter((m) => (kind === 'all' ? true : kind === 'youth' ? m.isJeune : !m.isJeune)),
    [members, kind],
  );
  const inCount = ofKind.filter((m) => m.inCurrentSeason).length;
  // Adhérents externes : licenciés dans un autre club (compte créé depuis l'appli).
  const externalCount = ofKind.filter((m) => m.isExternal).length;
  const missingCount = ofKind.filter((m) => m.isJeune && hasMissingDocs(youth?.get(m.id))).length;

  // Puces à bascule : un second appui retire le filtre (le public se choisit dans le sélecteur du dessus).
  const statusOptions = useMemo(() => {
    const options: { key: StatusFilter; label: string }[] = [];
    if (seasonKnown && season) {
      options.push({ key: 'in', label: `Saison ${season.label} (${inCount})` });
      options.push({ key: 'out', label: `Hors liste (${ofKind.length - inCount})` });
    }
    if (externalCount > 0) options.push({ key: 'external', label: `Externes (${externalCount})` });
    // Documents des jeunes : sans objet pour les seuls séniors.
    if (kind !== 'senior' && youth !== null && youthCount > 0) {
      options.push({ key: 'missing', label: `À compléter (${missingCount})` });
    }
    return options;
  }, [ofKind.length, seasonKnown, season, inCount, externalCount, kind, youth, youthCount, missingCount]);
  // Un filtre qui n'est plus proposé (autre public choisi) est ignoré.
  const activeStatus: StatusFilter = statusOptions.some((o) => o.key === status) ? status : 'all';

  const sections = useMemo(() => {
    const q = normalize(query.trim());
    const byStatus = ofKind.filter((m) => {
      switch (activeStatus) {
        case 'external': return m.isExternal;
        case 'missing': return m.isJeune && hasMissingDocs(youth?.get(m.id));
        case 'in': return m.inCurrentSeason;
        case 'out': return !m.inCurrentSeason;
        default: return true;
      }
    });
    const filtered = q === ''
      ? byStatus
      : byStatus.filter((m) => normalize(`${m.nom} ${m.prenom}`).includes(q) || normalize(`${m.prenom} ${m.nom}`).includes(q));

    const byLetter = new Map<string, StaffMember[]>();
    for (const m of filtered) {
      const letter = normalize(m.nom.trim()).charAt(0).toUpperCase() || '#';
      const key = /[A-Z]/.test(letter) ? letter : '#';
      byLetter.set(key, [...(byLetter.get(key) ?? []), m]);
    }
    return Array.from(byLetter.entries())
      .sort(([a], [b]) => (a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b)))
      .map(([title, data]) => ({ title, data }));
  }, [ofKind, activeStatus, query, youth]);

  const total = sections.reduce((n, s) => n + s.data.length, 0);
  const noun = kind === 'senior' ? 'sénior' : kind === 'youth' ? 'jeune' : 'adhérent';

  if (loading) return <FullScreenLoading />;
  if (error && members.length === 0) return <ErrorState message={error} onRetry={() => void load()} />;

  const kindCount = (k: MemberKind) => (k === 'all' ? members.length : k === 'youth' ? youthCount : members.length - youthCount);

  return (
    <View style={styles.root}>
      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={COLORS.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Rechercher un nom ou un prénom"
          placeholderTextColor={COLORS.textSubtle}
          autoCapitalize="none"
          autoCorrect={false}
          clearButtonMode="while-editing"
          style={styles.search}
        />
        {query !== '' && (
          <Pressable onPress={() => setQuery('')} hitSlop={10} accessibilityLabel="Effacer la recherche">
            <Ionicons name="close-circle" size={18} color={COLORS.textSubtle} />
          </Pressable>
        )}
      </View>

      {/* Séniors / Jeunes : sélecteur à trois segments. */}
      <View style={styles.segment} accessibilityRole="tablist">
        {KIND_OPTIONS.map((o) => {
          const active = kind === o.key;
          return (
            <Pressable
              key={o.key}
              onPress={() => setKind(o.key)}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              style={[styles.segmentItem, active && styles.segmentItemActive]}
            >
              <Text style={[styles.segmentLabel, active && styles.segmentLabelActive]} numberOfLines={1}>
                {o.label} ({kindCount(o.key)})
              </Text>
            </Pressable>
          );
        })}
      </View>

      {statusOptions.length > 0 && (
        <View style={styles.filters}>
          {statusOptions.map((f) => {
            const active = activeStatus === f.key;
            return (
              <Pressable
                key={f.key}
                onPress={() => setStatus(active ? 'all' : f.key)}
                accessibilityRole="button"
                accessibilityState={{ selected: active }}
                style={[styles.filterChip, active && styles.filterChipActive]}
              >
                <Text style={[styles.filterLabel, active && styles.filterLabelActive]} numberOfLines={1}>{f.label}</Text>
              </Pressable>
            );
          })}
        </View>
      )}
      {season !== null && season.memberCount === 0 && (
        <Text style={styles.seasonNote}>
          La liste des adhérents de la saison {season.label} n'est pas encore importée : l'appartenance n'est pas indiquée.
        </Text>
      )}
      <Text style={styles.count}>{total} {noun}{total > 1 ? 's' : ''}</Text>

      <SectionList
        sections={sections}
        keyExtractor={(m) => String(m.id)}
        stickySectionHeadersEnabled
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={
          members.length === 0
            ? <EmptyState icon="👥" title="Aucun adhérent" message="Aucun adhérent actif pour le moment." />
            : activeStatus === 'missing' && query.trim() === ''
              ? <EmptyState icon="✅" title="Rien à afficher" message="Tous les documents sont à jour." />
              : <EmptyState icon="🔎" title="Aucun adhérent trouvé" message="Essayez une autre orthographe." />
        }
        renderSectionHeader={({ section }) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionLetter}>{section.title}</Text>
          </View>
        )}
        renderItem={({ item }) => {
          const docs = item.isJeune ? youth?.get(item.id) : undefined;
          const ficheHref = ('/youth-documents/' + item.id) as never;
          const info = (
            <>
              <Text style={styles.name} numberOfLines={1}>
                <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
                {docs && docs.age !== null ? <Text style={styles.age}>  {docs.age} ans</Text> : null}
              </Text>
              {((seasonKnown && season) || item.isExternal || (item.isJeune && kind === 'all')) && (
                <View style={styles.pillRow}>
                  {item.isJeune && kind === 'all' && (
                    <View style={[styles.pill, styles.pillYouth]}>
                      <Text style={[styles.pillLabel, styles.pillLabelYouth]}>Jeune</Text>
                    </View>
                  )}
                  {item.isExternal && (
                    <View style={[styles.pill, styles.pillExternal]}>
                      <Text style={[styles.pillLabel, styles.pillLabelExternal]}>Externe · autre club</Text>
                    </View>
                  )}
                  {seasonKnown && season && (
                    <View style={[styles.pill, item.inCurrentSeason ? styles.pillIn : styles.pillOut]}>
                      <Text style={[styles.pillLabel, item.inCurrentSeason ? styles.pillLabelIn : styles.pillLabelOut]}>
                        {item.inCurrentSeason ? `✓ Saison ${season.label}` : `Hors liste ${season.label}`}
                      </Text>
                    </View>
                  )}
                </View>
              )}
              {item.telephone ? (
                <>
                  <Text style={styles.phone}>{formatPhoneFr(item.telephone)}</Text>
                  {item.telephoneOf && <Text style={styles.phoneOf}>Numéro de {item.telephoneOf} (parent)</Text>}
                </>
              ) : (
                <Text style={styles.noPhone}>Numéro non renseigné</Text>
              )}
              {item.telephoneUrgence && (
                <Text style={styles.phoneUrgence}>Urgence : {formatPhoneFr(item.telephoneUrgence)}</Text>
              )}
            </>
          );

          return (
            <View style={styles.rowWrap}>
              <View style={styles.row}>
                <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} onPress={() => setZoomed(item)} />
                {item.isJeune ? (
                  // Jeune : un appui sur la ligne ouvre sa fiche (documents, contacts, dépôt).
                  <Pressable
                    onPress={() => router.push(ficheHref)}
                    accessibilityRole="button"
                    accessibilityLabel={`Fiche de ${item.prenom} ${item.nom}`}
                    style={({ pressed }) => [{ flex: 1 }, pressed && { opacity: 0.85 }]}
                  >
                    {info}
                  </Pressable>
                ) : (
                  <View style={{ flex: 1 }}>{info}</View>
                )}
                <View style={styles.actions}>
                  {(item.telephone || item.telephoneUrgence) && (
                    <ContactButtons
                      name={`${item.prenom} ${item.nom}`}
                      telephone={item.telephone}
                      telephoneOf={item.telephoneOf}
                      telephoneUrgence={item.telephoneUrgence}
                    />
                  )}
                  {item.isJeune && <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />}
                </View>
              </View>
              {/* Documents du jeune, sur une ligne à part : les deux pastilles tiennent côte à côte. */}
              {docs && (
                <View style={styles.docs}>
                  {KINDS.map((k) => {
                    const doc = docs.documents[k.kind] ?? null;
                    const ok = isOk(doc);
                    return (
                      <Pressable
                        key={k.kind}
                        // Un document déposé s'ouvre directement ; sinon on ouvre la fiche pour le déposer.
                        onPress={() => (doc
                          ? void openAttachment((token) => withBearer(doc.url, token))
                          : router.push(ficheHref))}
                        accessibilityRole="button"
                        accessibilityLabel={`${k.label} de ${item.prenom} ${item.nom} : ${doc ? (doc.expired ? 'expirée' : 'ouvrir') : 'manquante'}`}
                        style={({ pressed }) => [styles.doc, ok ? styles.docOk : doc ? styles.docExpired : styles.docMissing, pressed && { opacity: 0.7 }]}
                      >
                        <Text style={styles.docIcon}>{k.icon}</Text>
                        <Text style={[styles.docLabel, ok ? styles.docLabelOk : doc ? styles.docLabelExpired : styles.docLabelMissing]} numberOfLines={1}>
                          {ok ? k.label : doc ? 'Expirée' : 'Manquante'}
                        </Text>
                      </Pressable>
                    );
                  })}
                </View>
              )}
            </View>
          );
        }}
      />

      {/* Photo en grand : un appui n'importe où referme. */}
      <Modal visible={zoomed !== null} transparent animationType="fade" onRequestClose={() => setZoomed(null)}>
        <Pressable style={styles.zoomBackdrop} onPress={() => setZoomed(null)} accessibilityLabel="Fermer la photo">
          {zoomed?.avatarUrl ? (
            <Image source={{ uri: zoomed.avatarUrl }} style={styles.zoomImage} contentFit="cover" />
          ) : null}
          {zoomed && (
            <Text style={styles.zoomName}>{zoomed.prenom} {zoomed.nom.toUpperCase()}</Text>
          )}
        </Pressable>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  zoomBackdrop: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: SPACING.md,
    padding: SPACING.xl,
    backgroundColor: 'rgba(0,0,0,0.85)',
  },
  zoomImage: { width: 300, height: 300, maxWidth: '100%', borderRadius: RADIUS.lg, backgroundColor: COLORS.surfaceAlt },
  zoomName: { color: '#fff', fontSize: 18, fontWeight: '700', textAlign: 'center' },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    margin: SPACING.md,
    marginBottom: 8,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  segment: {
    flexDirection: 'row',
    marginHorizontal: SPACING.md,
    marginBottom: 8,
    padding: 3,
    gap: 3,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surfaceAlt,
  },
  segmentItem: { flex: 1, alignItems: 'center', paddingVertical: 8, paddingHorizontal: 4, borderRadius: RADIUS.sm },
  segmentItemActive: { backgroundColor: COLORS.brandNavy },
  segmentLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  segmentLabelActive: { color: '#fff' },
  filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginHorizontal: SPACING.md, marginBottom: 6 },
  filterChip: {
    paddingHorizontal: 12, paddingVertical: 7,
    borderRadius: RADIUS.full, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  filterChipActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  filterLabel: { fontSize: 12, fontWeight: '600', color: COLORS.text },
  filterLabelActive: { color: '#fff' },
  seasonNote: { fontSize: 12, color: COLORS.textMuted, marginHorizontal: SPACING.md, marginBottom: 4 },
  pill: { alignSelf: 'flex-start', paddingHorizontal: 8, paddingVertical: 2, borderRadius: RADIUS.full, marginTop: 3 },
  pillRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  pillYouth: { backgroundColor: '#ede9fe' },
  pillLabelYouth: { color: '#5b21b6' },
  pillExternal: { backgroundColor: '#dbeafe' },
  pillLabelExternal: { color: '#1e40af' },
  pillIn: { backgroundColor: '#dcfce7' },
  pillOut: { backgroundColor: '#ffedd5' },
  pillLabel: { fontSize: 11, fontWeight: '700' },
  pillLabelIn: { color: '#166534' },
  pillLabelOut: { color: '#9a3412' },
  count: { fontSize: 12, color: COLORS.textMuted, marginHorizontal: SPACING.md, marginBottom: 4 },
  listContent: { paddingBottom: SPACING.xxl },
  sectionHeader: { backgroundColor: COLORS.background, paddingHorizontal: SPACING.md, paddingVertical: 4 },
  sectionLetter: { fontSize: 13, fontWeight: '800', color: COLORS.secondaryDark },
  rowWrap: {
    backgroundColor: COLORS.surface,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: SPACING.md,
    paddingVertical: 10,
  },
  name: { fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  age: { fontSize: 12, color: COLORS.textMuted },
  // Aligné sur le texte (marge + photo 52 + écart), sous le nom du jeune.
  docs: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginLeft: SPACING.md + 52 + 12, marginRight: SPACING.md, marginTop: -2, paddingBottom: 10 },
  doc: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 9, paddingVertical: 5, borderRadius: RADIUS.full, borderWidth: 1 },
  docOk: { backgroundColor: '#dcfce7', borderColor: '#86efac' },
  docMissing: { backgroundColor: '#fee2e2', borderColor: '#fca5a5' },
  docExpired: { backgroundColor: '#ffedd5', borderColor: '#fdba74' },
  docIcon: { fontSize: 13 },
  docLabel: { fontSize: 12, fontWeight: '700' },
  docLabelOk: { color: '#166534' },
  docLabelMissing: { color: '#991b1b' },
  docLabelExpired: { color: '#9a3412' },
  phone: { fontSize: 14, color: COLORS.secondaryDark, marginTop: 2, fontVariant: ['tabular-nums'] },
  phoneOf: { fontSize: 12, color: COLORS.textMuted, marginTop: 1 },
  noPhone: { fontSize: 13, color: COLORS.textSubtle, fontStyle: 'italic', marginTop: 2 },
  phoneUrgence: { fontSize: 13, color: COLORS.error, fontWeight: '600', marginTop: 2, fontVariant: ['tabular-nums'] },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
