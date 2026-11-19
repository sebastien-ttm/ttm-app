<?php

namespace App\Form;

use App\Entity\EventTariff;
use App\Enum\Profile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une ligne de la collection « Tarifs » du formulaire d'un événement (inscription payante HelloAsso) :
 * libellé, prix, places, public visé.
 */
class EventTariffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                // Sans valeur de repli, un champ vidé enverrait null au setter typé string.
                'empty_data' => '',
                'attr' => ['placeholder' => 'Ex : Adulte, Jeune, Repas…', 'maxlength' => 120],
            ])
            ->add('amountCents', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'EUR',
                // Le modèle stocke des centimes, le formulaire montre des euros.
                'divisor' => 100,
                'scale' => 2,
                'attr' => ['placeholder' => '0,00'],
                'help' => '0 € = option gratuite.',
            ])
            ->add('maxPlaces', IntegerType::class, [
                'label' => 'Places',
                'required' => false,
                'attr' => ['min' => 1, 'placeholder' => 'Illimité'],
                'help' => 'Quantité maximale vendable pour ce tarif. Vide = illimité.',
            ])
            ->add('description', TextType::class, [
                'label' => 'Précision',
                'required' => false,
                'attr' => ['placeholder' => 'Ex : né après 2010, repas du samedi soir…', 'maxlength' => 255],
            ])
            ->add('audience', ChoiceType::class, [
                'label' => 'Réservé aux profils',
                'choices' => Profile::choices(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Aucun profil coché = proposé à tous les adhérents qui voient l\'événement.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventTariff::class,
        ]);
    }
}
