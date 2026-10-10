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
 * adhérents : accepter — le temps rejoint une prise de temps de l'épreuve
 * (par défaut la plus récente, ou une plus ancienne au choix) — ou refuser.
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
     * Ajoute le temps déclaré à la prise de temps `$target`, ou à la plus
     * récente de l'épreuve (et du bassin) si elle est omise.
     *
     * @return PerfTestSession|string la prise de temps utilisée, ou un message d'erreur (la demande reste en attente)
     */
    public function accept(PerfTestDeclaration $declaration, User $by, ?PerfTestSession $target = null, ?string $note = null): PerfTestSession|string
    {
        if (!$declaration->isPending()) {
            return 'Cette demande a déjà été traitée.';
        }

        $session = $target ?? $this->sessions->findMostRecentForTest($declaration->getTest(), $declaration->getPoolLength());
        if ($session === null) {
            return 'Aucune prise de temps n\'existe pour cette épreuve : créez-en une d\'abord (Tests chronométrés).';
        }
        if ($session->getTest() !== $declaration->getTest() || $session->getPoolLength() !== $declaration->getPoolLength()) {
            return 'Cette prise de temps ne correspond pas à l\'épreuve (ou au bassin) de la demande.';
        }

        $existing = $this->results->findOneBySessionAndUser($session, $declaration->getUser());
        if ($existing !== null) {
            return sprintf(
                '%s a déjà un temps (%s) sur la prise de temps %s : choisissez-en une autre, ou refusez cette demande.',
                $declaration->getUser()->getFullName(),
                PerfTestResult::format($existing->getTimeSeconds()),
                $session->getDatesLabel(),
            );
        }

        $this->em->persist(new PerfTestResult($session, $declaration->getUser(), $declaration->getTimeSeconds(), $by));
        $declaration->accept($by, $note);
        $this->em->flush();

        return $session;
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
