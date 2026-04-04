<?php

namespace App\Service;

use App\Service\HiboutikClient;

class DiaporamaSlotProvider
{
    public function __construct(
        private HiboutikClient $hiboutikClient,
    ) {
    }

    /**
     * Retourne les choix pour le formulaire Symfony :
     * [
     *   'Vitrine droite' => 'vitrine-droite',
     *   'Vitrine gauche' => 'vitrine-gauche',
     * ]
     */
    public function getChoices(): array
    {
        $choices = [];

        try {
            $catalog = $this->hiboutikClient->buildGroupedProductTagCatalog();
            $groups = $catalog['groups'] ?? [];

            foreach ($groups as $group) {
                $catName = strtolower(trim((string)($group['name'] ?? '')));

                if ($catName !== 'diaporama') {
                    continue;
                }

                foreach (($group['tags'] ?? []) as $tag) {
                    $label = trim((string)($tag['label'] ?? ''));
                    $desc  = trim((string)($tag['desc'] ?? ''));

                    if ($label === '') {
                        continue;
                    }

                    // Valeur stockée = desc si rempli, sinon label
                    $value = $desc !== '' ? $desc : $this->slugify($label);

                    $choices[$label] = $value;
                }
            }
        } catch (\Throwable $e) {
            // on évite de planter le form si Hiboutik répond mal
            return [];
        }

        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    /**
     * Retourne les slots sous forme de tableau simple:
     * [
     *   ['label' => 'Vitrine droite', 'value' => 'vitrine-droite'],
     *   ...
     * ]
     */
    public function getList(): array
    {
        $out = [];
        foreach ($this->getChoices() as $label => $value) {
            $out[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $out;
    }

    private function slugify(string $text): string
    {
        $text = trim(mb_strtolower($text));
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');

        return $text !== '' ? $text : 'slot';
    }
}