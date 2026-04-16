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
        ->leftJoin('b.items', 'ri')
        ->andWhere('b.status = :status')
        ->andWhere('i.id IS NULL')
        ->andWhere('r.id IS NULL')
        ->andWhere('ri.id IS NULL')
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
        ->leftJoin('b.items', 'ri')
        ->orderBy('b.code', 'ASC');

    if ($current && $current->getId()) {
        $qb
            ->andWhere('((b.status = :status AND i.id IS NULL AND r.id IS NULL AND ri.id IS NULL) OR b.id = :currentId)')
            ->setParameter('status', Boite::STATUS_AVAILABLE)
            ->setParameter('currentId', $current->getId());
    } else {
        $qb
            ->andWhere('b.status = :status')
            ->andWhere('i.id IS NULL')
            ->andWhere('r.id IS NULL')
            ->andWhere('ri.id IS NULL')
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


    public function findRoots(): array
{
    $rows = $this->createQueryBuilder('b')
        ->select('b.code')
        ->orderBy('b.code', 'ASC')
        ->getQuery()
        ->getScalarResult();

    $roots = [];

    foreach ($rows as $row) {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));

        if ($code === '') {
            continue;
        }

        if (preg_match('/^([A-Z_-]+?)(\d+)$/', $code, $m)) {
            $root = $m[1];
        } else {
            $root = $code;
        }

        if ($root !== '') {
            $roots[$root] = $root;
        }
    }

    ksort($roots, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($roots);
}

public function nextCodeForRoot(string $root): string
{
    $root = strtoupper(trim($root));

    $rows = $this->createQueryBuilder('b')
        ->select('b.code')
        ->andWhere('b.code LIKE :prefix')
        ->setParameter('prefix', $root . '%')
        ->getQuery()
        ->getScalarResult();

    $max = 0;

    foreach ($rows as $row) {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));

        if (preg_match('/^' . preg_quote($root, '/') . '(\d+)$/', $code, $m)) {
            $num = (int) $m[1];
            if ($num > $max) {
                $max = $num;
            }
        }
    }

    return sprintf('%s%02d', $root, $max + 1);
}
}