import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Linking,
  Platform,
  Pressable,
  RefreshControl,
  SectionList,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { ApiError } from '@/api/client';
import { staffDirectory } from '@/api/resources';
import type { StaffMember } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatPhoneFr, telHref } from '@/utils/phone';

/** Pour la recherche : sans accents ni casse (« élodie » trouve « Elodie »). */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Onglet « Adhérents » de l'espace Gestion : tous les adhérents actifs avec
 * nom, prénom et téléphone, et un bouton d'appel (urgences). Regroupés par
 * initiale du nom, avec une recherche. Réservé au staff (le serveur
 * revérifie le profil).
 */
export function MembersTab() {
  const [members, setMembers] = useState<StaffMember[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');

  const load = useCallback(async () => {
    try {
      setError(null);
      setMembers((await staffDirectory.list()).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    (async () => {
      await load();
      setLoading(false);
    })();
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  const sections = useMemo(() => {
    const q = normalize(query.trim());
    const filtered = q === ''
      ? members
      : members.filter((m) => normalize(`${m.nom} ${m.prenom}`).includes(q) || normalize(`${m.prenom} ${m.nom}`).includes(q));

    const byLetter = new Map<string, StaffMember[]>();
    for (const m of filtered) {
      const letter = normalize(m.nom.trim()).charAt(0).toUpperCase() || '#';
      const key = /[A-Z]/.test(letter) ? letter : '#';
      byLetter.set(key, [...(byLetter.get(key) ?? []), m]);
    }
    return Array.from(byLetter.entries())
      .sort(([a], [b]) => (a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b)))
      .map(([title, data]) => ({ title, data }));
  }, [members, query]);

  const total = sections.reduce((n, s) => n + s.data.length, 0);

  async function call(member: StaffMember) {
    if (!member.telephone) return;
    try {
      await Linking.openURL(telHref(member.telephone));
    } catch {
      const msg = `Impossible de lancer l'appel vers ${member.prenom} ${member.nom}.`;
      if (Platform.OS === 'web') window.alert(msg);
      else Alert.alert('Appel impossible', msg);
    }
  }

  if (loading) return <FullScreenLoading />;
  if (error && members.length === 0) return <ErrorState message={error} onRetry={load} />;

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
      <Text style={styles.count}>{total} adhérent{total > 1 ? 's' : ''}</Text>

      <SectionList
        sections={sections}
        keyExtractor={(m) => String(m.id)}
        stickySectionHeadersEnabled
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={
          <EmptyState icon="🔎" title="Aucun adhérent trouvé" message="Essayez une autre orthographe." />
        }
        renderSectionHeader={({ section }) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionLetter}>{section.title}</Text>
          </View>
        )}
        renderItem={({ item }) => (
          <View style={styles.row}>
            <View style={{ flex: 1 }}>
              <Text style={styles.name} numberOfLines={1}>
                <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
              </Text>
              {item.telephone ? (
                <>
                  <Text style={styles.phone}>{formatPhoneFr(item.telephone)}</Text>
                  {item.telephoneOf && <Text style={styles.phoneOf}>Numéro de {item.telephoneOf} (parent)</Text>}
                </>
              ) : (
                <Text style={styles.noPhone}>Numéro non renseigné</Text>
              )}
            </View>
            {item.telephone && (
              <Pressable
                onPress={() => call(item)}
                accessibilityRole="button"
                accessibilityLabel={`Appeler ${item.prenom} ${item.nom}${item.telephoneOf ? ` (via ${item.telephoneOf})` : ''}`}
                style={({ pressed }) => [styles.callBtn, pressed && { opacity: 0.75 }]}
              >
                <Ionicons name="call" size={20} color="#fff" />
              </Pressable>
            )}
          </View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    margin: SPACING.md,
    marginBottom: 4,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  count: { fontSize: 12, color: COLORS.textMuted, marginHorizontal: SPACING.md, marginBottom: 4 },
  listContent: { paddingBottom: SPACING.xxl },
  sectionHeader: { backgroundColor: COLORS.background, paddingHorizontal: SPACING.md, paddingVertical: 4 },
  sectionLetter: { fontSize: 13, fontWeight: '800', color: COLORS.secondaryDark },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.md,
    paddingVertical: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  name: { fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  phone: { fontSize: 14, color: COLORS.secondaryDark, marginTop: 2, fontVariant: ['tabular-nums'] },
  phoneOf: { fontSize: 12, color: COLORS.textMuted, marginTop: 1 },
  noPhone: { fontSize: 13, color: COLORS.textSubtle, fontStyle: 'italic', marginTop: 2 },
  callBtn: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: COLORS.success,
  },
});
