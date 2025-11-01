<?php

namespace App\Twig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class JsonExtension extends AbstractExtension {
    public function getFilters(): array {
        return [
            new TwigFilter('json_decode', fn ($v, $assoc = true) => json_decode((string)$v, $assoc)),
        ];
    }
}


