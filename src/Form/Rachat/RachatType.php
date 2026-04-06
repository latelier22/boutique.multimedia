<?php
namespace App\Form\Rachat;

use App\Entity\Rachat\Rachat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Types
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

// Events
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class RachatType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $opt): void
    {
        $brandChoices = $opt['hib_brands_choices'] ?? [];
$catChoices   = $opt['hib_categories_choices'] ?? [];

if (!is_array($brandChoices)) $brandChoices = [];
if (!is_array($catChoices))   $catChoices = [];

    $b
        ->add('hibBrandId', ChoiceType::class, [
            'label' => 'Marque',
            'required' => false,
            'placeholder' => '— Choisir —',
            'choices' => $brandChoices, // ['Apple' => 12, ...]
        ])
        ->add('hibCategoryId', ChoiceType::class, [
            'label' => 'Catégorie',
            'required' => false,
            'placeholder' => '— Choisir —',
            'choices' => $catChoices,   // ['Smartphone' => 3, ...]
        ])
            ->add('marqueModele', TextType::class, [
                'label' => 'Marque/Modèle',
                'required' => true,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('imei', TextType::class, [
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('numeroCi', TextType::class, [
                'label' => "Numéro de carte d'identité",
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])

            // ⬇️ MoneyType OK, nettoyage en PRE_SUBMIT (voir plus bas)
            ->add('prixAchat', MoneyType::class, [
                'currency'        => 'EUR',
                'required'        => true,
                'scale'           => 2,
                'invalid_message' => 'Montant invalide.',
            ])

            ->add('nom', TextType::class, ['required' => false, 'attr' => ['autocomplete' => 'off']])
            ->add('prenom', TextType::class, ['required' => false, 'attr' => ['autocomplete' => 'off']])
            ->add('telephone', TextType::class, [
                'required' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'inputmode' => 'tel',
                    'placeholder' => '06...',
                ],
            ])
            ->add('email', EmailType::class, [
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'mail@...'],
            ])

            // ✅ adresse multi-lignes
            ->add('adresse', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => "Adresse (ligne 1)\nAdresse (ligne 2)"],
            ])

            ->add('codePostal', TextType::class, [
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'maxlength' => 10],
            ])

            // ✅ date picker HTML5 (valeur par défaut à mettre côté Entity/Controller)
            ->add('dateCession', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'html5' => true,
            ])

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
                'label' => 'Photos produit (max 4)',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
            ])
        ;

        $b->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $e) {
            $data = $e->getData();
            if (!is_array($data)) return;

            // 🧹 Nettoyage prixAchat (évite "Expected a numeric")
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
                    $s = str_replace(',', '', $s);
                }

                $data['prixAchat'] = ($s === '' ? null : $s);
            }

            // 🧹 Normalisation téléphone (supprime espaces, points, tirets, etc.)
            if (array_key_exists('telephone', $data)) {
                $tel = trim((string)($data['telephone'] ?? ''));
                $tel = preg_replace('/[^\d+]/', '', $tel);
                $data['telephone'] = ($tel === '' ? null : $tel);
            }

            // 🧹 Normalisation email (trim + lowercase)
            if (array_key_exists('email', $data)) {
                $email = strtolower(trim((string)($data['email'] ?? '')));
                $data['email'] = ($email === '' ? null : $email);
            }

            // 🧹 Trim nom/prenom
            foreach (['nom', 'prenom'] as $k) {
                if (array_key_exists($k, $data)) {
                    $v = trim((string)($data[$k] ?? ''));
                    $data[$k] = ($v === '' ? null : $v);
                }
            }

            // 🧹 Trim imei / numeroCi / codePostal
            foreach (['imei', 'numeroCi', 'codePostal'] as $k) {
                if (array_key_exists($k, $data)) {
                    $v = trim((string)($data[$k] ?? ''));
                    $data[$k] = ($v === '' ? null : $v);
                }
            }

            // 🧹 Adresse trim (garde les retours à la ligne)
            if (array_key_exists('adresse', $data)) {
                $addr = (string)($data['adresse'] ?? '');
                $addr = str_replace(["\r\n", "\r"], "\n", $addr);
                $addr = trim($addr);
                $data['adresse'] = ($addr === '' ? null : $addr);
            }

            $e->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $r): void
{
    $r->setDefaults([
        'data_class' => Rachat::class,
        'hib_brands_choices' => [],
        'hib_categories_choices' => [],
    ]);

    $r->setAllowedTypes('hib_brands_choices', 'array');
    $r->setAllowedTypes('hib_categories_choices', 'array');
}
}