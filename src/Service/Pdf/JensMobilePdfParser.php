<?php

namespace App\Service\Pdf;

use Smalot\PdfParser\Parser;

class JensMobilePdfParser
{
    public function parse(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(
            static fn ($l) => trim((string) $l),
            $lines
        ), static fn ($l) => $l !== ''));

        $rows = [];
        $matchedLines = [];
        $unmatchedCandidates = [];

        $current = null;
        $lineNumber = 0;
        $documentCode = null;
        $documentDate = null;

        foreach ($lines as $index => $rawLine) {
            $line = $this->normalize($rawLine);

            if ($documentCode === null && preg_match('/^Commande\s*:\s*([A-Z0-9-]+)/iu', $line, $m)) {
                $documentCode = trim((string) $m[1]);
            }

            if ($documentDate === null && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) {
                $documentDate = \DateTimeImmutable::createFromFormat('d/m/Y', $line) ?: null;
            }

            if ($this->isIgnorableLine($line)) {
                continue;
            }

            // Si on a déjà un article en cours, on essaie de fermer avec :
            // "QTE PU_HT MONTANT_HT"
            // ex: "2 2,65 € 5,30 €"
            if ($current && preg_match('/^(\d+)\s+([\d.,]+)\s*€\s+([\d.,]+)\s*€$/u', $line, $m)) {
                $lineNumber++;

                $current['line_number'] = $lineNumber;
                $current['qty'] = (int) $m[1];
                $current['unit_price'] = $this->toFloat($m[2]);
                $current['total_ht'] = $this->toFloat($m[3]);

                $rows[] = $current;

                $matchedLines[] = [
                    'type' => 'tail',
                    'index' => $index,
                    'line' => $line,
                    'sku' => $current['sku'],
                    'label' => $current['label'],
                ];

                $current = null;
                continue;
            }

            // Début d’un nouvel article :
            // "AR0027677 Coque ...."
            // ou "AR0019642 Ecran lcd Samsung ..."
            if (preg_match('/^([A-Z]{2}\d{5,}|AR\d{5,})\s+(.+)$/u', $line, $m)) {
                // sécurité : si un article précédent était incomplet, on le flush quand même
                if ($current) {
                    $lineNumber++;
                    $current['line_number'] = $lineNumber;
                    $rows[] = $current;
                }

                $current = [
                    'line_number' => null,
                    'sku' => trim((string) $m[1]),
                    'label' => trim((string) $m[2]),
                    'qty' => 1,
                    'unit_price' => 0.0,
                    'total_ht' => 0.0,
                    'vat' => '20%',
                    'ean' => null,
                    'imei' => null,
                    'source_lines' => [$rawLine],
                ];

                $matchedLines[] = [
                    'type' => 'head',
                    'index' => $index,
                    'line' => $line,
                    'sku' => $current['sku'],
                ];

                continue;
            }

            // Ligne de remise : on l'ignore
            if ($current && preg_match('/^Remise\s*:/iu', $line)) {
                $current['source_lines'][] = $rawLine;
                continue;
            }

            // Suite de désignation multi-ligne
            if ($current) {
                $current['label'] .= ' ' . $line;
                $current['source_lines'][] = $rawLine;
                continue;
            }

            // Debug
            if (
                str_contains($line, '€') ||
                preg_match('/^[A-Z]{2}\d{5,}/u', $line) ||
                preg_match('/^AR\d{5,}/u', $line)
            ) {
                $unmatchedCandidates[] = [
                    'index' => $index,
                    'raw' => $rawLine,
                    'normalized' => $line,
                ];
            }
        }

        // Flush final
        if ($current) {
            $lineNumber++;
            $current['line_number'] = $lineNumber;
            $rows[] = $current;
        }

        return [
            'source_type' => 'purchase_order',
            'document_code' => $documentCode,
            'document_date' => $documentDate?->format('Y-m-d'),
            'rows' => $rows,
            'matched_lines' => $matchedLines,
            'unmatched_candidates' => $unmatchedCandidates,
            'raw_lines' => $lines,
        ];
    }

    private function normalize(string $line): string
    {
        $line = str_replace("\t", ' ', $line);
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line);
    }

    private function toFloat(string $value): float
    {
        $value = str_replace(' ', '', trim($value));
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function isIgnorableLine(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        if ($line === 'Réf Désignation Qté P.U. HT Montant HT') {
            return true;
        }

        if (preg_match('/^\d+\s+sur\s+\d+$/iu', $line)) {
            return true;
        }

        if (str_starts_with($line, 'JMA')) {
            return true;
        }

        if (str_starts_with($line, 'Jen\'s mobiles accessories')) {
            return true;
        }

        if (str_starts_with($line, '14 rue Jules Vanzuppe')) {
            return true;
        }

        if (str_starts_with($line, 'Tél :')) {
            return true;
        }

        if (str_starts_with($line, 'E-mail :')) {
            return true;
        }

        if (str_starts_with($line, 'Site :')) {
            return true;
        }

        if (str_starts_with($line, 'Client')) {
            return true;
        }

        if (str_starts_with($line, 'Page')) {
            return true;
        }

        if (str_starts_with($line, 'Adresse de facturation')) {
            return true;
        }

        if (str_starts_with($line, 'Adresse de livraison')) {
            return true;
        }

        if (str_starts_with($line, 'Mode de règlement')) {
            return true;
        }

        if (str_starts_with($line, 'Mode de livraison')) {
            return true;
        }

        if (str_starts_with($line, 'Date de livraison')) {
            return true;
        }

        if (str_starts_with($line, 'Frais de port')) {
            return true;
        }

        if (str_starts_with($line, 'Total commande')) {
            return true;
        }

        if (str_starts_with($line, 'Total € HT')) {
            return true;
        }

        if (str_starts_with($line, 'Taux:')) {
            return true;
        }

        if (str_starts_with($line, 'Port HT')) {
            return true;
        }

        if (str_starts_with($line, 'TVA / Frais de port')) {
            return true;
        }

        if ($line === 'Total €') {
            return true;
        }

        if (str_starts_with($line, 'Eco-part incluse')) {
            return true;
        }

        if (str_starts_with($line, 'Acompte versé')) {
            return true;
        }

        if (str_starts_with($line, 'Solde dû')) {
            return true;
        }

        if (preg_match('/^\d+[.,]\d{2}\s*€$/u', $line)) {
            return true;
        }

        if (preg_match('/^Siret\s*:/iu', $line)) {
            return true;
        }

        return false;
    }
}