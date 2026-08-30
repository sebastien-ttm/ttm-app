<?php

namespace App\Controller\Admin;

use App\Entity\MembershipFee;
use App\Enum\Profile;
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

        yield ChoiceField::new('profile', 'Profil')
            ->setChoices([
                'Jeune' => Profile::Jeune->value,
                'U25' => Profile::U25->value,
                'Sénior' => Profile::Senior->value,
            ])
            ->renderAsBadges();

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
