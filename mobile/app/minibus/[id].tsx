import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { minibus as minibusApi } from '@/api/resources';
import type { MinibusBookingDetail, MinibusCheck, MinibusCheckType } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { CheckRow } from '@/components/minibus/CheckRow';
import { MinibusCheckForm } from '@/components/minibus/MinibusCheckForm';
import { RichContent } from '@/components/RichContent';
import { COLORS, RADIUS, SPACING } from '@/config';
import { openAttachment, withBearer } from '@/lib/openAttachment';
import { confirmAction } from '@/utils/confirm';
import { MINIBUS_STATUS_STYLE, formatBookingRange, isoOfDate, stepToDo } from '@/utils/minibus';

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
const fmtDateTime = (iso: string) => {
  const d = new Date(iso);
  return `${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} à ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
};

/**
 * Détail d'une demande d'emprunt du minibus :
 *  - le résumé (dates, emprunteur, raison, remorque) et la réponse du gestionnaire ;
 *  - pour le gestionnaire, la décision (valider / refuser, avec un message) en signalant les demandes qui se chevauchent ;
 *  - dès que la demande est validée, la charte d'utilisation, que l'emprunteur lit et accepte ;
 *  - l'état des lieux de départ puis de retour (photos, kilométrage, plein et nettoyage au retour, comment cela s'est passé,
 *    problèmes) que remplit l'emprunteur — consultables par tous les membres du CoDir ;
 *  - l'annulation tant qu'aucun état des lieux n'est enregistré.
 */
export default function MinibusDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const bookingId = Number(id);
  const [booking, setBooking] = useState<MinibusBookingDetail | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const [charterOpen, setCharterOpen] = useState<boolean | null>(null);
  const [charterRead, setCharterRead] = useState(false);
  const [editing, setEditing] = useState<MinibusCheckType | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setBooking(await minibusApi.get(bookingId));
      // Le jeton sert aux photos (<img> sans en-tête Authorization) : relu après l'appel, qui a pu le rafraîchir.
      setToken(await storage.getItem(STORAGE_KEYS.accessToken));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setRefreshing(false);
    }
  }, [bookingId]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  /** Lance une action, remplace la demande par la réponse du serveur et montre l'erreur éventuelle. */
  async function run(task: () => Promise<MinibusBookingDetail>) {
    setBusy(true);
    setActionError(null);
    try {
      setBooking(await task());
      setToken(await storage.getItem(STORAGE_KEYS.accessToken));
    } catch (e) {
      setActionError(e instanceof ApiError ? e.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  }

  async function cancel() {
    if (!(await confirmAction('Annuler cette demande ?', 'Le minibus sera de nouveau libre sur ces dates.', 'Annuler la demande'))) return;
    await run(() => minibusApi.cancel(bookingId));
  }

  async function removePhoto(photoId: number) {
    if (!(await confirmAction('Supprimer cette photo ?', 'Elle disparaîtra de l\'état des lieux.', 'Supprimer'))) return;
    await run(() => minibusApi.deletePhoto(photoId));
  }

  // Le titre de l'en-tête est posé dès le chargement (sinon la barre affiche brièvement « minibus/[id] »).
  if (error) return <><Stack.Screen options={{ title: 'Emprunt du minibus' }} /><ErrorState message={error} onRetry={load} /></>;
  if (booking === null) return <><Stack.Screen options={{ title: 'Emprunt du minibus' }} /><FullScreenLoading /></>;

  const b = booking;
  const s = MINIBUS_STATUS_STYLE[b.status];
  const todo = b.isMine ? stepToDo(b.step) : null;
  const today = isoOfDate(new Date());
  // La charte est dépliée tant qu'elle n'est pas acceptée ; ensuite elle se relit à la demande.
  const showCharter = charterOpen ?? b.can.acceptCharter;

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Emprunt du minibus' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.content}
          keyboardShouldPersistTaps="handled"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
        >
          {/* Résumé */}
          <View style={styles.card}>
            <View style={styles.row}>
              <Text style={styles.dates}>{formatBookingRange(b)}</Text>
              <View style={[styles.pill, { backgroundColor: s.bg }]}>
                <Text style={[styles.pillText, { color: s.fg }]}>{b.status === 'approved' && b.step === 'done' ? 'Terminé' : b.statusLabel}</Text>
              </View>
            </View>
            <Text style={styles.who}>{b.requesterName}{b.isMine ? ' (moi)' : ''}</Text>
            <Text style={styles.reason}>{b.reason}</Text>
            <View style={styles.row}>
              {b.withTrailer && <View style={[styles.pill, { backgroundColor: COLORS.secondarySoft }]}><Text style={[styles.pillText, { color: COLORS.secondaryDark }]}>Avec remorque</Text></View>}
              {b.hasProblem && <View style={[styles.pill, { backgroundColor: '#fee2e2' }]}><Text style={[styles.pillText, { color: '#991b1b' }]}>⚠ Problème signalé</Text></View>}
            </View>
            <Text style={styles.meta}>Demandé le {fmtDate(b.createdAt)}</Text>
            {b.decidedAt && (
              <Text style={styles.meta}>
                {b.status === 'approved' ? 'Validée' : b.status === 'refused' ? 'Refusée' : 'Traitée'} par {b.decidedByName ?? 'le gestionnaire'} le {fmtDate(b.decidedAt)}
              </Text>
            )}
            {b.decisionNote ? (
              <View style={styles.noteBox}>
                <Text style={styles.noteLabel}>Message du gestionnaire</Text>
                <Text style={styles.noteText}>{b.decisionNote}</Text>
              </View>
            ) : null}
          </View>

          {todo && (
            <View style={styles.todo}>
              <Ionicons name="alert-circle" size={18} color="#92400e" />
              <Text style={styles.todoText}>À faire : {todo}</Text>
            </View>
          )}
          {actionError && <Text style={styles.error}>{actionError}</Text>}

          {/* Décision du gestionnaire */}
          {b.can.decide && (
            <View style={styles.card}>
              <Text style={styles.sectionTitle}>Décision du gestionnaire</Text>
              {b.conflicts.length > 0 && (
                <View style={styles.conflict}>
                  <Text style={styles.conflictTitle}>⚠ Chevauche d'autres demandes</Text>
                  {b.conflicts.map((c) => (
                    <Text key={c.id} style={styles.conflictLine}>
                      {c.requesterName} · {formatBookingRange(c)} · {c.status === 'approved' ? 'validée' : 'en attente'}
                    </Text>
                  ))}
                </View>
              )}
              <TextInput
                value={note}
                onChangeText={setNote}
                placeholder="Message à l'emprunteur (facultatif : motif d'un refus, remise des clés…)"
                placeholderTextColor={COLORS.textSubtle}
                multiline
                maxLength={1000}
                style={[styles.input, styles.noteInput]}
                editable={!busy}
              />
              <View style={styles.decisionRow}>
                <Pressable onPress={() => void run(() => minibusApi.decide(b.id, 'approve', note))} disabled={busy} style={[styles.approveBtn, busy && { opacity: 0.5 }]}>
                  <Text style={styles.decisionLabel}>Valider</Text>
                </Pressable>
                <Pressable onPress={() => void run(() => minibusApi.decide(b.id, 'refuse', note))} disabled={busy} style={[styles.refuseBtn, busy && { opacity: 0.5 }]}>
                  <Text style={styles.decisionLabel}>Refuser</Text>
                </Pressable>
              </View>
            </View>
          )}

          {/* Charte d'utilisation : présentée dès que la demande est validée */}
          {b.charter && (
            <View style={styles.card}>
              <Pressable onPress={() => setCharterOpen(!showCharter)} style={styles.row} accessibilityRole="button">
                <Ionicons name="document-text" size={18} color={COLORS.brandNavy} />
                <Text style={[styles.sectionTitle, { flex: 1 }]}>{b.charter.title}</Text>
                <Ionicons name={showCharter ? 'chevron-up' : 'chevron-down'} size={18} color={COLORS.textMuted} />
              </Pressable>
              {showCharter && <RichContent html={b.charter.content} style={styles.charterText} />}
              {b.can.acceptCharter ? (
                <View style={{ gap: 10, marginTop: 6 }}>
                  <Text style={styles.hint}>Lisez la charte jusqu'au bout : vous devez l'accepter avant de remplir l'état des lieux de départ.</Text>
                  <CheckRow label="J'ai lu la charte et je m'engage à la respecter" checked={charterRead} onChange={setCharterRead} disabled={busy} />
                  <Pressable onPress={() => void run(() => minibusApi.acceptCharter(b.id))} disabled={busy || !charterRead} style={[styles.primaryBtn, (busy || !charterRead) && { opacity: 0.4 }]}>
                    {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.primaryLabel}>Accepter la charte</Text>}
                  </Pressable>
                </View>
              ) : b.charterAcceptedAt ? (
                <View style={styles.row}>
                  <Ionicons name="checkmark-circle" size={16} color={COLORS.success} />
                  <Text style={styles.accepted}>Charte acceptée le {fmtDate(b.charterAcceptedAt)}</Text>
                </View>
              ) : null}
            </View>
          )}

          {/* États des lieux */}
          {(b.status === 'approved' || b.checks.departure || b.checks.return) && (
            <>
              <CheckSection
                type="departure"
                booking={b}
                token={token}
                editing={editing === 'departure'}
                onEdit={(on) => setEditing(on ? 'departure' : null)}
                onSaved={(updated) => { setBooking(updated); setEditing(null); }}
                onRemovePhoto={removePhoto}
                blockedHint={!b.isMine ? 'Pas encore rempli.' : !b.charterAcceptedAt ? 'À remplir après l\'acceptation de la charte.' : 'Disponible à partir de la veille de l\'emprunt.'}
              />
              <CheckSection
                type="return"
                booking={b}
                token={token}
                editing={editing === 'return'}
                onEdit={(on) => setEditing(on ? 'return' : null)}
                onSaved={(updated) => { setBooking(updated); setEditing(null); }}
                onRemovePhoto={removePhoto}
                blockedHint={!b.isMine ? 'Pas encore rempli.' : !b.charterAcceptedAt ? 'À remplir après l\'acceptation de la charte.' : 'À remplir au retour du minibus.'}
                // Le retour se remplit après le départ, sauf si l'emprunt est terminé (départ oublié : on ne bloque pas).
                waitForDeparture={b.isMine && !b.checks.departure && b.endDate >= today}
              />
            </>
          )}

          {b.can.cancel && (
            <Pressable onPress={() => void cancel()} disabled={busy} style={styles.cancelBtn}>
              <Text style={styles.cancelLabel}>Annuler cette demande</Text>
            </Pressable>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

/** Un état des lieux (départ ou retour) : formulaire pour l'emprunteur, sinon récapitulatif consultable. */
function CheckSection({ type, booking, token, editing, onEdit, onSaved, onRemovePhoto, blockedHint, waitForDeparture = false }: {
  type: MinibusCheckType;
  booking: MinibusBookingDetail;
  token: string | null;
  editing: boolean;
  onEdit: (on: boolean) => void;
  onSaved: (b: MinibusBookingDetail) => void;
  onRemovePhoto: (photoId: number) => void;
  /** Texte affiché quand l'état des lieux n'est pas (encore) remplissable. */
  blockedHint: string;
  waitForDeparture?: boolean;
}) {
  const check = booking.checks[type];
  const canFill = type === 'departure' ? booking.can.departureCheck : booking.can.returnCheck;
  const title = type === 'departure' ? '1. État des lieux au départ' : '2. État des lieux au retour';
  return (
    <View style={styles.card}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {check ? (
        <>
          <CheckSummary check={check} token={token} canDeletePhotos={booking.can.deletePhotos} onRemovePhoto={onRemovePhoto} />
          {canFill && !editing && (
            <Pressable onPress={() => onEdit(true)} style={styles.secondaryBtn}>
              <Text style={styles.secondaryLabel}>Compléter ou modifier</Text>
            </Pressable>
          )}
          {canFill && editing && (
            <MinibusCheckForm bookingId={booking.id} type={type} existing={check} onSaved={onSaved} onCancel={() => onEdit(false)} />
          )}
        </>
      ) : canFill && !waitForDeparture ? (
        <MinibusCheckForm bookingId={booking.id} type={type} existing={null} onSaved={onSaved} />
      ) : (
        <Text style={styles.hint}>{waitForDeparture ? 'À remplir après l\'état des lieux de départ.' : blockedHint}</Text>
      )}
    </View>
  );
}

/** Récapitulatif d'un état des lieux : qui, quand, kilométrage, plein / nettoyage, texte, problème, photos. */
function CheckSummary({ check, token, canDeletePhotos, onRemovePhoto }: {
  check: MinibusCheck;
  token: string | null;
  canDeletePhotos: boolean;
  onRemovePhoto: (photoId: number) => void;
}) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={styles.meta}>Rempli par {check.submittedByName} le {fmtDateTime(check.submittedAt)}</Text>
      {check.mileage != null && <Text style={styles.line}>Kilométrage : <Text style={styles.bold}>{check.mileage.toLocaleString('fr-FR')} km</Text></Text>}
      {check.type === 'return' && (
        <View style={{ gap: 2 }}>
          <Text style={[styles.line, { color: check.refueled ? '#166534' : '#991b1b' }]}>{check.refueled ? '✓ Plein refait' : '✗ Plein non refait'}</Text>
          <Text style={[styles.line, { color: check.cleaned ? '#166534' : '#991b1b' }]}>{check.cleaned ? '✓ Minibus nettoyé et vidé' : '✗ Minibus non nettoyé / vidé'}</Text>
        </View>
      )}
      {check.comment ? <Text style={styles.line}>{check.comment}</Text> : null}
      {check.hasProblem && (
        <View style={styles.problem}>
          <Text style={styles.problemTitle}>⚠ Problème signalé</Text>
          <Text style={styles.problemText}>{check.problemDescription ?? 'Non décrit.'}</Text>
        </View>
      )}
      <View style={styles.photoGrid}>
        {check.photos.map((p) => (
          <View key={p.id} style={styles.thumbWrap}>
            <Pressable onPress={() => void openAttachment((t) => withBearer(p.url, t))} accessibilityLabel="Ouvrir la photo">
              {token ? (
                <Image source={{ uri: withBearer(p.url, token) }} style={styles.thumb} contentFit="cover" />
              ) : (
                <View style={[styles.thumb, { backgroundColor: COLORS.border }]} />
              )}
            </Pressable>
            {canDeletePhotos && check.photos.length > 1 && (
              <Pressable onPress={() => onRemovePhoto(p.id)} style={styles.thumbRemove} accessibilityLabel="Supprimer la photo">
                <Ionicons name="close" size={14} color="#fff" />
              </Pressable>
            )}
          </View>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, gap: SPACING.md, paddingBottom: SPACING.xxl, maxWidth: 640, width: '100%', alignSelf: 'center' },
  card: { gap: 8, padding: SPACING.md, backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border },
  row: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6 },
  dates: { fontSize: 18, fontWeight: '800', color: COLORS.text, flexShrink: 1 },
  who: { fontSize: 14, fontWeight: '700', color: COLORS.brandNavy },
  reason: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  meta: { fontSize: 12.5, color: COLORS.textMuted },
  pill: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  pillText: { fontSize: 11, fontWeight: '700' },
  noteBox: { padding: 10, borderRadius: RADIUS.sm, backgroundColor: COLORS.secondarySoft, gap: 2 },
  noteLabel: { fontSize: 11, fontWeight: '800', color: COLORS.secondaryDark, textTransform: 'uppercase', letterSpacing: 0.5 },
  noteText: { fontSize: 13.5, color: COLORS.secondaryDark },
  todo: { flexDirection: 'row', alignItems: 'center', gap: 8, padding: 12, borderRadius: RADIUS.md, backgroundColor: '#fef3c7', borderWidth: 1, borderColor: COLORS.warning },
  todoText: { flex: 1, fontSize: 14, fontWeight: '700', color: '#92400e' },
  error: { color: COLORS.error, backgroundColor: '#fee2e2', padding: 12, borderRadius: RADIUS.sm, fontSize: 13, fontWeight: '500' },
  sectionTitle: { fontSize: 15, fontWeight: '800', color: COLORS.brandNavy },
  hint: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  input: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, paddingHorizontal: 14, paddingVertical: 12,
    fontSize: 15, color: COLORS.text, borderWidth: 1, borderColor: COLORS.border,
  },
  noteInput: { minHeight: 70, textAlignVertical: 'top' },
  conflict: { padding: 10, borderRadius: RADIUS.sm, backgroundColor: '#fff7ed', gap: 2 },
  conflictTitle: { fontSize: 13, fontWeight: '800', color: '#9a3412' },
  conflictLine: { fontSize: 13, color: '#9a3412' },
  decisionRow: { flexDirection: 'row', gap: 10 },
  approveBtn: { flex: 1, backgroundColor: COLORS.success, borderRadius: RADIUS.md, paddingVertical: 13, alignItems: 'center' },
  refuseBtn: { flex: 1, backgroundColor: COLORS.error, borderRadius: RADIUS.md, paddingVertical: 13, alignItems: 'center' },
  decisionLabel: { color: '#fff', fontWeight: '800', fontSize: 15 },
  charterText: { fontSize: 14, lineHeight: 21, color: COLORS.text },
  accepted: { fontSize: 13, color: '#166534', fontWeight: '600' },
  primaryBtn: { backgroundColor: COLORS.primary, borderRadius: RADIUS.md, paddingVertical: 13, alignItems: 'center' },
  primaryLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  secondaryBtn: { alignSelf: 'flex-start', paddingVertical: 8, paddingHorizontal: 12, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.secondary },
  secondaryLabel: { color: COLORS.secondary, fontWeight: '700', fontSize: 13 },
  line: { fontSize: 14, color: COLORS.text, lineHeight: 20 },
  bold: { fontWeight: '700' },
  problem: { padding: 10, borderRadius: RADIUS.sm, backgroundColor: '#fee2e2', gap: 2 },
  problemTitle: { fontSize: 13, fontWeight: '800', color: '#991b1b' },
  problemText: { fontSize: 13.5, color: '#991b1b' },
  photoGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  thumbWrap: { position: 'relative' },
  thumb: { width: 96, height: 72, borderRadius: RADIUS.sm, backgroundColor: COLORS.surface },
  thumbRemove: {
    position: 'absolute', top: -6, right: -6, backgroundColor: COLORS.error, borderRadius: 10,
    width: 20, height: 20, alignItems: 'center', justifyContent: 'center',
  },
  cancelBtn: { alignItems: 'center', paddingVertical: 14 },
  cancelLabel: { color: COLORS.error, fontSize: 14, fontWeight: '600' },
});
