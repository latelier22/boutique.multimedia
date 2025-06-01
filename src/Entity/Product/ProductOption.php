<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use MonsieurBiz\SyliusSearchPlugin\Entity\Product\SearchableInterface;
use MonsieurBiz\SyliusSearchPlugin\Model\Product\SearchableTrait;
use Sylius\Component\Product\Model\ProductOption as BaseProductOption;
use Sylius\Component\Product\Model\ProductOptionTranslationInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_product_option")
 */
#[ORM\Entity]
#[ORM\Table(name: 'sylius_product_option')]
class ProductOption extends BaseProductOption implements SearchableInterface
{
    use SearchableTrait;

    protected function createTranslation(): ProductOptionTranslationInterface
    {
        return new ProductOptionTranslation();
    }
}


