<?php

namespace App\Twig;

use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TaxonExtension extends AbstractExtension
{
    private TaxonRepositoryInterface $taxonRepository;
    private ProductRepositoryInterface $productRepository;
    private ChannelContextInterface $channelContext;
    private CurrencyContextInterface $currencyContext;

    public function __construct(
        TaxonRepositoryInterface $taxonRepository,
        ProductRepositoryInterface $productRepository,
        ChannelContextInterface $channelContext,
        CurrencyContextInterface $currencyContext
    ) {
        $this->taxonRepository = $taxonRepository;
        $this->productRepository = $productRepository;
        $this->channelContext = $channelContext;
        $this->currencyContext = $currencyContext;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('front_taxons', [$this, 'getFrontTaxons']),
            new TwigFunction('lowest_price_by_taxon', [$this, 'getLowestPriceByTaxon']),
        ];
    }

    public function getFrontTaxons(): array
    {
        return $this->taxonRepository->createQueryBuilder('t')
            ->where('t.isFront = true')
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getLowestPriceByTaxon(TaxonInterface $taxon): ?int
    {
        $channel = $this->channelContext->getChannel();

        $products = $this->productRepository->findByTaxon($taxon);
        $min = null;

        foreach ($products as $product) {
            foreach ($product->getVariants() as $variant) {
                if (!$variant->isEnabled()) {
                    continue;
                }

                $channelPricing = $variant->getChannelPricingForChannel($channel);

                if ($channelPricing && $channelPricing->getPrice() !== null) {
                    $price = $channelPricing->getPrice();
                    $min = $min === null ? $price : min($min, $price);
                }
            }
        }

        return $min;
    }
}
