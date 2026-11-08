<?php

namespace App\Service\Volunteer;

use App\Entity\User;
use App\Entity\VolunteerApplication;
use App\Entity\VolunteerCampaign;
use App\Entity\VolunteerPole;
use App\Entity\VolunteerRole;

/**
 * Mise en forme JSON des campagnes de bénévolat pour l'appli : fiches, personnes en place, accord
 * « référent / référente » et état des candidatures de l'adhérent connecté.
 *
 * Personnes en place = candidatures validées (prénom + initiale du nom) + noms saisis à la main sur la fiche.
 * Accord : masculin dès qu'un homme est en place, féminin si toutes les personnes en place sont des femmes
 * connues (sexe de la fiche adhérent), écriture inclusive sinon (noms saisis à la main, sexe non renseigné).
 */
class VolunteerPresenter
{
    /**
     * @param array<int, VolunteerApplication> $mine  candidatures du viewer indexées par id de fiche
     * @return array<string, mixed>
     */
    public function campaign(VolunteerCampaign $campaign, array $mine, bool $isManager, int $pendingToReview): array
    {
        $poles = [];
        $fieldRoles = [];
        $tasks = 0;
        $activities = 0;
        foreach ($campaign->getPoles() as $pole) {
            $items = [];
            foreach ($pole->getRoles() as $role) {
                if (!$role->isPublished()) {
                    continue;
                }
                if ($role->isField()) {
                    $fieldRoles[] = $this->summary($role, $mine[$role->getId()] ?? null);
                    continue;
                }
                $items[] = $this->summary($role, $mine[$role->getId()] ?? null);
                $tasks += count($this->list($role->getContent()['tasks'] ?? []));
                $activities++;
            }
            if ($items === []) {
                continue;
            }
            $poles[] = $this->pole($pole) + ['activities' => $items];
        }

        return [
            'id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'startsOn' => $campaign->getStartsOn()->format('Y-m-d'),
            'endsOn' => $campaign->getEndsOn()?->format('Y-m-d'),
            'location' => $campaign->getLocation(),
            'applicationsDeadline' => $campaign->getApplicationsDeadline()?->format('Y-m-d'),
            'intro' => $campaign->getIntro(),
            'applicationsOpen' => $campaign->isApplicationsOpen(),
            'poles' => $poles,
            'fieldRoles' => $fieldRoles,
            'stats' => [
                'poles' => count($poles),
                'activities' => $activities,
                'fieldRoles' => count($fieldRoles),
                'tasks' => $tasks,
            ],
            'myApplicationCount' => count($mine),
            'isManager' => $isManager,
            'pendingToReview' => $pendingToReview,
        ];
    }

    /** @return array<string, mixed> */
    public function pole(VolunteerPole $pole): array
    {
        return [
            'id' => $pole->getId(),
            'name' => $pole->getName(),
            'managerNames' => $pole->getManagerNames(),
            'color' => $pole->getColor(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(VolunteerRole $role, ?VolunteerApplication $mine): array
    {
        $holders = $this->holders($role);
        $filled = count($holders['names']);
        $required = $role->getRequiredCount();
        $content = $role->getContent();

        return [
            'id' => $role->getId(),
            'kind' => $role->getKind(),
            'name' => $role->getName(),
            'title' => $role->getTitle(),
            'poleId' => $role->getPole()->getId(),
            'poleName' => $role->getPole()->getName(),
            'activityName' => $role->getActivity()?->getName(),
            'taskCount' => count($this->list($content['tasks'] ?? [])),
            'requiredCount' => $required,
            'holders' => $holders['names'],
            'holderLabel' => $holders['label'],
            'filled' => $filled,
            'missing' => $required !== null ? max(0, $required - $filled) : null,
            // Activité : une seule personne en place → on cherche un binôme.
            'needsPartner' => !$role->isField() && $filled === 1,
            'myStatus' => $mine?->getStatus(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(VolunteerRole $role, ?VolunteerApplication $mine): array
    {
        $activity = $role->getActivity();
        return $this->summary($role, $mine) + [
            'pitch' => $role->getPitch(),
            'content' => $role->getContent(),
            'pole' => $this->pole($role->getPole()),
            'activity' => $activity !== null ? [
                'id' => $activity->isPublished() ? $activity->getId() : null,
                'name' => $activity->getName(),
                'holders' => $this->holders($activity)['names'],
                'holderLabel' => $this->holders($activity)['label'],
            ] : null,
            'myMessage' => $mine?->getMessage(),
            'applicationsOpen' => $role->getCampaign()->isApplicationsOpen(),
            'campaignName' => $role->getCampaign()->getName(),
        ];
    }

    /** Candidature vue par son auteur ou par un responsable. @return array<string, mixed> */
    public function application(VolunteerApplication $a, bool $forManager): array
    {
        $role = $a->getRole();
        $out = [
            'id' => $a->getId(),
            'status' => $a->getStatus(),
            'statusLabel' => $a->getStatusLabel(),
            'message' => $a->getMessage(),
            'createdAt' => $a->getCreatedAt()->format(\DATE_ATOM),
            'decidedAt' => $a->getDecidedAt()?->format(\DATE_ATOM),
            'assigned' => $a->getAssignedBy() !== null,
            'role' => [
                'id' => $role->getId(),
                'kind' => $role->getKind(),
                'name' => $role->getName(),
                'title' => $role->getTitle(),
                'poleName' => $role->getPole()->getName(),
                'published' => $role->isPublished(),
            ],
        ];
        if ($forManager) {
            $user = $a->getUser();
            $out['user'] = [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'telephone' => $user->getTelephone(),
                'email' => $user->getEmail(),
            ];
            $out['decidedBy'] = $a->getDecidedBy()?->getFullName();
        }
        return $out;
    }

    /** Affichage court d'un adhérent sur une fiche : prénom + initiale du nom. */
    public static function shortName(User $user): string
    {
        $initial = mb_substr(trim($user->getNom()), 0, 1);
        return trim($user->getPrenom().($initial !== '' ? ' '.mb_strtoupper($initial).'.' : ''));
    }

    /**
     * @return array{names: list<string>, label: string}
     */
    public function holders(VolunteerRole $role): array
    {
        $names = [];
        $sexes = [];
        foreach ($role->getAcceptedApplications() as $a) {
            $names[] = self::shortName($a->getUser());
            $sexes[] = $a->getUser()->getSexe();
        }
        foreach (self::splitNames($role->getManualNames()) as $n) {
            $names[] = $n;
            $sexes[] = null;
        }

        $noun = $role->isField() ? 'Responsable' : 'Référent';
        if ($role->isField()) {
            $label = $noun.(count($names) > 1 ? 's' : '');
        } else {
            $male = in_array('m', $sexes, true);
            $allFemale = $sexes !== [] && count(array_filter($sexes, static fn ($s) => $s === 'f')) === count($sexes);
            $base = $male ? 'Référent' : ($allFemale ? 'Référente' : 'Référent·e');
            $label = $base.(count($names) > 1 ? 's' : '');
        }

        return ['names' => $names, 'label' => $label];
    }

    /** @return list<string> */
    public static function splitNames(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:,|&|\bet\b|\bou\b)\s*/u', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s) => $s !== '' && $s !== '?'));
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
