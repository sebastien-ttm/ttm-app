<?php

namespace App\Controller\Admin;

use App\Entity\EventOrder;
use App\Enum\EventOrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Inscriptions payantes aux événements (paiement HelloAsso depuis l'appli), en lecture seule : elles naissent dans
 * l'appli et changent de statut quand HelloAsso confirme le paiement. Ni création, ni modification, ni suppression
 * ici (trace des paiements).
 */
class EventOrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EventOrder::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Inscription payante')
            ->setEntityLabelInPlural('Inscriptions payantes')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['eventTitle', 'payerName', 'checkoutIntentId', 'helloassoOrderId']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices([
                EventOrderStatus::Paid->label() => EventOrderStatus::Paid,
                EventOrderStatus::Pending->label() => EventOrderStatus::Pending,
                EventOrderStatus::Abandoned->label() => EventOrderStatus::Abandoned,
                EventOrderStatus::Canceled->label() => EventOrderStatus::Canceled,
            ]))
            ->add(EntityFilter::new('event', 'Événement'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'N°')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Commandée le')
            ->setFormat('d MMM yyyy HH:mm');
        yield TextField::new('eventTitle', 'Événement');
        yield TextField::new('payerName', 'Adhérent');
        yield TextField::new('linesSummary', 'Tarifs')
            ->setSortable(false);
        yield TextField::new('totalLabel', 'Total')
            ->setSortable(false);
        yield TextField::new('statusLabel', 'Statut')
            ->setSortable(false)
            ->renderAsHtml()
            ->formatValue(static fn ($value, EventOrder $order) => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($order->getStatus()) {
                    EventOrderStatus::Paid => 'success',
                    EventOrderStatus::Pending => 'warning',
                    EventOrderStatus::Abandoned => 'secondary',
                    EventOrderStatus::Canceled => 'danger',
                },
                htmlspecialchars($order->getStatus()->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ));
        yield DateTimeField::new('paidAt', 'Payée le')
            ->setFormat('d MMM yyyy HH:mm');
        yield AssociationField::new('event', 'Fiche de l\'événement')->onlyOnDetail();
        yield AssociationField::new('user', 'Fiche de l\'adhérent')->onlyOnDetail();
        yield TextField::new('checkoutIntentId', 'Demande de paiement HelloAsso (n°)')
            ->formatValue(static fn ($value) => $value === null ? null : (string) $value)
            ->onlyOnDetail();
        yield TextField::new('helloassoOrderId', 'Commande HelloAsso (n°)')
            ->formatValue(static fn ($value) => $value === null ? null : (string) $value)
            ->onlyOnDetail();
        yield DateTimeField::new('expiresAt', 'Réservation des places jusqu\'au')
            ->setFormat('d MMM yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('lastCheckedAt', 'Dernière vérification HelloAsso')
            ->setFormat('d MMM yyyy HH:mm')
            ->onlyOnDetail();
    }
}
