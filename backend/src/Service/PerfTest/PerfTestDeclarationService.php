<?php

namespace App\Service\PerfTest;

use App\Entity\PerfTestDeclaration;
use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traitement par les entraîneurs / l'admin des temps déclarés par les
 * adhérents : accepter (le temps est enregistré sur la séance « individuelle »
 * de l'épreuve et du jour, créée au besoin) ou refuser.
 */
class PerfTestDeclarationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestResultRepository $results,
    ) {
    }

    /**
     * @return string|null message d'erreur (la demande reste en attente), null si acceptée
     */
    public function accept(PerfTestDeclaration $declaration, User $by, ?string $note = null): ?string
    {
        if (!$declaration->isPending()) {
            return 'Cette demande a déjà été traitée.';
        }

        $session = $this->sessions->findIndividualSession(
            $declaration->getTest(),
            $declaration->getPoolLength(),
            $declaration->getPerformedOn(),
        );
        if ($session === null) {
            $session = new PerfTestSession();
            $session->setTest($declaration->getTest());
            $session->setPoolLength($declaration->getPoolLength());
            $session->setDate($declaration->getPerformedOn());
            $session->setNotes(PerfTestSession::INDIVIDUAL_NOTES);
            $session->setCreatedBy($by);
            $this->em->persist($session);
        } else {
            $existing = $this->results->findOneBySessionAndUser($session, $declaration->getUser());
            if ($existing !== null) {
                return sprintf(
                    '%s a déjà un temps enregistré (%s) sur la séance individuelle du %s : refusez cette demande ou corrigez le temps existant.',
                    $declaration->getUser()->getFullName(),
                    PerfTestResult::format($existing->getTimeSeconds()),
                    $declaration->getPerformedOn()->format('d/m/Y'),
                );
            }
        }

        $this->em->persist(new PerfTestResult($session, $declaration->getUser(), $declaration->getTimeSeconds(), $by));
        $declaration->accept($by, $note);
        $this->em->flush();

        return null;
    }

    public function reject(PerfTestDeclaration $declaration, User $by, ?string $note = null): void
    {
        if (!$declaration->isPending()) {
            return;
        }
        $declaration->reject($by, $note);
        $this->em->flush();
    }
}
