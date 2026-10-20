import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Modal, Pressable, SafeAreaView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { staffDirectory } from '@/api/resources';
import type { StaffMember } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Pour la recherche : sans accents ni casse (« élodie » trouve « Elodie »). */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Choix des adhérents d'un groupe : l'annuaire des adhérents (nom, prénom, photo), avec recherche, et une case par
 * personne. `excludeIds` : personnes qui ne peuvent pas être choisies (les encadrants du groupe). `onConfirm` reçoit
 * la sélection complète (une personne sélectionnée avant l'ouverture et absente de l'annuaire est conservée).
 */
export function MemberPicker({
  visible,
  selectedIds,
  excludeIds = [],
  title = 'Choisir les adhérents',
  onConfirm,
  onClose,
}: {
  visible: boolean;
  selectedIds: number[];
  excludeIds?: number[];
  title?: string;
  /** Sélection complète : identifiants, et fiches annuaire de ces adhérents (pour les afficher sans les recharger). */
  onConfirm: (ids: number[], picked: StaffMember[]) => void;
  onClose: () => void;
}) {
  const [members, setMembers] = useState<StaffMember[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState<Set<number>>(new Set(selectedIds));

  const load = useCallback(() => {
    setError(null);
    staffDirectory.list()
      .then((r) => setMembers(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : 'Erreur de chargement'));
  }, []);

  // L'annuaire n'est chargé qu'à la première ouverture ; la sélection repart de celle du groupe à chaque ouverture.
  useEffect(() => {
    if (!visible) return;
    setSelected(new Set(selectedIds));
    setQuery('');
    if (members === null) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  const rows = useMemo(() => {
    const excluded = new Set(excludeIds);
    const q = normalize(query.trim());
    return (members ?? []).filter((m) => {
      if (excluded.has(m.id)) return false;
      return q === '' || normalize(`${m.nom} ${m.prenom}`).includes(q) || normalize(`${m.prenom} ${m.nom}`).includes(q);
    });
  }, [members, query, excludeIds]);

  function toggle(id: number) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={styles.root}>
        <View style={styles.header}>
          <Pressable onPress={onClose} hitSlop={10} accessibilityRole="button" accessibilityLabel="Annuler">
            <Text style={styles.cancel}>Annuler</Text>
          </Pressable>
          <Text style={styles.title} numberOfLines={1}>{title}</Text>
          <Pressable
            onPress={() => onConfirm([...selected], (members ?? []).filter((m) => selected.has(m.id)))}
            hitSlop={10}
            accessibilityRole="button"
            style={({ pressed }) => [styles.confirm, pressed && { opacity: 0.8 }]}
          >
            <Text style={styles.confirmLabel}>OK · {selected.size}</Text>
          </Pressable>
        </View>

        <View style={styles.searchWrap}>
          <Ionicons name="search" size={18} color={COLORS.textMuted} />
          <TextInput
            value={query}
            onChangeText={setQuery}
            placeholder="Rechercher un nom ou un prénom"
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

        {members === null ? (
          error ? <ErrorState message={error} onRetry={load} /> : <FullScreenLoading />
        ) : (
          <FlatList
            data={rows}
            keyExtractor={(m) => String(m.id)}
            keyboardShouldPersistTaps="handled"
            ListEmptyComponent={<Text style={styles.empty}>Aucun adhérent trouvé.</Text>}
            renderItem={({ item }) => {
              const on = selected.has(item.id);
              return (
                <Pressable
                  onPress={() => toggle(item.id)}
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: on }}
                  style={({ pressed }) => [styles.row, on && styles.rowOn, pressed && { opacity: 0.85 }]}
                >
                  <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} size={40} />
                  <Text style={styles.name} numberOfLines={1}>
                    <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
                  </Text>
                  <Ionicons
                    name={on ? 'checkbox' : 'square-outline'}
                    size={24}
                    color={on ? COLORS.primary : COLORS.textMuted}
                  />
                </Pressable>
              );
            }}
          />
        )}
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12,
    paddingHorizontal: SPACING.md, paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  cancel: { fontSize: 15, color: COLORS.textMuted, fontWeight: '600' },
  title: { flex: 1, textAlign: 'center', fontSize: 16, fontWeight: '700', color: COLORS.text },
  confirm: { backgroundColor: COLORS.primary, borderRadius: RADIUS.full, paddingHorizontal: 14, paddingVertical: 7 },
  confirmLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
  searchWrap: {
    flexDirection: 'row', alignItems: 'center', gap: 8, margin: SPACING.md, paddingHorizontal: 12,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: SPACING.md, paddingVertical: 8, backgroundColor: COLORS.surface,
    borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border,
  },
  rowOn: { backgroundColor: COLORS.primarySoft },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  empty: { textAlign: 'center', color: COLORS.textMuted, padding: SPACING.xl },
});
