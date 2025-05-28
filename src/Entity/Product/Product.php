<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Product as BaseProduct;
use Sylius\Component\Core\Model\ProductTranslationInterface;



/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_product")
 */
class Product extends BaseProduct 
{
    

    /**
     * Override the base method to return your custom translation.
     */
    protected function createTranslation(): ProductTranslationInterface
    {
        return new ProductTranslation();
    }
}
