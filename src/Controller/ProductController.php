<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product\ProductVariant;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use App\Service\ProductAssociationFilterService;


class ProductController extends ResourceController

{
    function hasTaxonCode($product, string $expectedCode): bool
    {
        foreach ($product->getProductTaxons() as $productTaxon) {
            $taxon = $productTaxon->getTaxon();
            while ($taxon !== null) {
                if ($taxon->getCode() === $expectedCode) {
                    return true;
                }
                $taxon = $taxon->getParent();
            }
        }
        return false;
    }

    public function showAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);
        $this->isGrantedOr403($configuration, ResourceActions::SHOW);

        /** @var \App\Entity\Product\Product $product */
        $product = $this->findOr404($configuration);
        $this->eventDispatcher->dispatch(ResourceActions::SHOW, $configuration, $product);

        $channel = $this->get('sylius.context.channel')->getChannel();
        $filterService = $this->get(ProductAssociationFilterService::class);
        $filterService->filterAssociationsByChannel($product, $channel);

        $hasPromotionsTaxon = $this->hasTaxonCode($product, 'PROMOTIONS');

               
        

    return $this->render($configuration->getTemplate(ResourceActions::SHOW . '.html'), [
        'configuration' => $configuration,
        'metadata' => $this->metadata,
        'resource' => $product,
        'product' => $product,
        'hasPromotionsTaxon' => $hasPromotionsTaxon,
    ]);
}
}