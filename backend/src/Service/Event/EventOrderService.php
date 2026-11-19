<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventOrder;
use App\Entity\EventOrderLine;
use App\Entity\EventTariff;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Enum\EventOrderStatus;
use App\Repository\EventOrderRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\HelloAsso\HelloAssoClient;
use App\Service\HelloAsso\HelloAssoException;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Inscription payante aux événements (paiement HelloAsso Checkout) : commande des tarifs choisis, demande de paiement à
 * HelloAsso (l'adhérent y arrive avec ses informations déjà saisies), confirmation du paiement, annulation.
 *
 * Règle de sécurité : une commande n'est « payée » que si HelloAsso le confirme par un appel authentifié à son API
 * (getCheckoutIntent). L'adresse de retour (paramètre « code » falsifiable) et les notifications (non signées) ne servent
 * que de déclencheur pour aller vérifier ; leur contenu n'est jamais cru.
 *
 * Places : un tarif limité (`maxPlaces`) compte les commandes payées et les commandes en attente non expirées ; la prise
 * de places se fait sous verrou sur l'événement, pour que deux adhérents ne prennent pas la dernière en même temps.
 */
class EventOrderService
{
    /** Quantité maximale d'un même tarif dans une commande (l'adhérent et ses invités). */
    public const MAX_QUANTITY = 10;

    /** Délai minimal entre deux interrogations de HelloAsso pour une même commande (lectures répétées, notifications forgées). */
    private const CHECK_INTERVAL_SECONDS = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventOrderRepository $orders,
        private readonly HelloAssoClient $helloAsso,
        private readonly AudienceFilter $audienceFilter,
        private readonly EventAttendanceService $attendance,
        private readonly WebPushNotifier $webPush,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    /** Les inscriptions sont closes une fois l'événement terminé (fin, ou fin de la journée de début sans heure de fin). */
    public function isRegistrationClosed(Event $event, ?\DateTimeImmutable $now = null): bool
    {
        $end = $event->getEndsAt() ?? $event->getStartsAt();
        if ($event->isAllDay() || $event->getEndsAt() === null) {
            $end = $end->setTime(23, 59, 59);
        }

        return ($now ?? new \DateTimeImmutable()) > $end;
    }

    /**
     * Tarifs que `$viewer` peut choisir : ceux sans restriction, ou réservés à l'un de ses profils.
     *
     * @return list<EventTariff>
     */
    public function eligibleTariffs(Event $event, User $viewer): array
    {
        return array_values(array_filter(
            $event->getTariffs()->toArray(),
            fn (EventTariff $t) => $this->audienceFilter->isVisible($t->getAudience(), $viewer),
        ));
    }

    /**
     * Bloc « paiement » du détail d'un événement pour `$viewer`, ou null si l'événement n'a pas de tarif : tarifs
     * proposés (avec les places restantes), ses inscriptions en cours ou payées, et si l'inscription est possible.
     * Une commande en attente dont la réservation est échue est réglée au passage (payée ou abandonnée).
     *
     * @return array<string, mixed>|null
     */
    public function paymentInfo(Event $event, User $viewer): ?array
    {
        if (!$event->hasTariffs()) {
            return null;
        }
        foreach ($this->orders->findActiveForUser($event, $viewer) as $order) {
            if ($order->isExpired()) {
                $this->settleExpired($order);
            }
        }

        $taken = $this->orders->takenByTariff($event);
        $tariffs = [];
        foreach ($this->eligibleTariffs($event, $viewer) as $tariff) {
            $remaining = $tariff->getMaxPlaces() === null ? null : max(0, $tariff->getMaxPlaces() - ($taken[(int) $tariff->getId()] ?? 0));
            $tariffs[] = [
                'id' => $tariff->getId(),
                'label' => $tariff->getLabel(),
                'description' => $tariff->getDescription(),
                'amountCents' => $tariff->getAmountCents(),
                'remaining' => $remaining,
                'soldOut' => $remaining === 0,
            ];
        }

        return [
            // Paiement en ligne utilisable (identifiants HelloAsso renseignés) ; les tarifs nuls n'en ont pas besoin.
            'configured' => $this->helloAsso->isCheckoutConfigured(),
            // Bac à sable HelloAsso : aucun vrai paiement (l'appli le signale pour éviter toute confusion).
            'sandbox' => $this->helloAsso->isSandbox(),
            'closed' => $this->isRegistrationClosed($event),
            'maxQuantity' => self::MAX_QUANTITY,
            'tariffs' => $tariffs,
            'orders' => array_map(
                fn (EventOrder $o) => $this->serialize($o),
                $this->orders->findActiveForUser($event, $viewer),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function serialize(EventOrder $order): array
    {
        return [
            'id' => $order->getId(),
            'eventId' => $order->getEvent()?->getId(),
            'status' => $order->getStatus()->value,
            'statusLabel' => $order->getStatusLabel(),
            'totalCents' => $order->getTotalCents(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'paidAt' => $order->getPaidAt()?->format(\DATE_ATOM),
            'expiresAt' => $order->getExpiresAt()->format(\DATE_ATOM),
            // En attente et dans le délai : l'adhérent peut (re)prendre le paiement ou annuler.
            'canPay' => $order->isPending() && !$order->isExpired(),
            'lines' => array_map(static fn (EventOrderLine $l) => [
                'label' => $l->getLabel(),
                'unitAmountCents' => $l->getUnitAmountCents(),
                'quantity' => $l->getQuantity(),
            ], $order->getLines()->toArray()),
        ];
    }

    /**
     * Crée la commande « en attente » (ou « payée » d'emblée si le total est nul : rien à régler). Les places sont prises
     * sous verrou. L'appelant enchaîne avec startCheckout() quand la commande est à régler.
     *
     * @param list<array{tariffId: int, quantity: int}> $requested
     * @throws EventOrderException
     */
    public function createOrder(Event $event, User $user, array $requested): EventOrder
    {
        if ($this->isRegistrationClosed($event)) {
            throw new EventOrderException('Les inscriptions sont closes : l\'événement est terminé.', 409);
        }

        // Quantités par tarif (un même tarif répété s'additionne), contrôle des tarifs proposés à cet adhérent.
        $eligible = [];
        foreach ($this->eligibleTariffs($event, $user) as $tariff) {
            $eligible[(int) $tariff->getId()] = $tariff;
        }
        $quantities = [];
        foreach ($requested as $line) {
            $tariffId = (int) ($line['tariffId'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity < 1) {
                continue;
            }
            if (!isset($eligible[$tariffId])) {
                throw new EventOrderException('Ce tarif n\'est pas proposé pour cet événement.', 422);
            }
            $quantities[$tariffId] = ($quantities[$tariffId] ?? 0) + $quantity;
        }
        if ($quantities === []) {
            throw new EventOrderException('Choisissez au moins un tarif.', 422);
        }
        foreach ($quantities as $tariffId => $quantity) {
            if ($quantity > self::MAX_QUANTITY) {
                throw new EventOrderException(sprintf('« %s » : %d au plus par inscription.', $eligible[$tariffId]->getLabel(), self::MAX_QUANTITY), 422);
            }
        }

        $total = 0;
        foreach ($quantities as $tariffId => $quantity) {
            $total += $eligible[$tariffId]->getAmountCents() * $quantity;
        }
        if ($total > 0 && !$this->helloAsso->isCheckoutConfigured()) {
            throw new EventOrderException('Le paiement en ligne n\'est pas disponible pour le moment.', 503);
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // Verrou sur l'événement : les contrôles de places et d'inscription en cours qui suivent ne peuvent pas être
            // doublés par une autre inscription simultanée.
            $this->em->find(Event::class, $event->getId(), LockMode::PESSIMISTIC_WRITE);

            foreach ($this->orders->findActiveForUser($event, $user) as $existing) {
                if ($existing->isPending() && !$existing->isExpired()) {
                    throw new EventOrderException('Vous avez déjà un paiement en cours pour cet événement : reprenez-le ou annulez-le.', 409, ['orderId' => $existing->getId()]);
                }
            }

            $taken = $this->orders->takenByTariff($event);
            foreach ($quantities as $tariffId => $quantity) {
                $tariff = $eligible[$tariffId];
                if ($tariff->getMaxPlaces() === null) {
                    continue;
                }
                $left = max(0, $tariff->getMaxPlaces() - ($taken[$tariffId] ?? 0));
                if ($quantity > $left) {
                    throw new EventOrderException(
                        $left === 0
                            ? sprintf('« %s » est complet.', $tariff->getLabel())
                            : sprintf('« %s » : il ne reste que %d place%s.', $tariff->getLabel(), $left, $left > 1 ? 's' : ''),
                        409,
                    );
                }
            }

            $order = new EventOrder($event, $user);
            foreach ($quantities as $tariffId => $quantity) {
                $order->addLine(new EventOrderLine($order, $eligible[$tariffId], $quantity));
            }
            $this->em->persist($order);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        if ($order->getTotalCents() === 0 && $this->claimPaid($order, null)) {
            $this->onPaid($order);
        }

        return $order;
    }

    /**
     * Demande le paiement à HelloAsso pour une commande en attente et enregistre l'adresse de la page de paiement.
     * L'adhérent y arrive avec ses informations (prénom, nom, e-mail, date de naissance) déjà saisies.
     *
     * @throws HelloAssoException
     */
    public function startCheckout(EventOrder $order): void
    {
        $event = $order->getEvent();
        $user = $order->getUser();
        if (!$order->isPending() || $event === null || $user === null) {
            return;
        }

        $base = rtrim($this->publicUrl, '/').'/event/'.$event->getId();
        $back = static fn (string $result) => $base.'?'.http_build_query(['order' => $order->getId(), 'payment' => $result]);

        $payer = [
            'firstName' => mb_substr($user->getPrenom(), 0, 255),
            'lastName' => mb_substr($user->getNom(), 0, 255),
        ];
        if (filter_var($user->getEmail(), \FILTER_VALIDATE_EMAIL) !== false) {
            $payer['email'] = mb_substr($user->getEmail(), 0, 255);
        }
        if ($user->getDateNaissance() !== null) {
            // Date seule, sans heure : minuit UTC pour que le jour ne change pas.
            $payer['dateOfBirth'] = $user->getDateNaissance()->format('Y-m-d').'T00:00:00+00:00';
        }

        $intent = $this->helloAsso->createCheckoutIntent([
            'totalAmount' => $order->getTotalCents(),
            'initialAmount' => $order->getTotalCents(),
            'itemName' => mb_substr($event->getTitle().' — '.$order->getLinesSummary(), 0, 250),
            'backUrl' => $back('back'),
            'errorUrl' => $back('error'),
            'returnUrl' => $back('return'),
            'containsDonation' => false,
            'payer' => $payer,
            // Renvoyées telles quelles par HelloAsso : relient une notification à notre commande.
            'metadata' => ['app' => 'ttm', 'orderId' => $order->getId(), 'eventId' => $event->getId(), 'userId' => $user->getId()],
        ]);

        $order->setCheckout($intent['id'], $intent['redirectUrl'])->extendHold();
        $this->em->flush();
    }

    /**
     * Reprend le paiement d'une commande en attente : si une demande précédente a en réalité abouti, la commande est
     * marquée payée (retourne true, rien à régler) ; sinon une nouvelle page de paiement est demandée si l'ancienne a
     * expiré.
     *
     * @return bool true = déjà payée
     * @throws HelloAssoException
     */
    public function resumeCheckout(EventOrder $order): bool
    {
        if ($order->getCheckoutIntentId() !== null && $this->verify($order, null, true)) {
            return true;
        }
        if (!$order->isPending()) {
            return $order->isPaid();
        }
        if (!$order->hasLiveCheckoutUrl()) {
            $this->startCheckout($order);
        }

        return false;
    }

    /**
     * Interroge HelloAsso sur le paiement de la commande (la demande `$intentId`, par défaut la dernière) et la marque payée
     * s'il a abouti. Retourne true si la commande est payée. Sans effet si elle a été vérifiée il y a moins de quelques
     * secondes, sauf `$force`.
     */
    public function verify(EventOrder $order, ?int $intentId = null, bool $force = false): bool
    {
        if ($order->isPaid()) {
            return true;
        }
        $intentId ??= $order->getCheckoutIntentId();
        if (!$order->isPending() || $intentId === null) {
            return false;
        }
        $last = $order->getLastCheckedAt();
        if (!$force && $last !== null && $last->modify('+'.self::CHECK_INTERVAL_SECONDS.' seconds') > new \DateTimeImmutable()) {
            return false;
        }

        try {
            $data = $this->helloAsso->getCheckoutIntent($intentId);
        } catch (HelloAssoException $e) {
            $this->logger->warning('HelloAsso : vérification du paiement impossible', ['orderId' => $order->getId(), 'error' => $e->getMessage()]);

            return false;
        }
        $order->touchChecked();
        $this->em->flush();

        // La commande HelloAsso n'existe (non vide) que si le paiement a abouti.
        $helloOrder = $data['order'] ?? null;
        if (!is_array($helloOrder) || !isset($helloOrder['id'])) {
            return false;
        }
        // Les métadonnées que nous avons posées doivent désigner cette commande : jamais le paiement d'une autre.
        $metaOrderId = is_array($data['metadata'] ?? null) ? ($data['metadata']['orderId'] ?? null) : null;
        if ($metaOrderId !== null && (int) $metaOrderId !== (int) $order->getId()) {
            $this->logger->warning('HelloAsso : paiement rattaché à une autre commande', ['orderId' => $order->getId(), 'metadata' => $metaOrderId]);

            return false;
        }

        if ($this->claimPaid($order, (int) $helloOrder['id'])) {
            $this->onPaid($order);
        }

        return $order->isPaid();
    }

    /** Commande en attente dont la réservation est échue : dernière vérification, puis abandon si rien n'a été payé. */
    public function settleExpired(EventOrder $order): void
    {
        if (!$order->isExpired()) {
            return;
        }
        if ($this->verify($order, null, true)) {
            return;
        }
        $order->abandon();
        $this->em->flush();
    }

    /**
     * Annule une commande en attente (les places sont libérées). Retourne false si elle s'avère déjà payée.
     */
    public function cancel(EventOrder $order): bool
    {
        if ($this->verify($order, null, true)) {
            return false;
        }
        $order->cancel();
        $this->em->flush();

        return true;
    }

    /**
     * Notification HelloAsso (Order ou Payment) : déclencheur seulement. On en tire la demande de paiement et/ou notre
     * commande (métadonnées), puis on vérifie auprès de HelloAsso. Retourne true si une commande a été reconnue.
     *
     * @param array<string, mixed> $payload
     */
    public function handleNotification(array $payload): bool
    {
        [$intentId, $orderId] = self::extractReferences($payload);
        $order = null;
        if ($intentId !== null) {
            $order = $this->orders->findOneByCheckoutIntentId($intentId);
        }
        if ($order === null && $orderId !== null) {
            $order = $this->orders->find($orderId);
        }
        if ($order === null) {
            return false;
        }
        $this->verify($order, $intentId, true);

        return true;
    }

    /**
     * Cherche dans la notification, quelle que soit sa forme exacte, l'identifiant de la demande de paiement
     * (« checkoutIntentId ») et celui de notre commande (« orderId » des métadonnées que nous avons envoyées).
     *
     * @param array<string, mixed> $payload
     * @return array{0: ?int, 1: ?int}
     */
    public static function extractReferences(array $payload): array
    {
        $intentId = null;
        $orderId = null;
        $stack = [[$payload, false]];
        $steps = 0;
        while ($stack !== [] && $steps++ < 500) {
            [$node, $inMetadata] = array_pop($stack);
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $stack[] = [$value, $inMetadata || $key === 'metadata'];
                    continue;
                }
                if ($key === 'checkoutIntentId' && $intentId === null && is_numeric($value)) {
                    $intentId = (int) $value;
                } elseif ($inMetadata && $key === 'orderId' && $orderId === null && is_numeric($value)) {
                    $orderId = (int) $value;
                }
            }
        }

        return [$intentId > 0 ? $intentId : null, $orderId > 0 ? $orderId : null];
    }

    /**
     * Passe la commande à « payée » sous condition qu'elle soit encore « en attente » (une seule requête atomique) : si
     * la notification et le retour de l'adhérent arrivent en même temps, un seul des deux la confirme — donc un seul
     * message à l'adhérent.
     */
    private function claimPaid(EventOrder $order, ?int $helloassoOrderId): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE event_order SET status = ?, paid_at = ?, helloasso_order_id = COALESCE(?, helloasso_order_id) WHERE id = ? AND status = ?',
            [EventOrderStatus::Paid->value, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $helloassoOrderId, $order->getId(), EventOrderStatus::Pending->value],
            [ParameterType::STRING, ParameterType::STRING, $helloassoOrderId === null ? ParameterType::NULL : ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
        if ($affected > 0) {
            $this->em->refresh($order);
        }

        return $affected > 0;
    }

    /** Commande payée : présence confirmée à l'événement (s'il est soumis au vote) et message à l'adhérent. Jamais bloquant. */
    private function onPaid(EventOrder $order): void
    {
        $event = $order->getEvent();
        $user = $order->getUser();
        if ($event === null || $user === null) {
            return;
        }
        try {
            if ($event->isVoteEnabled()) {
                $this->attendance->setVote($event, $user, AttendanceStatus::Yes);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Inscription payante : présence non enregistrée', ['orderId' => $order->getId(), 'error' => $e->getMessage()]);
        }
        try {
            $this->webPush->notifyUsers(
                [(int) $user->getId()],
                'Inscription confirmée',
                sprintf('%s — %s (%s)', $event->getTitle(), $order->getLinesSummary(), $order->getTotalCents() > 0 ? $order->getTotalLabel() : 'gratuit'),
                '/event/'.$event->getId(),
                'event-order-'.$order->getId(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Inscription payante : notification non envoyée', ['orderId' => $order->getId(), 'error' => $e->getMessage()]);
        }
    }
}
