<?php
namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigTest;

class AppExtension extends AbstractExtension
{
    public function getTests(): array
    {
        return [
            new TwigTest('string', fn($v) => is_string($v)),
        ];
    }
}
