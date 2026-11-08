import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { volunteer as volunteerApi } from '@/api/resources';
import type { VolunteerApplication } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { StatusChip, VOL } from '@/components/volunteer/ui';

/** Mes candidatures sur la campagne de bénévolat en cours, avec leur état. */
export default function VolunteerMineScreen() {
  const router = useRouter();
  const [items, setItems] = useState<VolunteerApplication[] | null>(null);
  const [campaignName, setCampaignName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      setError(null);
      const { campaign } = await volunteerApi.current();
      if (!campaign) {
        setItems([]);
        return;
      }
      setCampaignName(campaign.name);
      setItems((await volunteerApi.mine(campaign.id)).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (items === null) return <FullScreenLoading />;

  return (
    <View style={styles.root}>
      <Stack.Screen options={{ title: 'Mes candidatures' }} />
      <FlatList
        data={items}
        keyExtractor={(a) => String(a.id)}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        ListHeaderComponent={campaignName ? <Text style={styles.header}>{campaignName}</Text> : null}
        ListEmptyComponent={
          <EmptyState icon="🙌" title="Aucune candidature" message="Ouvre une fiche et appuie sur « Je me positionne »." />
        }
        renderItem={({ item }) => (
          <Pressable
            disabled={!item.role.published}
            onPress={() => router.push(('/volunteer/role/' + item.role.id) as never)}
            style={({ pressed }) => [styles.card, pressed && { opacity: 0.8 }]}
          >
            <View style={{ flex: 1, gap: 4 }}>
              <Text style={styles.kicker}>
                {item.role.kind === 'field' ? 'Responsable terrain' : 'Activité'} · Pôle {item.role.poleName}
              </Text>
              <Text style={styles.name}>{item.role.name}</Text>
              <StatusChip status={item.status} />
              {item.assigned ? <Text style={styles.meta}>Ajouté(e) par un responsable du pôle</Text> : null}
              {item.message ? <Text style={styles.meta}>Ton message : « {item.message} »</Text> : null}
            </View>
            {item.role.published ? <Ionicons name="chevron-forward" size={18} color={VOL.muted} /> : null}
          </Pressable>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: VOL.cream },
  list: { padding: 14, gap: 8, maxWidth: 760, width: '100%', alignSelf: 'center' },
  header: { fontSize: 13, fontWeight: '700', color: VOL.muted, marginBottom: 4 },
  card: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: '#fff', borderRadius: 12, padding: 12, borderWidth: 1, borderColor: VOL.border },
  kicker: { fontSize: 10.5, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: VOL.muted },
  name: { fontSize: 16, fontWeight: '800', color: VOL.navy },
  meta: { fontSize: 12.5, color: VOL.muted },
});
