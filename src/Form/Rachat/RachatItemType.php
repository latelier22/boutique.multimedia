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
            ->add('attributes', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'class' => 'js-item-attributes-json',
                ],
            ])
            ->add('convertToHib', CheckboxType::class, [
    'required' => false,
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
        ]);
    }
}