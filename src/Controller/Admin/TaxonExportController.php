<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TaxonExportController
{
    #[Route('/admin/export/taxons.csv', name: 'admin_export_taxons_csv')]
    public function exportTaxons(Connection $connection): Response
    {
        $sql = <<<SQL
            SELECT
              t.code         AS code,
              p.code         AS parent,
              tt.locale      AS locale,
              tt.name        AS name,
              tt.slug        AS slug,
              tt.description AS description
            FROM sylius_taxon t
            LEFT JOIN sylius_taxon p ON t.parent_id = p.id
            JOIN sylius_taxon_translation tt ON t.id = tt.translatable_id
            ORDER BY t.code, tt.locale
        SQL;

        $rows = $connection->fetchAllAssociative($sql);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Code', 'Parent', 'Locale', 'Name', 'Slug', 'Description']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['code'],
                $row['parent'],
                $row['locale'],
                $row['name'],
                $row['slug'],
                $row['description'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response(
            $csv,
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="taxons.csv"',
            ]
        );
    }
}
