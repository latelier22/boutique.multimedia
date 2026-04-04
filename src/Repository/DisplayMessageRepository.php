<?php

namespace App\Repository;

use App\Entity\DisplayMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DisplayMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DisplayMessage::class);
    }

    public function findForAdmin(?string $slot = null): array
    {
        $qb = $this->createQueryBuilder('m');

        if ($slot !== null && $slot !== '') {
            $qb
                ->andWhere('m.slot = :slot')
                ->setParameter('slot', $slot);
        }

        if ($slot) {
            return $qb
                ->orderBy('m.sortOrder', 'ASC')
                ->addOrderBy('m.id', 'DESC')
                ->getQuery()
                ->getResult();
        }

        return $qb
            ->orderBy('m.slot', 'ASC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForSlot(string $slot, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        return $this->createQueryBuilder('m')
            ->andWhere('m.slot = :slot')
            ->andWhere('m.isEnabled = :enabled')
            ->andWhere('(m.startsAt IS NULL OR m.startsAt <= :now)')
            ->andWhere('(m.endsAt IS NULL OR m.endsAt >= :now)')
            ->setParameter('slot', trim($slot))
            ->setParameter('enabled', true)
            ->setParameter('now', $now)
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}