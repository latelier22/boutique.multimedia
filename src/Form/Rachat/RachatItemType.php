<?php

namespace App\Form\Rachat;

use App\Entity\Rachat\RachatItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use App\Entity\Stock\Boite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class RachatItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('designation', TextType::class, [
                'required' => false,
                'label' => 'Désignation',
            ])
            ->add('marqueModele', TextType::class, [
                'required' => false,
                'label' => 'Marque / Modèle',
            ])
            ->add('imei', TextType::class, [
                'required' => false,
                'label' => 'IMEI',
            ])
            ->add('prixAchat', TextType::class, [
                'required' => false,
                'label' => 'Prix achat',
            ])
            ->add('hibBrandId', ChoiceType::class, [
                'required' => false,
                'label' => 'Marque Hiboutik',
                'choices' => $options['brands_choices'],
                'placeholder' => 'Choisir une marque',
                'attr' => [
                    'class' => 'ui fluid search selection dropdown js-item-brand-real',
                ],
            ])
            ->add('hibCategoryId', ChoiceType::class, [
                'required' => false,
                'label' => 'Catégorie Hiboutik',
                'choices' => $options['categories_choices'],
                'placeholder' => 'Choisir une catégorie',
                'choice_attr' => function ($choice, $label, $value) use ($options) {
                    return !empty($options['categories_disabled'][(string) $value])
                        ? ['disabled' => 'disabled']
                        : [];
                },
                'attr' => [
                    'class' => 'ui fluid search selection dropdown js-item-category-select',
                ],
            ])
            ->add('convertToHib', CheckboxType::class, [
                'required' => false,
                'label' => 'Créer le produit Hiboutik',
            ])
            ->add('reconditioningStatus', ChoiceType::class, [
                'required' => false,
                'label' => 'Statut atelier',
                'choices' => [
                    'Pas de reconditionnement' => RachatItem::RECONDITIONING_NONE,
                    'À reconditionner' => RachatItem::RECONDITIONING_TODO,
                    'En cours' => RachatItem::RECONDITIONING_IN_PROGRESS,
                    'Reconditionné' => RachatItem::RECONDITIONING_DONE,
                    'Échec reconditionnement' => RachatItem::RECONDITIONING_FAILED,
                    'Pour pièces' => RachatItem::RECONDITIONING_PARTS,
                ],
                'attr' => [
                    'class' => 'ui dropdown',
                ],
            ])
            ->add('boite', EntityType::class, [
                'class' => Boite::class,
                'required' => false,
                'label' => 'Boîte / emplacement',
                'choices' => $options['boites_choices'],
                'choice_label' => function (Boite $boite) {
                    return $boite->getCode();
                },
                'placeholder' => 'Choisir une boîte',
                'attr' => [
                    'class' => 'ui fluid search selection dropdown',
                ],
            ])
            ->add('attributes', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'class' => 'js-item-attributes-json',
                ],

            ])
            ->add('reconditioningStatus', ChoiceType::class, [
    'required' => false,
    'label' => 'Statut atelier',
    'choices' => [
        'Pas de reconditionnement' => 'none',
        'À reconditionner' => 'todo',
        'En cours' => 'in_progress',
        'Reconditionné' => 'done',
        'Échec reconditionnement' => 'failed',
        'Pour pièces' => 'parts',
    ],
    'attr' => [
        'class' => 'ui dropdown js-reconditioning-status',
    ],
])
->add('reconditioningWorkToDo', TextareaType::class, [
    'required' => false,
    'label' => 'Travail à faire',
    'attr' => [
        'rows' => 4,
        'class' => 'js-reconditioning-work',
        'placeholder' => 'Ex : changement batterie, nettoyage, écran à remplacer, tests, reset, etc.',
    ],
])
->add('reconditioningNotes', TextareaType::class, [
    'required' => false,
    'label' => 'Notes atelier',
    'attr' => [
        'rows' => 3,
        'class' => 'js-reconditioning-notes',
        'placeholder' => 'Remarques internes atelier',
    ],
])
        ;

        $builder->get('attributes')->addModelTransformer(new CallbackTransformer(
            function ($value) {
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if (is_string($value)) {
                    return $value;
                }

                return '';
            },
            function ($value) {
                if ($value === null || $value === '') {
                    return [];
                }

                if (is_array($value)) {
                    return $value;
                }

                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RachatItem::class,
            'brands_choices' => [],
            'categories_choices' => [],
            'categories_disabled' => [],
            'boites_choices' => [],
        ]);
    }
}