<?php

namespace App\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

class ProductAssociationFilterService
{
    public function filterAssociationsByChannel(ProductInterface $product, ChannelInterface $channel): void
    {
        foreach ($product->getAssociations() as $association) {
            $filtered = $association->getAssociatedProducts()->filter(function (ProductInterface $associated) use ($channel) {
                return $associated->isEnabled()
                    && $associated->getChannels()->exists(fn($key, $c) => $c->getCode() === $channel->getCode());
            });

            // Remplace la collection interne sans setter
            $reflection = new \ReflectionClass($association);
            $property = $reflection->getProperty('associatedProducts');
            $property->setAccessible(true);
            $property->setValue($association, new ArrayCollection($filtered->toArray()));
        }
    }
}
