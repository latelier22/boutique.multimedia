<?php

namespace App\Controller\Admin\Till;

use App\Service\HiboutikTillClient;
use App\Service\HiboutikClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/till', name: 'admin_till_')]
final class TillController extends AbstractController
{
    public function __construct(
        private HiboutikTillClient $hib,
        private HiboutikClient $baseHib
    ) {}

   #[Route('', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
    $currentMonth = (int) date('m');
    $currentYear  = (int) date('Y');

    $month = (int) $request->query->get('month', $currentMonth);
    $year  = (int) $request->query->get('year', $currentYear);

    $storeMeta = $this->baseHib->getDefaultStoreMeta();
    $storeId   = $storeMeta['store_id'] ?? 1;

    // 1) On récupère TOUT ce que dit Hiboutik
    $res  = $this->hib->listTillMovements($storeId, $year, $month);
    $all  = $res['data'] ?? [];

    // 2) On filtre localement sur "RACHAT" dans comments
    $movements = array_values(array_filter($all, function (array $row): bool {
        $comments = strtoupper((string) ($row['comments'] ?? ''));
        return str_contains($comments, 'RACHAT');
    }));

    // 3) Tri du plus récent au plus ancien par ID décroissant
usort($movements, static function (array $a, array $b): int {
    $idA = (int) ($a['id'] ?? $a['till_id'] ?? $a['movement_id'] ?? 0);
    $idB = (int) ($b['id'] ?? $b['till_id'] ?? $b['movement_id'] ?? 0);

    return $idB <=> $idA;
});

    return $this->render('@SyliusAdmin/Till/index.html.twig', [
        'storeId'      => $storeId,
        'movements'    => $movements,
        'year'         => $year,
        'month'        => $month,
        'currentMonth' => $currentMonth,
        'currentYear'  => $currentYear,
    ]);
}

}
