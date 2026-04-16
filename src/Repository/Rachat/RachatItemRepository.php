<?php

namespace App\Repository\Rachat;

use App\Entity\Rachat\RachatItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

final class RachatItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RachatItem::class);
    }

    public function findForReconditioning(string $statusFilter = 'open', string $q = ''): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.dossier', 'd')->addSelect('d')
            ->leftJoin('i.boite', 'b')->addSelect('b')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('i.id', 'DESC');

        $this->applyStatusFilter($qb, $statusFilter);
        $this->applySearch($qb, $q);

        return $qb->getQuery()->getResult();
    }

    public function countByReconditioningStatus(string $q = ''): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.reconditioningStatus AS status, COUNT(i.id) AS total')
            ->leftJoin('i.dossier', 'd')
            ->leftJoin('i.boite', 'b')
            ->groupBy('i.reconditioningStatus');

        $this->applySearch($qb, $q);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [
            'none' => 0,
            'todo' => 0,
            'in_progress' => 0,
            'done' => 0,
            'failed' => 0,
            'parts' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        $counts['open'] =
            $counts['todo']
            + $counts['in_progress']
            + $counts['failed']
            + $counts['parts'];

        $counts['all_except_none'] =
            $counts['todo']
            + $counts['in_progress']
            + $counts['done']
            + $counts['failed']
            + $counts['parts'];

        return $counts;
    }

    private function applyStatusFilter(QueryBuilder $qb, string $statusFilter): void
    {
        switch ($statusFilter) {
            case 'all':
                $qb->andWhere('i.reconditioningStatus != :none')
                    ->setParameter('none', 'none');
                break;

            case 'todo':
            case 'in_progress':
            case 'done':
            case 'failed':
            case 'parts':
                $qb->andWhere('i.reconditioningStatus = :status')
                    ->setParameter('status', $statusFilter);
                break;

            case 'open':
            default:
                $qb->andWhere('i.reconditioningStatus IN (:statuses)')
                    ->setParameter('statuses', [
                        'todo',
                        'in_progress',
                        'failed',
                        'parts',
                    ]);
                break;
        }
    }

    private function applySearch(QueryBuilder $qb, string $q): void
    {
        $q = trim(mb_strtolower($q));
        if ($q === '') {
            return;
        }

        $like = '%' . $q . '%';

        $qb->andWhere(
            $qb->expr()->orX(
                'LOWER(COALESCE(i.designation, \'\')) LIKE :q',
                'LOWER(COALESCE(i.marqueModele, \'\')) LIKE :q',
                'LOWER(COALESCE(i.imei, \'\')) LIKE :q',
                'LOWER(COALESCE(i.numeroSerie, \'\')) LIKE :q',
                'LOWER(COALESCE(d.reference, \'\')) LIKE :q',
                'LOWER(COALESCE(d.nomSnapshot, \'\')) LIKE :q',
                'LOWER(COALESCE(d.prenomSnapshot, \'\')) LIKE :q',
                'LOWER(COALESCE(b.code, \'\')) LIKE :q'
            )
        )->setParameter('q', $like);
    }
}