<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariant as BaseProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface as BaseProductVariantInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;

/**
 * @ORM\Entity
 * @ORM\Table(name="sylius_product_variant")
 */
#[ORM\Entity]
#[ORM\Table(name: 'sylius_product_variant')]
class ProductVariant extends BaseProductVariant implements BaseProductVariantInterface
{

    protected function createTranslation(): ProductVariantTranslationInterface
    {
        return new ProductVariantTranslation();
    }

    // 🔧 Correction : retour non-nullable requis par l'interface du plugin
    public function getProduct(): ProductInterface
    {
        /** @var ProductInterface */
        return parent::getProduct(); // ⚠️ Doit ne jamais être null !
    }
}
