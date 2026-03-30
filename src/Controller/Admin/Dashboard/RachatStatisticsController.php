<?php

namespace App\Controller\Admin\Dashboard;

use App\Entity\Rachat\Rachat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, StreamedResponse};
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/dashboard/rachats', name: 'admin_dashboard_rachats_')]
final class RachatStatisticsController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

  #[Route('/block', name: 'block', methods: ['GET'])]
public function block(Request $request): Response
{
    return $this->render('@SyliusAdmin/Dashboard/_rachat_statistics_block.html.twig', $this->buildBlockViewData($request));
}


    #[Route('', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
    return $this->render('@SyliusAdmin/Dashboard/rachat_statistics.html.twig', $this->buildBlockViewData($request));
}

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $interval = $request->query->get('interval', 'week');
        $data = $this->getStats($interval);

        return $this->json($data);
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $now = new \DateTimeImmutable('now');
        $defaultExportDate = $now->modify('first day of last month');

        $period = $request->query->get('period', 'month');
        $year   = (int) $request->query->get('year', $defaultExportDate->format('Y'));
        $month  = (int) $request->query->get('month', $defaultExportDate->format('m'));

        $exportData = $this->getExportData($period, $year, $month);

        return $this->render('@SyliusAdmin/Dashboard/_rachat_export_preview.html.twig', [
            'rows'          => $exportData['rows'],
            'previewCount'  => $exportData['count'],
            'previewTotal'  => $exportData['total_formatted'],
            'filename'      => $exportData['filename'],
            'start'         => $exportData['start'],
            'end'           => $exportData['end'],
            'period'        => $exportData['period'],
            'year'          => $exportData['year'],
            'month'         => $exportData['month'],
        ]);
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $now = new \DateTimeImmutable('now');
        $defaultExportDate = $now->modify('first day of last month');

        $period = $request->query->get('period', 'month');
        $year   = (int) $request->query->get('year', $defaultExportDate->format('Y'));
        $month  = (int) $request->query->get('month', $defaultExportDate->format('m'));

        $exportData = $this->getExportData($period, $year, $month);
        $rows = $exportData['rows'];
        $filename = $exportData['filename'];

        $response = new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w+');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'ID rachat',
                'Date rachat',
                'Montant achat',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['id'],
                    $row['date'],
                    $row['amount_csv'],
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    private function getExportData(string $period, int $year, int $month): array
    {
        [$period, $year, $month, $start, $end, $filename] = $this->buildExportRange($period, $year, $month);

        $rachats = $this->findRachatsForExport($start, $end);

        $rows = [];
        $total = 0.0;

        foreach ($rachats as $rachat) {
            $dateRachat = $this->resolveRachatDate($rachat);
            $amount = $this->normalizeAmount(
                method_exists($rachat, 'getPrixAchat') ? $rachat->getPrixAchat() : null
            );

            $rows[] = [
                'id' => $rachat->getId(),
                'date' => $dateRachat ? $dateRachat->format('Y-m-d') : '',
                'amount' => number_format($amount, 2, ',', ' '),
                'amount_csv' => number_format($amount, 2, '.', ''),
            ];

            $total += $amount;
        }

        return [
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'filename' => $filename,
            'rows' => $rows,
            'count' => count($rows),
            'total' => $total,
            'total_formatted' => number_format($total, 2, ',', ' '),
        ];
    }

    private function buildExportRange(string $period, int $year, int $month): array
    {
        $period = $period === 'year' ? 'year' : 'month';
        $month = max(1, min(12, $month));

        if ($period === 'year') {
            $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
            $end   = $start->modify('+1 year');
            $filename = sprintf('rachats_%d.csv', $year);
        } else {
            $start = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
            $end   = $start->modify('+1 month');
            $filename = sprintf('rachats_%d-%02d.csv', $year, $month);
        }

        return [$period, $year, $month, $start, $end, $filename];
    }

    /**
     * @return Rachat[]
     */
    private function findRachatsForExport(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $repo = $this->em->getRepository(Rachat::class);

        return $repo->createQueryBuilder('r')
            ->where('r.dateCession IS NOT NULL AND r.dateCession >= :start AND r.dateCession < :end')
            ->orWhere('r.dateCession IS NULL AND r.createdAt >= :start AND r.createdAt < :end')
            ->setParameters([
                'start' => $start,
                'end'   => $end,
            ])
            ->orderBy('r.dateCession', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function resolveRachatDate(Rachat $rachat): ?\DateTimeImmutable
    {
        if (method_exists($rachat, 'getDateCession') && $rachat->getDateCession() instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($rachat->getDateCession());
        }

        if (method_exists($rachat, 'getCreatedAt') && $rachat->getCreatedAt() instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($rachat->getCreatedAt());
        }

        return null;
    }

    private function normalizeAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = str_replace("\xc2\xa0", ' ', (string) $value);
        $normalized = str_replace(' ', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function toDate($value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new \DateTimeImmutable($value);
        }

        return null;
    }

    private function getStats(string $interval = 'week'): array
    {
        $repo = $this->em->getRepository(Rachat::class);
        $now  = new \DateTimeImmutable('now');
        $series = [];
        $labels = [];

        if ($interval === 'week') {
            $start = (clone $now)->modify('-14 days')->setTime(0, 0, 0);
            $end   = (clone $now)->setTime(23, 59, 59);
            $cursor = clone $start;

            for ($i = 0; $i < 15; $i++) {
                $label = $cursor->format('d/m');
                $labels[] = $label;
                $series[$label] = 0;
                $cursor = $cursor->modify('+1 day');
            }

            $results = $repo->createQueryBuilder('r')
                ->select('COALESCE(r.dateCession, r.createdAt) AS date, r.prixAchat')
                ->where('COALESCE(r.dateCession, r.createdAt) BETWEEN :start AND :end')
                ->setParameters(['start' => $start, 'end' => $end])
                ->orderBy('date', 'ASC')
                ->getQuery()
                ->getArrayResult();

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) {
                    continue;
                }
                $key = $date->format('d/m');
                if (isset($series[$key])) {
                    $series[$key] += (float) str_replace(',', '.', (string) $row['prixAchat']);
                }
            }

            $values = array_values($series);
        } elseif ($interval === 'month') {
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

            $days = (int) $end->format('t');
            for ($i = 1; $i <= $days; $i++) {
                $label = sprintf('%02d', $i);
                $labels[] = $label;
                $series[$label] = 0;
            }

            $results = $repo->createQueryBuilder('r')
                ->select('COALESCE(r.dateCession, r.createdAt) AS date, r.prixAchat')
                ->where('COALESCE(r.dateCession, r.createdAt) BETWEEN :start AND :end')
                ->setParameters(['start' => $start, 'end' => $end])
                ->orderBy('date', 'ASC')
                ->getQuery()
                ->getArrayResult();

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) {
                    continue;
                }
                $day = $date->format('d');
                if (isset($series[$day])) {
                    $series[$day] += (float) str_replace(',', '.', (string) $row['prixAchat']);
                }
            }

            $values = array_values($series);
        } elseif ($interval === 'year') {
            $year  = (int) $now->format('Y');
            $start = new \DateTimeImmutable("$year-01-01 00:00:00");
            $end   = new \DateTimeImmutable("$year-12-31 23:59:59");

            $labels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
            $series = array_fill_keys($labels, 0);

            $results = $repo->createQueryBuilder('r')
                ->select('COALESCE(r.dateCession, r.createdAt) AS date, r.prixAchat')
                ->where('COALESCE(r.dateCession, r.createdAt) BETWEEN :start AND :end')
                ->setParameters(['start' => $start, 'end' => $end])
                ->orderBy('date', 'ASC')
                ->getQuery()
                ->getArrayResult();

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) {
                    continue;
                }
                $monthIdx = ((int) $date->format('n')) - 1;
                $label = $labels[$monthIdx] ?? null;
                if ($label) {
                    $series[$label] += (float) str_replace(',', '.', (string) $row['prixAchat']);
                }
            }

            $values = array_values($series);
        } else {
            $start  = (clone $now)->modify('-11 months')->modify('first day of this month')->setTime(0, 0, 0);
            $end    = (clone $now)->setTime(23, 59, 59);
            $cursor = clone $start;

            for ($i = 0; $i < 12; $i++) {
                $label = $cursor->format('M Y');
                $labels[] = $label;
                $series[$cursor->format('Y-m')] = 0;
                $cursor = $cursor->modify('+1 month');
            }

            $results = $repo->createQueryBuilder('r')
                ->select('COALESCE(r.dateCession, r.createdAt) AS date, r.prixAchat')
                ->where('COALESCE(r.dateCession, r.createdAt) BETWEEN :start AND :end')
                ->setParameters(['start' => $start, 'end' => $end])
                ->orderBy('date', 'ASC')
                ->getQuery()
                ->getArrayResult();

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) {
                    continue;
                }
                $key = $date->format('Y-m');
                if (isset($series[$key])) {
                    $series[$key] += (float) str_replace(',', '.', (string) $row['prixAchat']);
                }
            }

            $values = array_values($series);
        }

        $sum = array_sum($values);
        $count = array_sum(array_map(fn($v) => $v > 0 ? 1 : 0, $values));
        $avg = $count ? $sum / $count : 0;

        return [
            'summary' => [
                'total_sales'          => number_format($sum, 2, ',', ' '),
                'number_of_new_orders' => $count,
                'average_order_value'  => number_format($avg, 2, ',', ' '),
            ],
            'series' => [
                'intervals' => $labels,
                'sales'     => $values,
            ],
        ];
    }

    private function buildBlockViewData(Request $request): array
{
    $now = new \DateTimeImmutable('now');
    $defaultExportDate = $now->modify('first day of last month');

    $selectedExportPeriod = $request->query->get('period', 'month');
    $selectedExportYear   = (int) $request->query->get('year', $defaultExportDate->format('Y'));
    $selectedExportMonth  = (int) $request->query->get('month', $defaultExportDate->format('m'));
    $defaultInterval      = $request->query->get('interval', 'week');

    $exportData = $this->getExportData(
        $selectedExportPeriod,
        $selectedExportYear,
        $selectedExportMonth
    );

    $data = $this->getStats($defaultInterval);

    return [
        'statistics'            => $data['summary'],
        'sales_summary'         => $data['series'],
        'defaultInterval'       => $defaultInterval,
        'currentYear'           => (int) $now->format('Y'),
        'selectedExportPeriod'  => $selectedExportPeriod,
        'selectedExportYear'    => $selectedExportYear,
        'selectedExportMonth'   => $selectedExportMonth,
        'exportPreviewRows'     => $exportData['rows'],
        'exportPreviewCount'    => $exportData['count'],
        'exportPreviewTotal'    => $exportData['total_formatted'],
        'exportFilename'        => $exportData['filename'],
        'exportStart'           => $exportData['start'],
        'exportEnd'             => $exportData['end'],
    ];
}
}