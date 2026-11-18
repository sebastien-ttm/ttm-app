<?php

namespace App\Controller\Admin;

use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Message\CreateBoardMeetingCalendarEventMessage;
use App\Service\Board\BoardMeetingNotifier;
use App\Service\Google\GoogleCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Réunions du comité directeur (administrateurs) : programmer une réunion (les membres du CoDir sont notifiés et, si
 * Google Agenda est configuré, invités par un événement Google Agenda), la clore une fois tenue. Les réponses se
 * saisissent sur la page « En séance ».
 */
class BoardMeetingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly BoardMeetingNotifier $notifier,
        private readonly GoogleCalendarClient $calendar,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BoardMeeting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réunion du CODIR')
            ->setEntityLabelInPlural('Réunions du CODIR')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['scheduledAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('scheduledAt', 'Date et heure')->setFormat('EEEE d MMMM yyyy HH:mm');
        yield TextField::new('location', 'Lieu')->setRequired(false);
        yield TextareaField::new('notes', 'Informations (invités, ordre de passage…)')
            ->setRequired(false)
            ->setNumOfRows(3)
            ->hideOnIndex();
        yield IntegerField::new('itemCount', 'Points')->hideOnForm()->setSortable(false);
        yield IntegerField::new('answeredCount', 'Traités')->hideOnForm()->setSortable(false);
        yield BooleanField::new('calendarInvited', 'Invitation Google Agenda')
            ->renderAsSwitch(false)
            ->hideOnForm()
            ->setSortable(false)
            ->setHelp('Événement créé dans Google Agenda et invitation envoyée (pour le moment à l\'adresse générique du CoDir).');
        yield BooleanField::new('closed', 'Réunion close')
            ->renderAsSwitch(false)
            ->hideWhenCreating()
            ->setHelp('Close : plus aucun point ne peut être ajouté depuis l\'appli ; l\'ordre du jour devient le compte rendu.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $session = Action::new('session', 'En séance', 'fa fa-gavel')
            ->linkToRoute('admin_board_session', static fn (BoardMeeting $m) => ['meeting' => $m->getId()]);
        $export = Action::new('exportCsv', 'Export CSV', 'fa fa-file-csv')
            ->linkToRoute('admin_board_export', static fn (BoardMeeting $m) => ['id' => $m->getId()]);
        $minutes = Action::new('minutes', 'Compte rendu', 'fa fa-file-lines')
            ->linkToRoute('admin_board_minutes', static fn (BoardMeeting $m) => ['id' => $m->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $session)
            ->add(Crud::PAGE_INDEX, $minutes)
            ->add(Crud::PAGE_INDEX, $export)
            ->add(Crud::PAGE_DETAIL, $session)
            ->add(Crud::PAGE_DETAIL, $minutes)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * À la création d'une réunion à venir : événement Google Agenda et invitation (à l'adresse générique du CoDir pour le
     * moment ; voir CreateBoardMeetingCalendarEventMessageHandler). L'envoi est asynchrone (file Messenger).
     */
    private function inviteViaGoogleCalendar(BoardMeeting $meeting): void
    {
        if (!$this->calendar->isConfigured()) {
            $this->addFlash('warning', 'Google Agenda n\'est pas configuré : aucune invitation n\'a été envoyée (voir docs/GOOGLE-CALENDAR.md).');
            return;
        }
        if (!$meeting->isUpcoming()) {
            return;
        }
        $this->bus->dispatch(new CreateBoardMeetingCalendarEventMessage((int) $meeting->getId()));
        $this->addFlash('success', 'Invitation Google Agenda en cours d\'envoi.');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        if ($entityInstance instanceof BoardMeeting) {
            $user = $this->getUser();
            $this->notifier->notifyScheduled($entityInstance, $user instanceof User ? $user : null);
            $this->inviteViaGoogleCalendar($entityInstance);
        }
    }
}
