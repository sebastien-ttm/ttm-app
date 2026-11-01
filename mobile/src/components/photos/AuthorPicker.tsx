import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Modal, Pressable, SafeAreaView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { photos as photosApi } from '@/api/resources';
import type { PhotoAuthor } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';

/** Pour la recherche : sans accents ni casse (« élodie » trouve « Elodie »). */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Choix de l'adhérent au nom de qui le staff publie des photos : une seule personne, avec recherche, ou « Moi-même »
 * (`onPick(null)`). L'annuaire n'est chargé qu'à la première ouverture.
 */
export function AuthorPicker({ visible, selectedId, onPick, onClose }: {
  visible: boolean;
  selectedId: number | null;
  onPick: (author: PhotoAuthor | null) => void;
  onClose: () => void;
}) {
  const [members, setMembers] = useState<PhotoAuthor[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');

  const load = useCallback(() => {
    setError(null);
    photosApi.authors()
      .then((r) => setMembers(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : 'Erreur de chargement'));
  }, []);

  useEffect(() => {
    if (!visible) return;
    setQuery('');
    if (members === null) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);

  const rows = useMemo(() => {
    const q = normalize(query.trim());
    return (members ?? []).filter(
      (m) => q === '' || normalize(`${m.nom} ${m.prenom}`).includes(q) || normalize(`${m.prenom} ${m.nom}`).includes(q),
    );
  }, [members, query]);

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={styles.root}>
        <View style={styles.header}>
          <Pressable onPress={onClose} hitSlop={10} accessibilityRole="button" accessibilityLabel="Annuler">
            <Text style={styles.cancel}>Annuler</Text>
          </Pressable>
          <Text style={styles.title} numberOfLines={1}>Publier au nom de…</Text>
          <View style={{ width: 56 }} />
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
            ListHeaderComponent={
              <Pressable
                onPress={() => onPick(null)}
                accessibilityRole="button"
                style={({ pressed }) => [styles.row, selectedId === null && styles.rowOn, pressed && { opacity: 0.85 }]}
              >
                <View style={styles.selfIcon}>
                  <Ionicons name="person" size={22} color={COLORS.primary} />
                </View>
                <Text style={styles.name} numberOfLines={1}>Moi-même</Text>
                {selectedId === null && <Ionicons name="checkmark-circle" size={24} color={COLORS.primary} />}
              </Pressable>
            }
            ListEmptyComponent={<Text style={styles.empty}>Aucun adhérent trouvé.</Text>}
            renderItem={({ item }) => {
              const on = item.id === selectedId;
              return (
                <Pressable
                  onPress={() => onPick(item)}
                  accessibilityRole="button"
                  accessibilityState={{ selected: on }}
                  style={({ pressed }) => [styles.row, on && styles.rowOn, pressed && { opacity: 0.85 }]}
                >
                  <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} size={40} />
                  <Text style={styles.name} numberOfLines={1}>
                    <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
                  </Text>
                  {on && <Ionicons name="checkmark-circle" size={24} color={COLORS.primary} />}
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
  cancel: { fontSize: 15, color: COLORS.textMuted, fontWeight: '600', width: 56 },
  title: { flex: 1, textAlign: 'center', fontSize: 16, fontWeight: '700', color: COLORS.text },
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
  selfIcon: {
    width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center',
    backgroundColor: COLORS.primarySoft,
  },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  empty: { textAlign: 'center', color: COLORS.textMuted, padding: SPACING.xl },
});
