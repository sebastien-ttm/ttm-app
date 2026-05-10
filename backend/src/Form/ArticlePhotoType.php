<?php

namespace App\Form;

use App\Entity\ArticlePhoto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Form fragment used inside the Article admin form's "photos" collection.
 * Handles the actual file upload via VichUploader in a nested context.
 */
class ArticlePhotoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', VichImageType::class, [
                'label' => 'Image',
                'required' => false,
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
                'imagine_pattern' => null,
            ])
            ->add('alt', TextType::class, [
                'label' => 'Description (alt)',
                'required' => false,
                'attr' => ['placeholder' => 'Décrivez la photo (accessibilité)'],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Ordre',
                'required' => false,
                'empty_data' => '0',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ArticlePhoto::class,
        ]);
    }
}
