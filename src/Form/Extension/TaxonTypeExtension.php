<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Sylius\Bundle\TaxonomyBundle\Form\Type\TaxonType;

class TaxonTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('isFront', CheckboxType::class, [
            'label' => 'Afficher sur la page d’accueil (isFront)',
            'required' => false,
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [TaxonType::class];
    }
}
