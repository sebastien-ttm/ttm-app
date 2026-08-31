<?php

namespace App\Controller\Admin;

use App\Entity\MembershipFee;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class MembershipFeeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MembershipFee::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarif d\'adhésion')
            ->setEntityLabelInPlural('Grille tarifaire')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['season' => 'DESC', 'profile' => 'ASC', 'typeLicence' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('season', 'Saison')
            ->autocomplete()
            ->setRequired(true);

        yield ChoiceField::new('profile', 'Profil tarifaire')
            ->setChoices([
                'Jeune' => MembershipFee::PROFILE_JEUNE,
                'Sénior' => MembershipFee::PROFILE_SENIOR,
                'U25' => MembershipFee::PROFILE_U25,
            ])
            ->renderAsBadges()
            ->setHelp(
                'Jeune / Sénior sont dérivés automatiquement de l\'âge à l\'import. '
                .'U25 est un tarif optionnel : sélectionnable à la main dans la fiche '
                .'d\'adhésion pour facturer un adhérent Sénior sur ce barème dédié.'
            );

        yield ChoiceField::new('typeLicence', 'Type de licence')
            ->setChoices([
                'Compétition' => MembershipFee::TYPE_COMPETITION,
                'Loisir' => MembershipFee::TYPE_LOISIR,
                'Dirigeant' => MembershipFee::TYPE_DIRIGEANT,
            ])
            ->renderAsBadges();

        yield NumberField::new('amount', 'Montant (€)')
            ->setNumDecimals(2)
            ->setHelp('Prix TTC en euros. Stocké en centimes en base.');
    }
}
