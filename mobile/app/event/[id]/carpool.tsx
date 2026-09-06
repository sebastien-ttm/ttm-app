import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Alert, Linking, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { carpool as api } from '@/api/resources';
import type { CarpoolBoard, CarpoolOffer, CarpoolRole } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';

/**
 * Page covoiturage d'un événement (activée par l'admin via
 * event.carpoolingEnabled). Affiche les conducteurs positionnés
 * (places, vélos, statut voiture pleine) + les passagers demandeurs.
 * Chaque ligne autre que la sienne expose un bouton WhatsApp (si
 * l'user a un téléphone) — pas de messagerie interne.
 *
 * L'user peut se positionner via l'un des 2 boutons en tête, éditer
 * son offre s'il est conducteur (places / vélos / voiture pleine),
 * ou retirer sa proposition à tout moment.
 */
export default function CarpoolScreen() {
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const eventId = Number(rawId);
  const [board, setBoard] = useState<CarpoolBoard | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);

  const load = useCallback(async () => {
    if (!eventId) { setError('Événement invalide.'); return; }
    try {
      setError(null);
      const resp = await api.get(eventId);
      setBoard(resp);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, [eventId]);

  useEffect(() => {
    (async () => { await load(); setLoading(false); })();
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  async function offerAs(role: CarpoolRole) {
    setBusy(true);
    try {
      await api.upsert(eventId, {
        role,
        // Défauts sensés pour un conducteur — l'user pourra éditer.
        seatsAvailable: role === 'driver' ? 3 : undefined,
        bikeSlots: role === 'driver' ? 3 : undefined,
        isFull: false,
      });
      await load();
      if (role === 'driver') setEditing(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur');
    } finally {
      setBusy(false);
    }
  }

  async function removeMine() {
    const doRemove = async () => {
      setBusy(true);
      try {
        await api.remove(eventId);
        await load();
        setEditing(false);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Erreur');
      } finally {
        setBusy(false);
      }
    };
    if (Platform.OS === 'web') {
      if (typeof window !== 'undefined' && window.confirm('Retirer votre proposition ?')) await doRemove();
    } else {
      Alert.alert('Retirer votre proposition ?', 'Elle disparaîtra de la liste.', [
        { text: 'Annuler', style: 'cancel' },
        { text: 'Retirer', style: 'destructive', onPress: doRemove },
      ]);
    }
  }

  if (loading) {
    return (
      <>
        <Stack.Screen options={{ title: 'Covoiturage' }} />
        <FullScreenLoading />
      </>
    );
  }
  if (error) {
    return (
      <>
        <Stack.Screen options={{ title: 'Covoiturage' }} />
        <ErrorState message={error} onRetry={load} />
      </>
    );
  }

  const mine = board?.myOffer ?? null;

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Covoiturage' }} />
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      >
        {/* CTA principal si l'user n'a pas encore proposé */}
        {mine === null ? (
          <View style={styles.ctaCard}>
            <Text style={styles.ctaTitle}>Comment participez-vous ?</Text>
            <View style={styles.ctaRow}>
              <Pressable
                style={({ pressed }) => [styles.ctaBtn, styles.ctaDriver, pressed && { opacity: 0.7 }]}
                onPress={() => offerAs('driver')}
                disabled={busy}
              >
                <Ionicons name="car" size={22} color="#fff" />
                <Text style={styles.ctaBtnLabel}>Je propose des places</Text>
              </Pressable>
              <Pressable
                style={({ pressed }) => [styles.ctaBtn, styles.ctaPassenger, pressed && { opacity: 0.7 }]}
                onPress={() => offerAs('passenger')}
                disabled={busy}
              >
                <Ionicons name="hand-left" size={22} color="#fff" />
                <Text style={styles.ctaBtnLabel}>Je cherche une place</Text>
              </Pressable>
            </View>
          </View>
        ) : (
          <MyOfferCard
            offer={mine}
            busy={busy}
            editing={editing}
            onToggleEdit={() => setEditing((v) => !v)}
            onSave={async (seats, bikes, isFull) => {
              setBusy(true);
              try {
                await api.upsert(eventId, {
                  role: 'driver',
                  seatsAvailable: seats,
                  bikeSlots: bikes,
                  isFull,
                });
                await load();
                setEditing(false);
              } finally {
                setBusy(false);
              }
            }}
            onRemove={removeMine}
          />
        )}

        <Section title="🚗 Conducteurs" icon="car">
          {(board?.drivers ?? []).length === 0 ? (
            <Text style={styles.empty}>Personne ne propose de place pour l'instant.</Text>
          ) : (
            (board?.drivers ?? []).map((o) => (
              <OfferRow key={o.id} offer={o} isMine={o.id === mine?.id} />
            ))
          )}
        </Section>

        <Section title="🙋 Passagers" icon="hand-left">
          {(board?.passengers ?? []).length === 0 ? (
            <Text style={styles.empty}>Personne ne cherche de place pour l'instant.</Text>
          ) : (
            (board?.passengers ?? []).map((o) => (
              <OfferRow key={o.id} offer={o} isMine={o.id === mine?.id} />
            ))
          )}
        </Section>

        <Text style={styles.footerHint}>
          La mise en relation se fait via WhatsApp. Les numéros affichés proviennent des profils des adhérents.
        </Text>
      </ScrollView>
    </SafeAreaView>
  );
}

function Section({ title, children }: { title: string; icon: string; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {children}
    </View>
  );
}

function OfferRow({ offer, isMine }: { offer: CarpoolOffer; isMine: boolean }) {
  return (
    <View style={[styles.offerRow, isMine && styles.offerRowMine]}>
      <View style={{ flex: 1 }}>
        <Text style={styles.offerName}>
          {offer.fullName}{isMine ? ' (vous)' : ''}
        </Text>
        {offer.role === 'driver' && (
          <Text style={styles.offerMeta}>
            {offer.isFull ? (
              <Text style={styles.offerFull}>🚫 Voiture pleine</Text>
            ) : (
              <>
                {offer.seatsAvailable ?? 0} place{(offer.seatsAvailable ?? 0) > 1 ? 's' : ''}
                {' · '}{offer.bikeSlots ?? 0} vélo{(offer.bikeSlots ?? 0) > 1 ? 's' : ''}
              </>
            )}
          </Text>
        )}
      </View>
      {!isMine && offer.whatsappUrl && (
        <Pressable
          onPress={() => void Linking.openURL(offer.whatsappUrl!)}
          hitSlop={8}
          accessibilityLabel={`Contacter ${offer.fullName} sur WhatsApp`}
        >
          <Ionicons name="logo-whatsapp" size={24} color="#25D366" />
        </Pressable>
      )}
    </View>
  );
}

function MyOfferCard({
  offer, busy, editing, onToggleEdit, onSave, onRemove,
}: {
  offer: CarpoolOffer;
  busy: boolean;
  editing: boolean;
  onToggleEdit: () => void;
  onSave: (seats: number, bikes: number, isFull: boolean) => Promise<void>;
  onRemove: () => void;
}) {
  const [seats, setSeats] = useState(String(offer.seatsAvailable ?? 3));
  const [bikes, setBikes] = useState(String(offer.bikeSlots ?? 3));
  const [isFull, setIsFull] = useState(!!offer.isFull);

  const canEditNumbers = offer.role === 'driver';

  return (
    <View style={styles.mineCard}>
      <View style={styles.mineHeader}>
        <Text style={styles.mineTitle}>
          {offer.role === 'driver' ? '🚗 Je propose des places' : '🙋 Je cherche une place'}
        </Text>
      </View>
      {canEditNumbers && !editing && (
        <Text style={styles.mineSummary}>
          {offer.isFull ? (
            <Text style={styles.offerFull}>🚫 Voiture pleine</Text>
          ) : (
            <>
              {offer.seatsAvailable ?? 0} place{(offer.seatsAvailable ?? 0) > 1 ? 's' : ''}
              {' · '}{offer.bikeSlots ?? 0} vélo{(offer.bikeSlots ?? 0) > 1 ? 's' : ''}
            </>
          )}
        </Text>
      )}
      {canEditNumbers && editing && (
        <View style={styles.editRow}>
          <View style={styles.editCol}>
            <Text style={styles.editLabel}>Places</Text>
            <TextInput value={seats} onChangeText={setSeats}
              keyboardType="numeric" inputMode="numeric" style={styles.editInput} editable={!busy} />
          </View>
          <View style={styles.editCol}>
            <Text style={styles.editLabel}>Vélos</Text>
            <TextInput value={bikes} onChangeText={setBikes}
              keyboardType="numeric" inputMode="numeric" style={styles.editInput} editable={!busy} />
          </View>
          <Pressable
            onPress={() => setIsFull((v) => !v)}
            style={[styles.fullChip, isFull && styles.fullChipActive]}
            disabled={busy}
          >
            <Text style={[styles.fullChipLabel, isFull && styles.fullChipLabelActive]}>
              {isFull ? '🚫 Voiture pleine' : 'Marquer pleine'}
            </Text>
          </Pressable>
        </View>
      )}
      <View style={styles.mineActions}>
        {canEditNumbers && (
          editing ? (
            <Pressable
              onPress={() => void onSave(Number(seats) || 0, Number(bikes) || 0, isFull)}
              disabled={busy}
              style={({ pressed }) => [styles.saveBtn, pressed && { opacity: 0.7 }]}
            >
              <Text style={styles.saveBtnLabel}>Enregistrer</Text>
            </Pressable>
          ) : (
            <Pressable onPress={onToggleEdit} style={({ pressed }) => [styles.editBtn, pressed && { opacity: 0.7 }]}>
              <Text style={styles.editBtnLabel}>Modifier</Text>
            </Pressable>
          )
        )}
        <Pressable onPress={onRemove} disabled={busy}
          style={({ pressed }) => [styles.removeBtn, pressed && { opacity: 0.7 }]}>
          <Text style={styles.removeBtnLabel}>Retirer ma proposition</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl },
  ctaCard: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    ...SHADOWS.sm,
  },
  ctaTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.sm },
  ctaRow: { flexDirection: 'row', gap: 8 },
  ctaBtn: {
    flex: 1,
    paddingVertical: 12,
    borderRadius: RADIUS.md,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
  },
  ctaDriver: { backgroundColor: COLORS.brandNavy },
  ctaPassenger: { backgroundColor: COLORS.primary },
  ctaBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 13, textAlign: 'center' },
  mineCard: {
    backgroundColor: COLORS.primarySoft,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    borderWidth: 1.5,
    borderColor: COLORS.primary,
  },
  mineHeader: { marginBottom: 6 },
  mineTitle: { fontSize: 15, fontWeight: '700', color: COLORS.primaryDark },
  mineSummary: { fontSize: 13, color: COLORS.text, marginBottom: 8 },
  editRow: { flexDirection: 'row', gap: 8, alignItems: 'flex-end', marginTop: 4, flexWrap: 'wrap' },
  editCol: { flex: 1, minWidth: 80 },
  editLabel: { fontSize: 11, color: COLORS.textMuted, fontWeight: '600', marginBottom: 4 },
  editInput: {
    backgroundColor: '#fff', borderWidth: 1, borderColor: COLORS.border,
    borderRadius: RADIUS.sm, paddingHorizontal: 10, paddingVertical: 8, fontSize: 15,
  },
  fullChip: {
    paddingHorizontal: 10, paddingVertical: 8, borderRadius: RADIUS.sm,
    backgroundColor: '#fff', borderWidth: 1, borderColor: COLORS.border,
  },
  fullChipActive: { backgroundColor: '#fef2f2', borderColor: '#fecaca' },
  fullChipLabel: { fontSize: 12, fontWeight: '600', color: COLORS.textMuted },
  fullChipLabelActive: { color: '#991b1b' },
  mineActions: { flexDirection: 'row', gap: 8, marginTop: SPACING.sm, flexWrap: 'wrap' },
  editBtn: {
    paddingHorizontal: 12, paddingVertical: 8, borderRadius: RADIUS.sm,
    backgroundColor: '#fff', borderWidth: 1, borderColor: COLORS.border,
  },
  editBtnLabel: { fontSize: 13, fontWeight: '600', color: COLORS.text },
  saveBtn: {
    paddingHorizontal: 14, paddingVertical: 8, borderRadius: RADIUS.sm,
    backgroundColor: COLORS.brandNavy,
  },
  saveBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 13 },
  removeBtn: {
    paddingHorizontal: 12, paddingVertical: 8, borderRadius: RADIUS.sm,
    backgroundColor: 'transparent', borderWidth: 1, borderColor: COLORS.error,
  },
  removeBtnLabel: { fontSize: 13, fontWeight: '600', color: COLORS.error },
  section: { marginTop: SPACING.md },
  sectionTitle: {
    fontSize: 14, fontWeight: '700', color: COLORS.textMuted,
    marginBottom: SPACING.sm, marginLeft: 4,
    textTransform: 'uppercase', letterSpacing: 0.5,
  },
  offerRow: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    padding: SPACING.md, marginBottom: 6, ...SHADOWS.sm,
  },
  offerRowMine: { borderWidth: 1, borderColor: COLORS.primary },
  offerName: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  offerMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  offerFull: { color: '#991b1b', fontWeight: '700' },
  empty: {
    fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic',
    paddingVertical: 12, paddingHorizontal: 6,
  },
  footerHint: {
    fontSize: 12, color: COLORS.textMuted, fontStyle: 'italic',
    marginTop: SPACING.lg, textAlign: 'center', paddingHorizontal: SPACING.md,
  },
});
