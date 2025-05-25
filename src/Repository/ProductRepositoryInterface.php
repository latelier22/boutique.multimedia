<?php

// src/Repository/ProductRepositoryInterface.php
namespace App\Repository;

use Sylius\Component\Core\Repository\ProductRepositoryInterface as BaseInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Channel\Model\ChannelInterface;

interface ProductRepositoryInterface extends BaseInterface
{
    public function findBySlug(string $slug, string $locale): ?ProductInterface;

    public function findByTaxonSlug(string $slug, string $locale, ChannelInterface $channel): array;

    public function findByTaxonForChannel(TaxonInterface $taxon, ChannelInterface $channel): array;

    

}
