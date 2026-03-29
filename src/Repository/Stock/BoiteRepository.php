<?php

namespace App\Repository\Stock;

use App\Entity\Stock\Boite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BoiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Boite::class);
    }

    public function findAvailable(): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.intervention', 'i')
            ->leftJoin('b.rachat', 'r')
            ->andWhere('b.status = :status')
            ->andWhere('i.id IS NULL')
            ->andWhere('r.id IS NULL')
            ->setParameter('status', Boite::STATUS_AVAILABLE)
            ->orderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAvailableOrCurrent(?Boite $current): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.intervention', 'i')
            ->leftJoin('b.rachat', 'r')
            ->orderBy('b.code', 'ASC');

        if ($current && $current->getId()) {
            $qb
                ->andWhere('((b.status = :status AND i.id IS NULL AND r.id IS NULL) OR b.id = :currentId)')
                ->setParameter('status', Boite::STATUS_AVAILABLE)
                ->setParameter('currentId', $current->getId());
        } else {
            $qb
                ->andWhere('b.status = :status')
                ->andWhere('i.id IS NULL')
                ->andWhere('r.id IS NULL')
                ->setParameter('status', Boite::STATUS_AVAILABLE);
        }

        return $qb->getQuery()->getResult();
    }

    public function findLastCreated(): ?Boite
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function nextCode(): string
    {
        $last = $this->findLastCreated();

        if (!$last) {
            return 'B01';
        }

        if (!preg_match('/^B(\d+)$/', $last->getCode(), $m)) {
            return 'B01';
        }

        return sprintf('B%02d', ((int) $m[1]) + 1);
    }
}