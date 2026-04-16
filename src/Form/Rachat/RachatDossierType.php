<?php

namespace App\Form\Rachat;

use App\Entity\Rachat\RachatDossier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class RachatDossierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Nom',
            ])
            ->add('prenomSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Prénom',
            ])
            ->add('telephoneSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Téléphone',
            ])
            ->add('emailSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Email',
            ])
            ->add('adresseSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Adresse',
            ])
            ->add('codePostalSnapshot', TextType::class, [
                'required' => false,
                'label' => 'Code postal',
            ])
            ->add('numeroCiSnapshot', TextType::class, [
                'required' => false,
                'label' => 'N° pièce identité',
            ])
            ->add('hibCustomerId', TextType::class, [
                'required' => false,
                'label' => 'Client Hiboutik',
            ])
            ->add('paidMethod', ChoiceType::class, [
                'required' => false,
                'label' => 'Mode de paiement',
                'placeholder' => 'Choisir',
                'choices' => [
                    'Espèces' => 'ESP',
                    'Carte bancaire' => 'CB',
                    'Virement' => 'VIR',
                    'Chèque' => 'CHQ',
                ],
            ])
            ->add('dateCession', DateTimeType::class, [
                'required' => false,
                'label' => 'Date de cession',
                'widget' => 'single_text',
            ])
            ->add('items', CollectionType::class, [
                'entry_type' => RachatItemType::class,
                'entry_options' => [
                    'brands_choices' => $options['brands_choices'],
                    'categories_choices' => $options['categories_choices'],
                    'categories_disabled' => $options['categories_disabled'],
                    'boites_choices' => $options['boites_choices'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__name__',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RachatDossier::class,
            'brands_choices' => [],
            'categories_choices' => [],
            'categories_disabled' => [],
            'boites_choices' => [],
        ]);
    }
}