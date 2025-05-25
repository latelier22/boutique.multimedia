<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use App\Repository\ProductRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ProductInterface;

class ProductExtension extends AbstractExtension
{
    public function __construct(
        private ProductRepository $productRepository,
        private ChannelContextInterface $channelContext
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('products_by_taxon_slug', [$this, 'getProductsByTaxonSlug']),
            new TwigFunction('hasExactTaxonCode', [$this, 'hasExactTaxonCode']),
            new TwigFunction('hasTaxonCode', [$this, 'hasTaxonCode']),
            new TwigFunction('product_has_taxon', [$this, 'productHasTaxon']),
        ];
    }

    public function getProductsByTaxonSlug(string $slug, string $locale): array
    {
        $channel = $this->channelContext->getChannel();

        // Appelle le repo qui filtre déjà les produits par canal
        return $this->productRepository->findByTaxonSlug($slug, $locale, $channel);
    }

    public function hasExactTaxonCode(ProductInterface $product, string $expectedCode): bool
    {
        foreach ($product->getProductTaxons() as $productTaxon) {
            $taxon = $productTaxon->getTaxon();
            if ($taxon && $taxon->getCode() === $expectedCode) {
                return true;
            }
        }
        return false;
    }

    public function hasTaxonCode(ProductInterface $product, string $expectedCode): bool
{
    foreach ($product->getProductTaxons() as $productTaxon) {
        $taxon = $productTaxon->getTaxon();

        if ($taxon?->getCode() === $expectedCode) {
            return true;
        }
    }

    return false;
}

public function productHasTaxon(ProductInterface $product, string $taxonCode): bool
{
    $codes = $this->productRepository->getTaxonCodesByProductCode($product->getCode());
    return in_array($taxonCode, $codes, true);
}


}


    
