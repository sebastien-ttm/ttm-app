<?php

namespace App\Entity;

use App\Enum\BoardAttendance;
use Doctrine\ORM\Mapping as ORM;

/**
 * Présence d'une personne à une réunion du CoDir, pour le compte rendu :
 *  - Présent / Excusé : un membre du CoDir (ou un entraîneur invité) coché dans la page « En séance » ;
 *  - Invité : n'importe quel compte (adhérent, parent, compte externe…) ou une personne sans compte, saisie à la main
 *    avec, au besoin, sa qualité ou son organisme (« mairie de Blagnac »).
 *
 * `name` est une copie du nom au moment de la saisie : le compte rendu reste lisible si le compte est supprimé plus
 * tard (`user` passe alors à null) ; tant que le compte existe, on affiche son nom à jour (getDisplayName()).
 */
#[ORM\Entity]
#[ORM\Table(name: 'board_meeting_attendee')]
class BoardMeetingAttendee
{
    public const NAME_MAX = 120;
    public const NOTE_MAX = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BoardMeeting::class, inversedBy: 'attendees')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BoardMeeting $meeting;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** Qualité ou organisme d'un invité (facultatif). */
    #[ORM\Column(length: self::NOTE_MAX, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 16, enumType: BoardAttendance::class)]
    private BoardAttendance $status;

    public function __construct(BoardMeeting $meeting, BoardAttendance $status, ?User $user, string $name, ?string $note = null)
    {
        $this->meeting = $meeting;
        $this->status = $status;
        $this->user = $user;
        $this->name = mb_substr(trim($user !== null ? $user->getFullName() : $name), 0, self::NAME_MAX);
        $note = $note !== null ? trim($note) : null;
        $this->note = $note !== null && $note !== '' ? mb_substr($note, 0, self::NOTE_MAX) : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getMeeting(): BoardMeeting { return $this->meeting; }
    public function getUser(): ?User { return $this->user; }
    public function getStatus(): BoardAttendance { return $this->status; }
    public function getNote(): ?string { return $this->note; }

    /** Nom à afficher : celui du compte s'il existe encore (à jour), sinon la copie enregistrée. */
    public function getDisplayName(): string
    {
        return $this->user !== null ? $this->user->getFullName() : $this->name;
    }

    /** Rôle au CoDir de la personne (Président, Secrétaire…), s'il existe — pour le compte rendu. */
    public function getBoardRoleLabel(): ?string
    {
        return $this->user?->getBoardRole()?->label();
    }
}
