<?php
// src/Repository/TillRepository.php

namespace App\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository; // <— IMPORTANT

final class TillRepository extends EntityRepository
{
    /**
     * Exemple: requête DB classique (au besoin) — reste vide si tu ne l’utilises pas.
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
