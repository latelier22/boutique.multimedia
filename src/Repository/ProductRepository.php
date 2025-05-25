<?php
// src/Repository/ProductRepository.php

namespace App\Repository;


// ...
use Odiseo\SyliusVendorPlugin\Repository\ProductRepositoryInterface;
use Odiseo\SyliusVendorPlugin\Repository\ProductRepositoryTrait;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;
use Sylius\Component\Channel\Model\ChannelInterface;


class ProductRepository extends BaseProductRepository implements ProductRepositoryInterface
{
    use ProductRepositoryTrait;

    public function findBySlug(string $slug, string $locale): ?ProductInterface
    {
        return $this->createQueryBuilder('product')
            ->innerJoin('product.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere('translation.slug = :slug')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();
    }

  

    public function findByTaxonForChannel(TaxonInterface $taxon, ChannelInterface $channel): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->join('p.variants', 'v')
            ->join('v.channelPricings', 'cp')
            ->andWhere('t = :taxon')
            ->andWhere('p.enabled = true')
            ->andWhere('v.enabled = true')
            ->andWhere('cp.channelCode = :channelCode')
            ->setParameter('taxon', $taxon)
            ->setParameter('channelCode', $channel->getCode())
            ->addSelect('v', 'cp', 'pt', 't')
            ->getQuery()
            ->getResult();
    }

    public function findByTaxonSlug(string $slug, string $locale, ChannelInterface $channel): array

{
    return $this->createQueryBuilder('p')
        ->join('p.productTaxons', 'pt')
        ->join('pt.taxon', 't')
        ->join('t.translations', 'tt')
        ->join('p.channels', 'c')
        ->join('p.variants', 'v')
        ->join('v.channelPricings', 'cp')

        // 👇 on rajoute les select pour charger les taxons
        ->addSelect('pt', 't', 'tt')

        ->andWhere('tt.slug = :slug')
        ->andWhere('tt.locale = :locale')
        ->andWhere('p.enabled = true')
        ->andWhere('v.enabled = true')
        ->andWhere('cp.channelCode = :channelCode')
        ->andWhere('c.code = :channelCode')

        ->setParameter('slug', $slug)
        ->setParameter('locale', $locale)
        ->setParameter('channelCode', $channel->getCode())

        ->orderBy('pt.position', 'ASC')
        ->getQuery()
        ->getResult();
}

public function getTaxonCodesByProductCode(string $productCode): array
{
    $conn = $this->getEntityManager()->getConnection();

    $sql = <<<SQL
        SELECT DISTINCT t.code
        FROM sylius_product p
        INNER JOIN sylius_product_taxon pt ON pt.product_id = p.id
        INNER JOIN sylius_taxon t ON pt.taxon_id = t.id
        WHERE p.code = :productCode
    SQL;

    $stmt = $conn->prepare($sql);
    $result = $stmt->executeQuery(['productCode' => $productCode]);

    return array_column($result->fetchAllAssociative(), 'code');
}



}