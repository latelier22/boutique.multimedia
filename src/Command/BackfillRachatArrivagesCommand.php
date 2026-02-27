<?php

namespace App\Command;

use App\Service\HiboutikClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(
    name: 'app:arrivage:backfill-rachat',
    description: 'Rattache les rachats à leurs arrivages Hiboutik (hib_inventory_input_id) en scannant inventory_input_details.'
)]
class BackfillRachatArrivagesCommand extends Command
{
    public function __construct(
        private HiboutikClient $hib,
        private Connection $db,
    ) {
        parent::__construct();
    }

    protected static $defaultName = 'app:backfill-rachat-arrivages';
    protected static $defaultDescription = 'Backfill: rattache les rachats à leurs arrivages Hiboutik.';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[1] Scan Hiboutik arrivages (prefix RACHAT)...</info>');

        // ⚠️ augmente si tu as beaucoup d’arrivages
        $inputs = $this->hib->listRachatInputs(50);

        $output->writeln(sprintf('<info>Arrivages trouvés: %d</info>', count($inputs)));

        // Map : product_id => [inventory_input_id, label, date]
        $map = [];

        $i = 0;
        foreach ($inputs as $row) {
            $i++;
            $invId = (int)($row['inventory_input_id'] ?? 0);
            if ($invId <= 0) continue;

            $label = (string)($row['inventory_input_label'] ?? '');
            $date  = (string)($row['inventory_input_date'] ?? '');

            $details = $this->hib->listInventoryInputDetails($invId);
            if (!($details['ok'] ?? false)) {
                $output->writeln("<comment> - warn: details KO for inv#$invId</comment>");
                continue;
            }

            foreach (($details['data'] ?? []) as $d) {
                $pid = (int)($d['product_id'] ?? 0);
                if ($pid <= 0) continue;

                // si un même produit apparaît dans plusieurs arrivages, on garde le plus récent (heuristique)
                // on compare sur inventory_input_id (souvent croissant) si pas de date fiable.
                if (!isset($map[$pid]) || $invId > $map[$pid]['inventory_input_id']) {
                    $map[$pid] = [
                        'inventory_input_id' => $invId,
                        'label' => $label,
                        'date'  => $date,
                    ];
                }
            }

            if ($i % 10 === 0) {
                $output->writeln("... $i / ".count($inputs));
            }
        }

        $output->writeln(sprintf('<info>[2] Mapping Hiboutik products -> arrivages: %d produits</info>', count($map)));

        $output->writeln('<info>[3] Update SQL rachats...</info>');

        // on ne touche que les rachats qui ont un hib_product_id et pas encore hib_inventory_input_id
        $stmtSel = $this->db->prepare("
            SELECT id, hib_product_id
            FROM rachats
            WHERE hib_product_id IS NOT NULL
              AND hib_product_id > 0
              AND (hib_inventory_input_id IS NULL OR hib_inventory_input_id = 0)
        ");

        $toUpdate = $stmtSel->executeQuery()->fetchAllAssociative();
        $output->writeln(sprintf('<info>Rachats à compléter: %d</info>', count($toUpdate)));

        $stmtUpd = $this->db->prepare("
            UPDATE rachats
            SET hib_inventory_input_id = :inv,
                hib_arrivage_added_at = :dt
            WHERE id = :id
        ");

        $updated = 0;
        foreach ($toUpdate as $r) {
            $rid = (int)$r['id'];
            $pid = (int)$r['hib_product_id'];

            if (!isset($map[$pid])) continue;

            $invId = (int)$map[$pid]['inventory_input_id'];
            $dt = $map[$pid]['date'] ?: null;

            // Hiboutik renvoie souvent YYYY-MM-DD : on met en datetime
            $dtSql = $dt ? ($dt.' 00:00:00') : (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $stmtUpd->executeStatement([
                'inv' => $invId,
                'dt'  => $dtSql,
                'id'  => $rid,
            ]);

            $updated++;
        }

        $output->writeln(sprintf('<info>✅ Rachats mis à jour: %d</info>', $updated));
        $output->writeln('<info>Terminé.</info>');

        return Command::SUCCESS;
    }
}