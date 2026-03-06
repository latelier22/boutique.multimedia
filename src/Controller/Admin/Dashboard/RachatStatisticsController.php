<?php

namespace App\Controller\Admin\Dashboard;

use App\Entity\Rachat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, StreamedResponse};
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/dashboard/rachats', name: 'admin_dashboard_rachats_')]
final class RachatStatisticsController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        // ✅ Par défaut : 15 derniers jours
        $data = $this->getStats('week');

        return $this->render('@SyliusAdmin/Dashboard/_rachat_statistics.html.twig', [
            'statistics'       => $data['summary'],
            'sales_summary'    => $data['series'],
            'defaultInterval'  => 'week',
        ]);
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $interval = $request->query->get('interval', 'week');
        $data = $this->getStats($interval);
        return $this->json($data);
    }

    /**
     * EXPORT COMPTABLE (CSV) – SANS AUCUNE DONNÉE PERSONNELLE VENDEUR
     *
     * GET /admin/dashboard/rachats/export?period=month|year&year=2025&month=11
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $period = $request->query->get('period', 'month'); // month | year

        $now   = new \DateTimeImmutable('now');
        $year  = (int) $request->query->get('year', $now->format('Y'));
        $month = (int) $request->query->get('month', $now->format('m'));

        if ($period === 'year') {
            $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
            $end   = $start->modify('+1 year');
            $filename = sprintf('rachats_%d.csv', $year);
        } else {
            // Mensuel par défaut
            $start = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $month));
            $end   = $start->modify('+1 month');
            $filename = sprintf('rachats_%d-%02d.csv', $year, $month);
        }

        $repo = $this->em->getRepository(Rachat::class);

        // ⚠️ PAS de COALESCE ici, Doctrine râle dans WHERE
        // On fait à la main :
        // - soit date_cession dans l’intervalle
        // - soit pas de date_cession et created_at dans l’intervalle
        $qb = $repo->createQueryBuilder('r')
            ->where('r.dateCession IS NOT NULL AND r.dateCession >= :start AND r.dateCession < :end')
            ->orWhere('r.dateCession IS NULL AND r.createdAt >= :start AND r.createdAt < :end')
            ->setParameters([
                'start' => $start,
                'end'   => $end,
            ])
            ->orderBy('r.dateCession', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC');

        /** @var Rachat[] $rachats */
        $rachats = $qb->getQuery()->getResult();

        // ⚠️ AUCUNE info perso du vendeur : uniquement colonnes comptables.
        $response = new StreamedResponse(function () use ($rachats) {
            $handle = fopen('php://output', 'w+');

            // BOM UTF-8 pour Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // En-têtes du fichier – tu pourras en rajouter
            fputcsv($handle, [
                'ID rachat',
                'Date rachat',
                'Montant achat',  // prix_achat
            ], ';');

            foreach ($rachats as $rachat) {
                /** @var Rachat $rachat */

                // Date = date_cession si présente, sinon created_at
                $dateRachat = null;
                if (method_exists($rachat, 'getDateCession') && $rachat->getDateCession() instanceof \DateTimeInterface) {
                    $dateRachat = $rachat->getDateCession();
                } elseif (method_exists($rachat, 'getCreatedAt') && $rachat->getCreatedAt() instanceof \DateTimeInterface) {
                    $dateRachat = $rachat->getCreatedAt();
                }

                // Montant = prix_achat (champ déjà utilisé dans tes stats)
                $prixAchat = '';
                if (method_exists($rachat, 'getPrixAchat')) {
                    $prixAchat = $rachat->getPrixAchat();
                }

                fputcsv($handle, [
                    $rachat->getId(),
                    $dateRachat ? $dateRachat->format('Y-m-d') : '',
                    $prixAchat,
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="'.$filename.'"'
        );

        return $response;
    }

    // === Convertit proprement une valeur en DateTimeImmutable ===
    private function toDate($value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($value);
        if (is_string($value) && $value !== '') return new \DateTimeImmutable($value);
        return null;
    }

    // === Fonction centrale ===
    private function getStats(string $interval = 'week'): array
    {
        $repo = $this->em->getRepository(Rachat::class);
        $now  = new \DateTimeImmutable('now');
        $series = [];
        $labels = [];

        // === 15 DERNIERS JOURS ===
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

            error_log('==== [RACHATS WEEK] ====');
            error_log('start=' . $start->format('Y-m-d H:i:s') . ' end=' . $end->format('Y-m-d H:i:s'));
            error_log('rows=' . count($results));

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) continue;
                $key = $date->format('d/m');
                if (isset($series[$key])) {
                    $series[$key] += (float) str_replace(',', '.', (string) $row['prix_achat']);
                }
            }

            $values = array_values($series);
        }

        // === MOIS COURANT ===
        elseif ($interval === 'month') {
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

            error_log('==== [RACHATS MONTH] ====');
            error_log('start=' . $start->format('Y-m-d H:i:s') . ' end=' . $end->format('Y-m-d H:i:s'));

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

            error_log('rows=' . count($results));

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) continue;
                $day = $date->format('d');
                if (isset($series[$day])) {
                    $series[$day] += (float) str_replace(',', '.', (string) $row['prix_achat']);
                }
            }

            $values = array_values($series);
        }

        // === ANNÉE COURANTE ===
        elseif ($interval === 'year') {
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

            error_log('==== [RACHATS YEAR] rows=' . count($results));

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) continue;
                $monthIdx = ((int) $date->format('n')) - 1;
                $label = $labels[$monthIdx] ?? null;
                if ($label) {
                    $series[$label] += (float) str_replace(',', '.', (string) $row['prix_achat']);
                }
            }

            $values = array_values($series);
        }

        // === 12 DERNIERS MOIS ===
        else {
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

            error_log('==== [RACHATS LAST12] rows=' . count($results));

            foreach ($results as $row) {
                $date = $this->toDate($row['date']);
                if (!$date) continue;
                $key = $date->format('Y-m');
                if (isset($series[$key])) {
                    $series[$key] += (float) str_replace(',', '.', (string) $row['prix_achat']);
                }
            }

            $values = array_values($series);
        }

        // === RÉSUMÉ GLOBAL ===
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
}
