<?php
namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextType, EmailType, IntegerType, UrlType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SupplierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $options): void
    {
        $b
          ->add('supplier_name', TextType::class, ['label' => 'Nom du vendeur'])
          ->add('supplier_email', EmailType::class, ['required'=>false, 'label'=>'Email'])
          ->add('supplier_contact', TextType::class, ['required'=>false, 'label'=>'Téléphone'])
          ->add('supplier_address', TextareaType::class, ['required'=>false, 'label'=>'Adresse'])
          ->add('supplier_position', IntegerType::class, ['required'=>false, 'label'=>'Position', 'empty_data' => '1'])
          ->add('supplier_enabled', IntegerType::class, ['required'=>false, 'label'=>'Statut (1=actif,0=off)', 'empty_data' => '1'])
          ->add('supplier_url', UrlType::class, ['required'=>false, 'label'=>'Photo / lien externe']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true]);
    }
}
