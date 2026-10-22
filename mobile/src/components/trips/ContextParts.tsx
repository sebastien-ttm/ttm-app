import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import type { ComponentProps, ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { TripParticipant, TripSummary } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { MemberAvatar } from '@/components/MemberAvatar';
import { tripDatesLabel, tripPhase, tripStatusLabel } from '@/utils/trip';

/**
 * Briques communes des vues de contexte d'un déplacement (/trip/{id}) et d'un stage (/stage/{id}) : en-tête,
 * sections, liste des participants et cartes « Mes déplacements / Mes stages » de l'accueil. Un stage a le même
 * résumé qu'un déplacement (TripSummary).
 */

type IoniconName = ComponentProps<typeof Ionicons>['name'];

/** En-tête : pastille (emoji ou icône de repli), titre, dates, lieu et état (« Dans 12 jours », « En cours »…). */
export function ContextHero({ item, fallbackIcon }: { item: TripSummary; fallbackIcon: IoniconName }) {
  const phase = tripPhase(item);
  return (
    <View style={styles.hero}>
      <View style={styles.heroIcon}>
        {item.icon ? <Text style={styles.heroEmoji}>{item.icon}</Text> : <Ionicons name={fallbackIcon} size={30} color="#fff" />}
      </View>
      <View style={{ flex: 1, gap: 4 }}>
        <Text style={styles.heroTitle}>{item.title}</Text>
        <View style={styles.heroLine}>
          <Ionicons name="calendar-outline" size={14} color={COLORS.textMuted} />
          <Text style={styles.heroMeta}>{tripDatesLabel(item)}</Text>
        </View>
        {item.location ? (
          <View style={styles.heroLine}>
            <Ionicons name="location-outline" size={14} color={COLORS.textMuted} />
            <Text style={styles.heroMeta}>{item.location}</Text>
          </View>
        ) : null}
        <View style={[styles.status, phase === 'past' && styles.statusPast]}>
          <Text style={[styles.statusLabel, phase === 'past' && styles.statusLabelPast]}>{tripStatusLabel(item)}</Text>
        </View>
      </View>
    </View>
  );
}

export function ContextSection({ title, icon, children }: { title: string; icon: IoniconName; children: ReactNode }) {
  return (
    <View style={styles.section}>
      <View style={styles.sectionHeader}>
        <Ionicons name={icon} size={18} color={COLORS.primary} />
        <Text style={styles.sectionTitle}>{title}</Text>
      </View>
      {children}
    </View>
  );
}

/** Participants : photo (ou initiales) et nom, sans coordonnées. */
export function ParticipantsGrid({ participants }: { participants: TripParticipant[] }) {
  return (
    <View style={styles.people}>
      {participants.map((p) => (
        <View key={p.id} style={styles.person}>
          <MemberAvatar prenom={p.prenom} nom={p.nom} avatarUrl={p.avatarUrl} size={44} />
          <Text style={styles.personName} numberOfLines={2}>{p.prenom} {p.nom}</Text>
        </View>
      ))}
    </View>
  );
}

/**
 * Accueil : cartes cliquables vers la vue de contexte (`hrefPrefix` + id). Rien n'est affiché sans élément.
 * `heading` est déjà au bon nombre (« Mon stage » / « Mes stages »).
 */
export function ContextCardList({
  heading,
  items,
  hrefPrefix,
  fallbackIcon,
  accessibilityKind,
}: {
  heading: string;
  items: TripSummary[];
  hrefPrefix: string;
  fallbackIcon: IoniconName;
  accessibilityKind: string;
}) {
  const router = useRouter();
  if (items.length === 0) return null;

  return (
    <View style={styles.cardsSection}>
      <Text style={styles.cardsHeading}>{heading}</Text>
      {items.map((t) => {
        const phase = tripPhase(t);
        return (
          <Pressable
            key={t.id}
            onPress={() => router.push((hrefPrefix + t.id) as never)}
            accessibilityRole="button"
            accessibilityLabel={`${accessibilityKind} ${t.title}`}
            style={({ pressed }) => [styles.card, phase === 'past' && styles.cardPast, pressed && { opacity: 0.75 }]}
          >
            <View style={[styles.cardIcon, phase === 'past' && styles.cardIconPast]}>
              {t.icon ? <Text style={styles.cardEmoji}>{t.icon}</Text> : <Ionicons name={fallbackIcon} size={22} color="#fff" />}
            </View>
            <View style={{ flex: 1, gap: 2 }}>
              <Text style={styles.cardTitle} numberOfLines={2}>{t.title}</Text>
              <Text style={styles.cardSub} numberOfLines={2}>
                {tripDatesLabel(t)}{t.location ? ` · ${t.location}` : ''}
              </Text>
              <Text style={[styles.cardStatus, phase === 'past' && styles.cardStatusPast]}>{tripStatusLabel(t)}</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  hero: {
    flexDirection: 'row', gap: 14, alignItems: 'center', padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
    borderLeftWidth: 4, borderLeftColor: COLORS.secondary,
  },
  heroIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center' },
  heroEmoji: { fontSize: 30 },
  heroTitle: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  heroLine: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  heroMeta: { fontSize: 13, color: COLORS.textMuted, flexShrink: 1 },
  status: { alignSelf: 'flex-start', marginTop: 4, paddingHorizontal: 10, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.secondary },
  statusPast: { backgroundColor: COLORS.border },
  statusLabel: { fontSize: 12, fontWeight: '800', color: '#fff' },
  statusLabelPast: { color: COLORS.textMuted },
  section: { gap: SPACING.sm },
  sectionHeader: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  sectionTitle: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  people: { flexDirection: 'row', flexWrap: 'wrap', gap: 14 },
  person: { width: 76, alignItems: 'center', gap: 4 },
  personName: { fontSize: 11, color: COLORS.text, textAlign: 'center' },
  cardsSection: { gap: SPACING.sm, marginTop: SPACING.md, paddingHorizontal: SPACING.md },
  cardsHeading: { fontSize: 13, fontWeight: '800', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderLeftWidth: 4, borderLeftColor: COLORS.secondary,
    borderWidth: 1, borderColor: COLORS.border,
  },
  cardPast: { borderLeftColor: COLORS.border },
  cardIcon: { width: 42, height: 42, borderRadius: 21, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center' },
  cardIconPast: { backgroundColor: COLORS.textMuted },
  cardEmoji: { fontSize: 22 },
  cardTitle: { fontSize: 16, fontWeight: '800', color: COLORS.text },
  cardSub: { fontSize: 12, color: COLORS.textMuted },
  cardStatus: { fontSize: 12, color: COLORS.secondaryDark, fontWeight: '700' },
  cardStatusPast: { color: COLORS.textMuted },
});
