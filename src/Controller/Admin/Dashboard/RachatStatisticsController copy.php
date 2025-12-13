<?php

declare(strict_types=1);

namespace App\Controller\Admin\Dashboard;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Rachat; // adapte selon ton entité

final class RachatStatisticsController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    public function renderRachatStatistics(Request $request): Response
    {
        $repo = $this->em->getRepository(Rachat::class);

        $total = 0;
$all = $repo->createQueryBuilder('r')
    ->select('r.prix_achat')
    ->where('r.prix_achat IS NOT NULL')
    ->getQuery()
    ->getScalarResult();

foreach ($all as $row) {
    $total += (float) str_replace(',', '.', (string) $row['prix_achat']);
}
$count = (int) ($repo->createQueryBuilder('r')
    ->select('COUNT(r.id)')
    ->getQuery()
    ->getSingleScalarResult() ?? 0);

$avg = $count ? $total / $count : 0;

        dump($total, $count, $avg);

        return $this->render('@SyliusAdmin/Dashboard/_rachat_statistics.html.twig', [
            'total_rachats' => number_format($total, 2, ',', ' '),
            'nombre_rachats' => $count,
            'prix_moyen' => number_format($avg, 2, ',', ' '),
        ]);
    }
}
