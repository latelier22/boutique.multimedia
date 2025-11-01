<?php

namespace App\Command;

use App\Entity\Rachat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rachat:import',
    description: 'Import CSV -> rachat (mapping 1:1 des colonnes, sans transformation)'
)]
class RachatImportCsvCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin vers le CSV')
            ->addOption('delimiter', 'd', InputOption::VALUE_REQUIRED, 'Délimiteur', ',')
            ->addOption('enclosure', null, InputOption::VALUE_REQUIRED, 'Enclosure', '"')
            ->addOption('escape', null, InputOption::VALUE_REQUIRED, 'Escape', '\\')
            ->addOption('encoding', null, InputOption::VALUE_REQUIRED, 'Encodage (UTF-8, CP1252...)', 'UTF-8')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation (aucun write)')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Flush par lot', '500')
            ->addOption('skip-header', null, InputOption::VALUE_NONE, 'Ignorer la première ligne')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $file = (string)$input->getArgument('file');
        $del  = (string)$input->getOption('delimiter');
        $enc  = strtoupper((string)$input->getOption('encoding'));
        $encl = (string)$input->getOption('enclosure');
        $esc  = (string)$input->getOption('escape');
        $dry  = (bool)$input->getOption('dry-run');
        $bs   = (int)$input->getOption('batch-size');
        $skip = (bool)$input->getOption('skip-header');

        if (!is_file($file)) {
            $io->error("Fichier introuvable: $file");
            return Command::FAILURE;
        }

        $fh = fopen($file, 'rb');
        if (!$fh) {
            $io->error("Lecture impossible");
            return Command::FAILURE;
        }

        $io->title('Import CSV -> rachat (1:1)');
        $header = null;
        if (!$skip) {
            $header = fgetcsv($fh, 0, $del, $encl, $esc);
            if ($header === false) {
                $io->error('Entête illisible');
                return Command::FAILURE;
            }
            $header = $this->normalizeHeader($header, $enc); // on normalise juste les NOMS de colonnes en snake_case
        }

        $count = $created = $updated = $errors = $batch = 0;

        while (($row = fgetcsv($fh, 0, $del, $encl, $esc)) !== false) {
            $data = $this->rowToAssoc($row, $header, $enc);

            try {
                // ID CSV = clé primaire
                if (!isset($data['id']) || $data['id'] === '') throw new \RuntimeException('Colonne id vide');
                $id = (int)trim($data['id']);

                $repo = $this->em->getRepository(Rachat::class);
                $e = $repo->find($id) ?: new Rachat();
                $e->setId($id);

                // Mapping strict 1:1 (CSV -> entité) — en collant à tes setters Rachat
                $e->setImei($data['imei_serie'] ?? null);
                $e->setNumeroCi($data['numero_ci'] ?? $data['numéro_ci'] ?? null);
                $e->setPrixAchat($data['prix_achat'] ?? null);
                $e->setNom($data['nom'] ?? null);
                $e->setPrenom($data['prenom'] ?? $data['prénom'] ?? null);
                $e->setTelephone($data['telephone'] ?? $data['téléphone'] ?? null);
                $e->setMarqueModele($data['marque_modele'] ?? $data['marque_modèle'] ?? null);
                $e->setPieceIdentiteUrl($data['piece_identite_url'] ?? $data['pièce_identite_url'] ?? $data['pièce_identité_url'] ?? null);
                $e->setDateCession($data['date_de_cession'] ?? null);
                $e->setAdresse($data['adresse'] ?? null);
                $e->setCodePostal($data['code_postal'] ?? null);
                $e->setEmail($data['email'] ?? null);
                $e->setSignatureUrl($data['signature_url'] ?? null);
                $e->setPdfUrl($data['pdf_url'] ?? null);
                $e->setPhotosJson($data['photos_produit_urls_json'] ?? null);
                $e->setPhoto1($data['photo_produit_1'] ?? null);
                $e->setPhoto2($data['photo_produit_2'] ?? null);
                $e->setPhoto3($data['photo_produit_3'] ?? null);
                $e->setColonne1($data['colonne_1'] ?? null);
                $e->setColonne2($data['colonne_2'] ?? null);
                $e->setColonne3($data['colonne_3'] ?? null);
                $e->setColonne4($data['colonne_4'] ?? null);


                if (!$repo->find($id)) {
                    $this->em->persist($e);
                    $created++;
                } else {
                    $updated++;
                }

                if (!$dry) {
                    if ((++$batch % $bs) === 0) {
                        $this->em->flush();
                        $this->em->clear();
                        $batch = 0;
                    }
                }
            } catch (\Throwable $ex) {
                $errors++;
                if ($io->isVerbose()) $io->warning("Ligne " . ($count + 1) . ": " . $ex->getMessage());
            }

            $count++;
        }
        fclose($fh);

        if (!$dry) $this->em->flush();

        $io->success("Lues: $count | Créés: $created | MAJ: $updated | Erreurs: $errors");
        return Command::SUCCESS;
    }

    // ——— Helpers ———
    private function normalizeHeader(array $header, string $encoding): array
    {
        $out = [];
        foreach ($header as $h) {
            $h = (string)$h;
            if ($encoding !== 'UTF-8') $h = iconv($encoding, 'UTF-8//IGNORE', $h);
            $h = trim($h, " \t\n\r\0\x0B\"'");
            $h = mb_strtolower($h, 'UTF-8');
            // on convertit TES en-têtes -> snake_case exact qu'on a mis dans l'entité
            // Exemple: "IMEI/SERIE" -> "imei_serie", "PDF (URL)" -> "pdf_url"
            $h = preg_replace('/[^a-z0-9]+/u', '_', $h);
            $h = trim($h, '_');
            $out[] = $h;
        }
        return $out;
    }

    private function rowToAssoc(array $row, ?array $header, string $encoding): array
    {
        $vals = [];
        foreach ($row as $v) {
            $v = (string)$v;
            if ($encoding !== 'UTF-8') $v = iconv($encoding, 'UTF-8//IGNORE', $v);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $vals[] = $v;
        }
        if ($header) {
            $assoc = [];
            foreach ($header as $i => $name) $assoc[$name] = $vals[$i] ?? null;
            return $assoc;
        }
        return $vals;
    }
}
