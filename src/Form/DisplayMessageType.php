<?php

namespace App\Form;

use App\Entity\DisplayMessage;
use App\Service\DiaporamaSlotProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DisplayMessageType extends AbstractType
{
    public function __construct(
        private DiaporamaSlotProvider $slotProvider,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $slotChoices = $this->slotProvider->getChoices();

        $currentSlot = $options['data']?->getSlot();
        if ($currentSlot && !in_array($currentSlot, $slotChoices, true)) {
            $slotChoices[$currentSlot] = $currentSlot;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom interne',
            ])
            ->add('slot', ChoiceType::class, [
                'label' => 'Diaporama',
                'choices' => $slotChoices,
                'placeholder' => 'Choisir un diaporama',
                'required' => true,
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'Template',
                'choices' => [
                    'Annonce' => 'announcement',
                    'Promo' => 'promo',
                    'Alerte' => 'warning',
                    'Événement' => 'event',
                ],
            ])
            ->add('badge', TextType::class, [
                'label' => 'Badge / accroche',
                'required' => false,
            ])
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'required' => false,
            ])
            ->add('line1', TextType::class, [
                'label' => 'Ligne 1',
                'required' => false,
            ])
            ->add('line2', TextType::class, [
                'label' => 'Ligne 2',
                'required' => false,
            ])
            ->add('line3', TextType::class, [
                'label' => 'Ligne 3',
                'required' => false,
            ])
            ->add('footerText', TextType::class, [
                'label' => 'Pied de page',
                'required' => false,
            ])
            ->add('backgroundType', ChoiceType::class, [
                'label' => 'Fond',
                'choices' => [
                    'Noir' => 'black',
                    'Orange' => 'orange',
                    'Dégradé sombre' => 'gradient',
                ],
            ])
            ->add('textAlign', ChoiceType::class, [
                'label' => 'Alignement',
                'choices' => [
                    'Centre' => 'center',
                    'Gauche' => 'left',
                ],
            ])
            ->add('accentColor', ColorType::class, [
                'label' => 'Couleur accent',
                'required' => false,
            ])
            ->add('intervalSeconds', IntegerType::class, [
                'label' => 'Afficher toutes les X secondes',
            ])
            ->add('durationSeconds', IntegerType::class, [
                'label' => 'Afficher pendant X secondes',
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Ordre',
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Début de diffusion',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Fin de diffusion',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DisplayMessage::class,
        ]);
    }
}