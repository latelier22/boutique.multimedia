<?php

namespace App\Form;

use App\Entity\Upload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Dropzone\Form\DropzoneType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;


class UploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
       
$builder
  ->add('file', DropzoneType::class, ['label' => 'Image'])
  ->add('removeWhiteBg', CheckboxType::class, [
      'label' => 'Supprimer le fond blanc (autour)',
      'required' => false,
      'mapped' => false, // important : pas en base
  ])
  ->add('save', SubmitType::class, [
      'label' => 'Enregistrer',
      'attr' => ['class' => 'ui primary button'],
  ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Upload::class,
        ]);
    }
}