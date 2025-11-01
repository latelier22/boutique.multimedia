<?php
namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Types
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

final class VendorType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // pas d’entité Doctrine ici → data_class null
            'data_class' => null,
            'is_edit'    => false, // <— option perso
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }

    public function buildForm(FormBuilderInterface $b, array $options): void
    {
        $isEdit = (bool) $options['is_edit'];

        $b
            ->add('supplier_name', TextType::class, [
                'label' => 'Nom',
                'required' => true,
            ])
            ->add('supplier_email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('supplier_contact', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
            ])
            ->add('supplier_address', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
            ])
            ->add('supplier_url', UrlType::class, [
                'label' => 'URL photo',
                'required' => false,
            ])
            ->add('supplier_enabled', ChoiceType::class, [
                'label' => 'Statut',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Actif'   => 1,
                    'Inactif' => 0,
                ],
            ])
            ->add('supplier_position', IntegerType::class, [
                'label' => 'Position',
                'required' => false,
                'empty_data' => '1',
            ])
            ->add('supplier_ref_ext', TextType::class, [
                'label' => 'Réf. externe',
                'required' => false,
                // 🔒 on la fige à l’édition
                'disabled' => $isEdit,
            ]);
    }
}
