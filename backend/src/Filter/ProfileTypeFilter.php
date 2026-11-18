<?php

namespace App\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use App\Enum\Profile;
use App\Enum\UserType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Filtre « Profil / type de compte » de la liste des utilisateurs : un seul champ à choix multiples qui mélange
 *  - le type de compte (colonne `type` : Adhérent, Externe) et
 *  - les profils (colonne JSON `profiles` : Jeune, Sénior, Parent, Entraîneur, Encadrant, Gestionnaire externe).
 *
 * Plusieurs valeurs cochées = « l'un OU l'autre » (ex. Entraîneur + Encadrant) ; la comparaison « différent de »
 * exclut, elle, tous les comptes qui en ont au moins un. Les valeurs sont celles des enums (aucune ne se recoupe).
 * La propriété (`profileType`) est virtuelle : elle n'existe pas sur l'entité, seule apply() en tire des clauses.
 */
final class ProfileTypeFilter implements FilterInterface
{
    use FilterTrait;

    /**
     * @param TranslatableInterface|string|false|null $label
     */
    public static function new(string $propertyName, $label = null): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle')
            ->setFormTypeOption('value_type_options.multiple', true)
            ->setFormTypeOption('value_type_options.choices', self::choices());
    }

    /**
     * Choix groupés : « Type de compte » puis « Profil ».
     *
     * @return array<string, array<string, string>>
     */
    public static function choices(): array
    {
        $types = [];
        foreach (UserType::cases() as $type) {
            $types[$type->label()] = $type->value;
        }

        return [
            'Type de compte' => $types,
            'Profil' => Profile::choices(),
        ];
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $values = $filterDataDto->getValue();
        $values = is_array($values) ? $values : [$values];
        $alias = $filterDataDto->getEntityAlias();
        $parameterName = $filterDataDto->getParameterName();

        $clauses = [];
        foreach (array_values(array_filter($values, 'is_string')) as $i => $value) {
            $parameter = $parameterName.'_'.$i;
            if (UserType::tryFrom($value) !== null) {
                $clauses[] = sprintf('%s.type = :%s', $alias, $parameter);
                $queryBuilder->setParameter($parameter, $value);
            } elseif (Profile::tryFrom($value) !== null) {
                $clauses[] = sprintf('JSON_CONTAINS(%s.profiles, :%s) = 1', $alias, $parameter);
                $queryBuilder->setParameter($parameter, json_encode($value));
            }
        }
        if ($clauses === []) {
            return; // rien de coché (ou valeur inconnue) : pas de restriction
        }

        $condition = '('.implode(' OR ', $clauses).')';
        $negated = in_array($filterDataDto->getComparison(), ['!=', 'NOT IN'], true);
        $queryBuilder->andWhere($negated ? 'NOT '.$condition : $condition);
    }
}
