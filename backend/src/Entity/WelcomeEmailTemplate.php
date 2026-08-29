<?php

namespace App\Entity;

use App\Repository\WelcomeEmailTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modèle d'email de bienvenue envoyé aux nouveaux adhérents créés via
 * l'import FFTri (CsvImportService). Contient un sujet + un corps HTML
 * personnalisable par l'admin via EasyAdmin.
 *
 * Placeholders remplacés lors de l'envoi (simple str_replace, PAS de
 * rendu Twig — évite tout risque d'injection depuis le contenu admin) :
 *   {{ prenom }}     → prénom de l'adhérent
 *   {{ nom }}        → nom de l'adhérent
 *   {{ magic_link }} → URL de première connexion (lien magique)
 *
 * Singleton : le repository ne renvoie que la ligne courante ; le CRUD
 * redirige la création vers l'édition si une ligne existe déjà.
 */
#[ORM\Entity(repositoryClass: WelcomeEmailTemplateRepository::class)]
#[ORM\Table(name: 'welcome_email_template')]
#[ORM\HasLifecycleCallbacks]
class WelcomeEmailTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $subject = 'Bienvenue au Triathlon Toulouse Métropole !';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $bodyHtml = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $s): self { $this->subject = $s; return $this; }
    public function getBodyHtml(): string { return $this->bodyHtml; }
    public function setBodyHtml(string $s): self { $this->bodyHtml = $s; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return 'Modèle email de bienvenue'; }
}
