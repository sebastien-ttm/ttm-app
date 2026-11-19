import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { eventOrders, events as eventsApi } from '@/api/resources';
import type { EventItem, EventOrderItem, EventPaymentInfo, EventTariffOption } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { formatPrice } from '@/utils/price';

type Notice = { kind: 'ok' | 'warn' | 'error'; text: string };

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

const euros = (cents: number) => formatPrice(cents / 100) ?? '';

function summary(order: EventOrderItem): string {
  return order.lines.map((l) => `${l.quantity} × ${l.label}`).join(', ');
}

function timeOf(iso: string): string {
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

function dateTimeOf(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/** Ouvre la page de paiement HelloAsso : dans la même fenêtre sur le web (un nouvel onglet serait bloqué après un appel réseau). */
function openPaymentPage(url: string) {
  // L'adresse vient de notre serveur (réponse de HelloAsso) : on n'ouvre que du https.
  if (!/^https:\/\//i.test(url)) return;
  if (Platform.OS === 'web' && typeof window !== 'undefined') window.location.assign(url);
  else void Linking.openURL(url);
}

/**
 * Inscription payante d'un événement (tarifs + paiement HelloAsso), sur sa page de détail :
 *  - tarifs proposés (selon le profil) avec quantités, total et bouton « S'inscrire et payer » ;
 *  - inscriptions déjà payées, et paiement en attente (à reprendre ou annuler) ;
 *  - au retour de HelloAsso (`returnOrderId`), vérification du paiement auprès du serveur (jamais d'après la seule adresse
 *    de retour) puis message de confirmation.
 *
 * `onChanged` demande à l'écran de relire l'événement ; `onReturnHandled` retire les paramètres de retour de l'adresse.
 */
export function EventPayment({
  event, payment, returnOrderId, returnResult, onChanged, onReturnHandled,
}: {
  event: EventItem;
  payment: EventPaymentInfo;
  returnOrderId: number | null;
  returnResult: string | null;
  onChanged: () => void;
  onReturnHandled: () => void;
}) {
  const [qty, setQty] = useState<Record<number, number>>({});
  const [busy, setBusy] = useState(false);
  const [checking, setChecking] = useState(returnOrderId !== null);
  const [notice, setNotice] = useState<Notice | null>(null);
  // Les callbacks de l'écran changent à chaque rendu : on les lit par référence pour ne pas relancer la vérification.
  const changedRef = useRef(onChanged);
  const handledRef = useRef(onReturnHandled);
  changedRef.current = onChanged;
  handledRef.current = onReturnHandled;

  // Web : le bouton « Précédent » depuis HelloAsso peut ressusciter cette page telle qu'elle était (bouton occupé).
  useEffect(() => {
    if (Platform.OS !== 'web' || typeof window === 'undefined') return;
    const onShow = (e: PageTransitionEvent) => { if (e.persisted) setBusy(false); };
    window.addEventListener('pageshow', onShow);
    return () => window.removeEventListener('pageshow', onShow);
  }, []);

  // Retour de la page de paiement HelloAsso : on interroge le serveur (qui vérifie auprès de HelloAsso) jusqu'à
  // la confirmation — elle peut arriver quelques secondes après le retour de l'adhérent.
  useEffect(() => {
    if (returnOrderId === null) return;
    let cancelled = false;
    (async () => {
      setChecking(true);
      let order: EventOrderItem | null = null;
      let failed = false;
      const attempts = returnResult === 'return' ? 10 : 1;
      for (let i = 0; i < attempts && !cancelled; i++) {
        try {
          order = (await eventOrders.get(returnOrderId)).order;
          failed = false;
        } catch {
          failed = true;
        }
        if (order && order.status !== 'pending') break;
        if (i < attempts - 1) await sleep(3000);
      }
      if (cancelled) return;
      if (order?.status === 'paid') {
        setNotice({ kind: 'ok', text: '✅ Paiement confirmé : votre inscription est enregistrée.' });
      } else if (order?.status === 'pending') {
        if (returnResult === 'return') {
          setNotice({
            kind: 'warn',
            text: 'HelloAsso n\'a pas encore confirmé le paiement. Cela peut prendre quelques instants : actualisez la page dans une minute, votre inscription apparaîtra ici dès sa confirmation.',
          });
        } else if (returnResult === 'error') {
          setNotice({ kind: 'error', text: 'Le paiement n\'a pas abouti. Vous pouvez le reprendre ou l\'annuler ci-dessous.' });
        } else {
          setNotice({ kind: 'warn', text: 'Paiement non terminé. Vous pouvez le reprendre ou l\'annuler ci-dessous.' });
        }
      } else if (order) {
        setNotice({ kind: 'warn', text: 'Cette inscription n\'est plus en attente de paiement : elle a été annulée ou a expiré.' });
      } else if (failed) {
        setNotice({ kind: 'warn', text: 'Impossible de vérifier le paiement pour le moment. Actualisez la page dans un instant.' });
      }
      setChecking(false);
      changedRef.current();
      handledRef.current();
    })();
    return () => { cancelled = true; };
  }, [returnOrderId, returnResult]);

  const pending = payment.orders.find((o) => o.canPay) ?? null;
  const paid = payment.orders.filter((o) => o.status === 'paid');

  const lines = useMemo(
    () => payment.tariffs.filter((t) => (qty[t.id] ?? 0) > 0).map((t) => ({ tariff: t, quantity: qty[t.id] })),
    [payment.tariffs, qty],
  );
  const count = lines.reduce((n, l) => n + l.quantity, 0);
  const total = lines.reduce((sum, l) => sum + l.tariff.amountCents * l.quantity, 0);
  const needsOnlinePayment = total > 0;
  const unavailable = needsOnlinePayment && !payment.configured;

  const maxFor = (t: EventTariffOption) => Math.min(payment.maxQuantity, t.remaining ?? payment.maxQuantity);
  const bump = (t: EventTariffOption, delta: number) => {
    setQty((prev) => ({ ...prev, [t.id]: Math.max(0, Math.min(maxFor(t), (prev[t.id] ?? 0) + delta)) }));
  };

  function fail(e: unknown) {
    setNotice({ kind: 'error', text: e instanceof ApiError ? e.message : 'Une erreur est survenue, réessayez.' });
    // Places prises entre-temps, paiement déjà en cours… : l'écran se remet à jour.
    changedRef.current();
  }

  async function submit() {
    if (busy || count === 0 || unavailable) return;
    setBusy(true);
    setNotice(null);
    try {
      const res = await eventsApi.order(event.id, lines.map((l) => ({ tariffId: l.tariff.id, quantity: l.quantity })));
      setQty({});
      if (res.redirectUrl) {
        // Web : on quitte l'appli pour HelloAsso, le bouton reste occupé jusqu'au changement de page.
        openPaymentPage(res.redirectUrl);
        if (Platform.OS !== 'web') setBusy(false);
        return;
      }
      setNotice({ kind: 'ok', text: '✅ Inscription enregistrée.' });
      changedRef.current();
    } catch (e) {
      fail(e);
    }
    setBusy(false);
  }

  async function resume(order: EventOrderItem) {
    if (busy) return;
    setBusy(true);
    setNotice(null);
    try {
      const res = await eventOrders.pay(order.id);
      if (res.redirectUrl) {
        openPaymentPage(res.redirectUrl);
        if (Platform.OS !== 'web') setBusy(false);
        return;
      }
      setNotice({ kind: 'ok', text: '✅ Paiement confirmé : votre inscription est enregistrée.' });
      changedRef.current();
    } catch (e) {
      fail(e);
    }
    setBusy(false);
  }

  async function doCancel(order: EventOrderItem) {
    setBusy(true);
    setNotice(null);
    try {
      await eventOrders.cancel(order.id);
      setNotice({ kind: 'warn', text: 'Inscription annulée : les places sont libérées.' });
      changedRef.current();
    } catch (e) {
      fail(e);
    }
    setBusy(false);
  }

  function cancel(order: EventOrderItem) {
    if (busy) return;
    if (Platform.OS === 'web') {
      if (typeof window !== 'undefined' && window.confirm('Annuler cette inscription non payée ?')) void doCancel(order);
    } else {
      Alert.alert('Annuler cette inscription ?', 'Elle n\'a pas été payée : les places seront libérées.', [
        { text: 'Garder', style: 'cancel' },
        { text: 'Annuler l\'inscription', style: 'destructive', onPress: () => void doCancel(order) },
      ]);
    }
  }

  const hasOrders = payment.orders.length > 0;
  // Rien à montrer : aucun tarif ne s'adresse à ce profil et aucune inscription en cours.
  if (payment.tariffs.length === 0 && !hasOrders && !checking && !notice) return null;

  const canRegister = !payment.closed && !pending && payment.tariffs.length > 0;

  return (
    <View style={styles.card}>
      <Text style={styles.title}>Inscription et paiement</Text>

      {payment.sandbox && (
        <Text style={styles.sandbox}>🧪 Mode test HelloAsso : aucun vrai paiement n'est effectué.</Text>
      )}

      {checking && (
        <View style={styles.checking}>
          <ActivityIndicator color={COLORS.primary} />
          <Text style={styles.checkingText}>Vérification de votre paiement auprès de HelloAsso…</Text>
        </View>
      )}

      {notice && (
        <Text style={[styles.notice, notice.kind === 'ok' ? styles.noticeOk : notice.kind === 'error' ? styles.noticeError : styles.noticeWarn]}>
          {notice.text}
        </Text>
      )}

      {paid.map((o) => (
        <View key={o.id} style={styles.paidRow}>
          <Text style={styles.paidTitle}>✅ Inscription payée{o.totalCents === 0 ? ' (gratuite)' : ''}</Text>
          <Text style={styles.rowText}>{summary(o)}</Text>
          <Text style={styles.rowMeta}>
            {o.totalCents > 0 ? `${euros(o.totalCents)} · ` : ''}{o.paidAt ? dateTimeOf(o.paidAt) : ''}
          </Text>
        </View>
      ))}

      {pending && (
        <View style={styles.pendingRow}>
          <Text style={styles.pendingTitle}>⏳ Paiement en attente</Text>
          <Text style={styles.rowText}>{summary(pending)}</Text>
          <Text style={styles.rowMeta}>
            {euros(pending.totalCents)} · places réservées jusqu'à {timeOf(pending.expiresAt)}
          </Text>
          <View style={styles.pendingActions}>
            <Pressable
              onPress={() => void resume(pending)}
              disabled={busy}
              style={({ pressed }) => [styles.payBtn, { flex: 1 }, (busy || pressed) && { opacity: 0.7 }]}
              accessibilityLabel="Reprendre le paiement"
            >
              {busy ? <ActivityIndicator color="#fff" /> : <Text style={styles.payBtnLabel}>Reprendre le paiement</Text>}
            </Pressable>
            <Pressable
              onPress={() => cancel(pending)}
              disabled={busy}
              style={({ pressed }) => [styles.cancelBtn, (busy || pressed) && { opacity: 0.7 }]}
              accessibilityLabel="Annuler l'inscription"
            >
              <Text style={styles.cancelBtnLabel}>Annuler</Text>
            </Pressable>
          </View>
        </View>
      )}

      {payment.closed && !hasOrders && <Text style={styles.muted}>Les inscriptions sont closes.</Text>}

      {canRegister && (
        <View style={paid.length > 0 ? styles.moreBlock : undefined}>
          {paid.length > 0 && <Text style={styles.subTitle}>Inscrire d'autres personnes</Text>}
          {payment.tariffs.map((t) => {
            const q = qty[t.id] ?? 0;
            const max = maxFor(t);
            return (
              <View key={t.id} style={[styles.tariff, t.soldOut && { opacity: 0.55 }]}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.tariffLabel}>{t.label}</Text>
                  {t.description ? <Text style={styles.tariffDesc}>{t.description}</Text> : null}
                  <Text style={styles.tariffMeta}>
                    {euros(t.amountCents)}
                    {t.soldOut ? ' · complet' : t.remaining !== null ? ` · ${t.remaining} place${t.remaining > 1 ? 's' : ''} restante${t.remaining > 1 ? 's' : ''}` : ''}
                  </Text>
                </View>
                {!t.soldOut && (
                  <View style={styles.stepper}>
                    <Pressable
                      onPress={() => bump(t, -1)}
                      disabled={busy || q === 0}
                      accessibilityLabel={`Retirer un « ${t.label} »`}
                      style={[styles.stepBtn, (busy || q === 0) && { opacity: 0.35 }]}
                    >
                      <Text style={styles.stepLabel}>−</Text>
                    </Pressable>
                    <Text style={styles.qty}>{q}</Text>
                    <Pressable
                      onPress={() => bump(t, 1)}
                      disabled={busy || q >= max}
                      accessibilityLabel={`Ajouter un « ${t.label} »`}
                      style={[styles.stepBtn, (busy || q >= max) && { opacity: 0.35 }]}
                    >
                      <Text style={styles.stepLabel}>+</Text>
                    </Pressable>
                  </View>
                )}
              </View>
            );
          })}

          <Pressable
            onPress={() => void submit()}
            disabled={busy || count === 0 || unavailable}
            accessibilityLabel={needsOnlinePayment ? 'S\'inscrire et payer' : 'S\'inscrire'}
            style={({ pressed }) => [styles.payBtn, (busy || count === 0 || unavailable) && { opacity: 0.5 }, pressed && { opacity: 0.8 }]}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.payBtnLabel}>
                {count === 0 ? 'Choisissez au moins un tarif' : needsOnlinePayment ? `S'inscrire et payer · ${euros(total)}` : 'S\'inscrire'}
              </Text>
            )}
          </Pressable>
          {unavailable && <Text style={styles.muted}>Le paiement en ligne n'est pas disponible pour le moment.</Text>}
          {needsOnlinePayment && !unavailable && (
            <Text style={styles.hint}>
              Vous réglez en ligne sur HelloAsso (carte bancaire) ; vos informations y sont déjà remplies. Vos places sont
              réservées 45 minutes.
            </Text>
          )}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginBottom: SPACING.md,
    gap: SPACING.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  title: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  subTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text, marginBottom: SPACING.xs },
  sandbox: {
    fontSize: 12, color: '#92400e', backgroundColor: '#fef3c7', borderRadius: RADIUS.sm,
    paddingHorizontal: 10, paddingVertical: 6,
  },
  checking: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  checkingText: { flex: 1, fontSize: 13, color: COLORS.textMuted },
  notice: { fontSize: 13, lineHeight: 18, borderRadius: RADIUS.sm, paddingHorizontal: 10, paddingVertical: 8 },
  noticeOk: { color: '#166534', backgroundColor: '#dcfce7' },
  noticeWarn: { color: '#92400e', backgroundColor: '#fef3c7' },
  noticeError: { color: '#991b1b', backgroundColor: '#fee2e2' },
  paidRow: { backgroundColor: '#f0fdf4', borderRadius: RADIUS.sm, padding: SPACING.md, gap: 2, borderWidth: 1, borderColor: '#bbf7d0' },
  paidTitle: { fontSize: 14, fontWeight: '700', color: '#166534' },
  pendingRow: { backgroundColor: '#fffbeb', borderRadius: RADIUS.sm, padding: SPACING.md, gap: 2, borderWidth: 1, borderColor: '#fde68a' },
  pendingTitle: { fontSize: 14, fontWeight: '700', color: '#92400e' },
  pendingActions: { flexDirection: 'row', gap: 8, marginTop: SPACING.sm },
  rowText: { fontSize: 14, color: COLORS.text },
  rowMeta: { fontSize: 12, color: COLORS.textMuted },
  moreBlock: { marginTop: SPACING.xs, paddingTop: SPACING.md, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border },
  tariff: {
    flexDirection: 'row', alignItems: 'center', gap: 10,
    paddingVertical: SPACING.sm, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border,
  },
  tariffLabel: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  tariffDesc: { fontSize: 12, color: COLORS.textMuted, marginTop: 1 },
  tariffMeta: { fontSize: 13, color: COLORS.textMuted, marginTop: 2 },
  stepper: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  stepBtn: {
    width: 36, height: 36, borderRadius: RADIUS.full, borderWidth: 1, borderColor: COLORS.borderStrong,
    alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.surface,
  },
  stepLabel: { fontSize: 20, lineHeight: 22, fontWeight: '600', color: COLORS.text },
  qty: { minWidth: 26, textAlign: 'center', fontSize: 16, fontWeight: '700', color: COLORS.text },
  payBtn: {
    backgroundColor: COLORS.success, borderRadius: RADIUS.md, paddingVertical: 14, paddingHorizontal: SPACING.md,
    alignItems: 'center', justifyContent: 'center', marginTop: SPACING.sm,
  },
  payBtnLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  cancelBtn: {
    borderRadius: RADIUS.md, paddingVertical: 14, paddingHorizontal: SPACING.md, alignItems: 'center', justifyContent: 'center',
    borderWidth: 1, borderColor: COLORS.borderStrong, marginTop: SPACING.sm,
  },
  cancelBtnLabel: { color: COLORS.textMuted, fontWeight: '600', fontSize: 14 },
  hint: { fontSize: 12, color: COLORS.textMuted, lineHeight: 17 },
  muted: { fontSize: 13, color: COLORS.textMuted, fontStyle: 'italic' },
});
