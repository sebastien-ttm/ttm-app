import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { events as eventsApi } from '@/api/resources';
import type { AttendanceStatus, EventAttendees as EventAttendeesData } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';

const TABS: { key: AttendanceStatus; icon: string; label: string; empty: string }[] = [
  { key: 'yes', icon: '✅', label: 'Présents', empty: 'Personne ne s\'est encore inscrit.' },
  { key: 'maybe', icon: '❓', label: 'Peut-être', empty: 'Personne n\'a répondu « peut-être ».' },
  { key: 'no', icon: '❌', label: 'Absents', empty: 'Personne n\'a indiqué être absent.' },
];

/**
 * « Qui vient ? » d'un événement soumis au vote de présence : les adhérents inscrits, ceux qui
 * viennent peut-être et ceux qui ne viennent pas, avec leur photo. Visible de tous ceux qui voient
 * l'événement, qu'ils aient voté ou non. `reloadKey` change après un vote ou une actualisation de
 * la page : la liste est alors rechargée.
 */
export function EventAttendees({ eventId, reloadKey }: { eventId: number; reloadKey: number }) {
  const { user } = useAuth();
  const [data, setData] = useState<EventAttendeesData | null>(null);
  const [failed, setFailed] = useState(false);
  const [tab, setTab] = useState<AttendanceStatus>('yes');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const result = await eventsApi.attendees(eventId);
        if (!cancelled) {
          setData(result);
          setFailed(false);
        }
      } catch {
        if (!cancelled) setFailed(true);
      }
    })();
    return () => { cancelled = true; };
  }, [eventId, reloadKey]);

  // Information annexe : en cas d'erreur sans données, on n'encombre pas la page.
  if (failed && !data) return null;
  if (!data) return <ActivityIndicator color={COLORS.secondary} style={{ marginVertical: SPACING.md }} />;

  const current = TABS.find((t) => t.key === tab) ?? TABS[0];
  const people = data[tab];

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Qui vient ?</Text>

      <View style={styles.tabs}>
        {TABS.map((t) => {
          const active = t.key === tab;
          return (
            <Pressable
              key={t.key}
              onPress={() => setTab(t.key)}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              style={[styles.tab, active && styles.tabActive]}
            >
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]} numberOfLines={1}>
                {t.icon} {t.label} ({data[t.key].length})
              </Text>
            </Pressable>
          );
        })}
      </View>

      {people.length === 0 ? (
        <Text style={styles.empty}>{current.empty}</Text>
      ) : (
        people.map((p) => (
          <View key={p.id} style={styles.row}>
            <MemberAvatar prenom={p.prenom} nom={p.nom} avatarUrl={p.avatarUrl} size={34} />
            <Text style={styles.name} numberOfLines={1}>
              {p.prenom} <Text style={styles.nom}>{p.nom.toUpperCase()}</Text>
              {p.id === user?.id ? <Text style={styles.me}>  (vous)</Text> : null}
            </Text>
          </View>
        ))
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.lg,
    padding: SPACING.md,
    marginTop: SPACING.md,
  },
  title: { fontSize: 15, fontWeight: '800', color: COLORS.text, marginBottom: SPACING.sm },
  tabs: { flexDirection: 'row', gap: 6, marginBottom: SPACING.sm },
  tab: {
    flex: 1,
    alignItems: 'center',
    paddingVertical: 8,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surfaceAlt,
  },
  tabActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  tabLabel: { fontSize: 12, fontWeight: '700', color: COLORS.text },
  tabLabelActive: { color: '#fff' },
  empty: { fontSize: 13, color: COLORS.textMuted, paddingVertical: SPACING.sm },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 6 },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  me: { color: COLORS.textMuted, fontSize: 13 },
});
