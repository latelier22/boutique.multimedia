<?php
namespace App\Form;

use App\Entity\Rachat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Types
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;

// Events
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class RachatType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $b
          ->add('marqueModele', TextType::class, ['label'=>'Marque/Modèle', 'required'=>true])
          ->add('imei', TextType::class, ['required'=>false])
          ->add('numeroCi', TextType::class, ['label'=>"Numéro de carte d'identité", 'required'=>false])

          // ⬇️ MoneyType OK, mais on va nettoyer en PRE_SUBMIT (voir plus bas)
          ->add('prixAchat', MoneyType::class, [
              'currency'        => 'EUR',
              'required'        => true,
              'scale'           => 2,                  // 2 décimales
              'invalid_message' => 'Montant invalide.',
              // 'divisor' => 1, // laisse 1 si la propriété est déjà en euros (pas en centimes)
          ])

          ->add('nom', TextType::class, ['required'=>false])
          ->add('prenom', TextType::class, ['required'=>false])
          ->add('telephone', TextType::class, ['required'=>false])
          ->add('email', EmailType::class, ['required'=>false])
          ->add('adresse', TextType::class, ['required'=>false])
          ->add('codePostal', TextType::class, ['required'=>false])
          ->add('dateCession', DateType::class, ['widget'=>'single_text', 'required'=>false])

          // 🔽 uploads non mappés (gérés dans le contrôleur)
          ->add('pieceIdentiteRectoFile', FileType::class, [
    'label' => "Pièce d'identité (recto)",
    'mapped' => false,
    'required' => false,
])
->add('pieceIdentiteVersoFile', FileType::class, [
    'label' => "Pièce d'identité (verso)",
    'mapped' => false,
    'required' => false,
])


          ->add('photoFiles', FileType::class, [
              'label'=> 'Photos produit (max 4)',
              'mapped'=> false,
              'required'=> false,
              'multiple'=> true,
          ])
        ;

        // 🧹 Nettoyage de prixAchat avant transformation (évite "Expected a numeric")
        $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) {
            $data = $e->getData();
            if (!is_array($data)) return;

            if (array_key_exists('prixAchat', $data)) {
                $v = (string)($data['prixAchat'] ?? '');

                // supprime tout sauf chiffres, , . et -
                $s = preg_replace('/[^\d,.\-]/u', '', $v);

                // retire espaces/insécables éventuels
                $s = str_replace(["\xC2\xA0", ' '], '', $s);

                // si virgule présente et pas de point -> virgule = décimale FR
                if (str_contains($s, ',') && !str_contains($s, '.')) {
                    $s = str_replace(',', '.', $s);
                } else {
                    // sinon, virgules probables séparateurs -> supprime-les
                    // (ex: "1,234.56" ou "1,234" → "1234.56" / "1234")
                    $s = str_replace(',', '', $s);
                }

                // si vide -> null (laisser la validation gérer required)
                $data['prixAchat'] = ($s === '' ? null : $s);
                $e->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults(['data_class' => Rachat::class]);
    }
}
