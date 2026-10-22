import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { youthDocuments } from '@/api/resources';
import type { StaffYouth, YouthDocument, YouthDocumentKind } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';
import { openAttachment, withBearer } from '@/lib/openAttachment';

type Filter = 'all' | 'missing';

const KINDS: { kind: YouthDocumentKind; icon: string; label: string }[] = [
  { kind: 'health_form', icon: '🩺', label: 'Fiche sanitaire' },
  { kind: 'exit_authorization', icon: '🛂', label: 'Autorisation de sortie' },
];

/** Pour la recherche : sans accents ni casse (« élodie » trouve « Elodie »). */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function isOk(doc: YouthDocument | null): boolean {
  return doc !== null && !doc.expired;
}

/**
 * Onglet « Jeunes » de l'espace Staff : les adhérents jeunes avec l'état de leur fiche sanitaire et de leur autorisation de
 * sortie du territoire. Les documents manquants ou expirés sont en évidence (filtre « À compléter ») ; un appui sur une
 * pastille ouvre directement le document, un appui sur la ligne ouvre la fiche du jeune (documents, contacts, dépôt).
 */
export function YouthTab() {
  const router = useRouter();
  const [rows, setRows] = useState<StaffYouth[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<Filter>('all');

  const load = useCallback(async () => {
    try {
      setError(null);
      setRows((await youthDocuments.overview()).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, []);

  // Relu à chaque retour sur l'onglet (un document vient peut-être d'être déposé depuis la fiche d'un jeune).
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  const incomplete = useMemo(
    () => (rows ?? []).filter((y) => KINDS.some((k) => !isOk(y.documents[k.kind]))).length,
    [rows],
  );

  const shown = useMemo(() => {
    const q = normalize(query.trim());
    return (rows ?? []).filter((y) => {
      if (filter === 'missing' && KINDS.every((k) => isOk(y.documents[k.kind]))) return false;
      return q === '' || normalize(`${y.nom} ${y.prenom}`).includes(q) || normalize(`${y.prenom} ${y.nom}`).includes(q);
    });
  }, [rows, query, filter]);

  if (rows === null) {
    return error ? <ErrorState message={error} onRetry={() => void load()} /> : <FullScreenLoading />;
  }

  return (
    <View style={styles.root}>
      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={COLORS.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Rechercher un jeune"
          placeholderTextColor={COLORS.textSubtle}
          autoCapitalize="none"
          autoCorrect={false}
          style={styles.search}
        />
        {query !== '' && (
          <Pressable onPress={() => setQuery('')} hitSlop={10} accessibilityLabel="Effacer la recherche">
            <Ionicons name="close-circle" size={18} color={COLORS.textSubtle} />
          </Pressable>
        )}
      </View>

      <View style={styles.filters}>
        {([
          { key: 'all', label: `Tous (${rows.length})` },
          { key: 'missing', label: `À compléter (${incomplete})` },
        ] as { key: Filter; label: string }[]).map((f) => {
          const active = filter === f.key;
          return (
            <Pressable
              key={f.key}
              onPress={() => setFilter(f.key)}
              accessibilityRole="button"
              accessibilityState={{ selected: active }}
              style={[styles.chip, active && styles.chipActive]}
            >
              <Text style={[styles.chipLabel, active && styles.chipLabelActive]}>{f.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <FlatList
        data={shown}
        keyExtractor={(y) => String(y.id)}
        contentContainerStyle={styles.list}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={
          rows.length === 0
            ? <EmptyState icon="🧒" title="Aucun jeune" message="Aucun adhérent n'a le profil Jeune pour le moment." />
            : <EmptyState icon="✅" title="Rien à afficher" message={filter === 'missing' ? 'Tous les documents sont à jour.' : 'Essayez une autre orthographe.'} />
        }
        renderItem={({ item }) => (
          <Pressable
            onPress={() => router.push(('/youth-documents/' + item.id) as never)}
            accessibilityRole="button"
            accessibilityLabel={`Documents de ${item.prenom} ${item.nom}`}
            style={({ pressed }) => [styles.row, pressed && { opacity: 0.85 }]}
          >
            <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} size={44} />
            <View style={{ flex: 1, gap: 6 }}>
              <Text style={styles.name} numberOfLines={1}>
                <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
                {item.age !== null ? <Text style={styles.age}>  {item.age} ans</Text> : null}
              </Text>
              <View style={styles.docs}>
                {KINDS.map((k) => {
                  const doc = item.documents[k.kind];
                  const ok = isOk(doc);
                  return (
                    <Pressable
                      key={k.kind}
                      // Un document déposé s'ouvre directement ; sinon on ouvre la fiche pour le déposer.
                      onPress={() => (doc
                        ? void openAttachment((token) => withBearer(doc.url, token))
                        : router.push(('/youth-documents/' + item.id) as never))}
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
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  searchWrap: {
    flexDirection: 'row', alignItems: 'center', gap: 8, margin: SPACING.md, marginBottom: 4, paddingHorizontal: 12,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  filters: { flexDirection: 'row', gap: 6, marginHorizontal: SPACING.md, marginVertical: 6 },
  chip: { paddingHorizontal: 12, paddingVertical: 7, borderRadius: RADIUS.full, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface },
  chipActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  chipLabel: { fontSize: 12, fontWeight: '600', color: COLORS.text },
  chipLabelActive: { color: '#fff' },
  list: { paddingBottom: SPACING.xxl },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: SPACING.md, paddingVertical: 10,
    backgroundColor: COLORS.surface, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border,
  },
  name: { fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  age: { fontSize: 12, color: COLORS.textMuted },
  docs: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  doc: { flexDirection: 'row', alignItems: 'center', gap: 5, paddingHorizontal: 9, paddingVertical: 5, borderRadius: RADIUS.full, borderWidth: 1 },
  docOk: { backgroundColor: '#dcfce7', borderColor: '#86efac' },
  docMissing: { backgroundColor: '#fee2e2', borderColor: '#fca5a5' },
  docExpired: { backgroundColor: '#ffedd5', borderColor: '#fdba74' },
  docIcon: { fontSize: 13 },
  docLabel: { fontSize: 12, fontWeight: '700' },
  docLabelOk: { color: '#166534' },
  docLabelMissing: { color: '#991b1b' },
  docLabelExpired: { color: '#9a3412' },
});
