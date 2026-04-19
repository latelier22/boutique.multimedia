<?php

namespace App\Service\Pdf;

use Smalot\PdfParser\Parser;

class MobileSentrixPdfParser
{
    public function parse(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(
            fn ($l) => $this->normalize((string) $l),
            $lines
        ), static fn ($l) => $l !== ''));

        $rows = [];
        $matchedLines = [];
        $unmatchedCandidates = [];

        $currentLabel = [];
        $lineNumber = 0;
        $documentCode = null;
        $documentDate = null;
        $captureOrderNumberNext = false;
        $captureDateNext = false;
        $insideItems = false;

        foreach ($lines as $index => $line) {
            if ($line === 'Order Number') {
                $captureOrderNumberNext = true;
                continue;
            }

            if ($captureOrderNumberNext && preg_match('/^\d{6,}$/', $line)) {
                $documentCode = $line;
                $captureOrderNumberNext = false;
                continue;
            }

            if ($line === 'Date de commande') {
                $captureDateNext = true;
                continue;
            }

            if ($captureDateNext && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) {
                $documentDate = \DateTimeImmutable::createFromFormat('d/m/Y', $line)?->format('Y-m-d');
                $captureDateNext = false;
                continue;
            }

            if (str_starts_with($line, 'Description du produit SKU Prix unitaire')) {
                $insideItems = true;
                $currentLabel = [];
                continue;
            }

            if (!$insideItems) {
                continue;
            }

            if ($this->isIgnorableLine($line)) {
                continue;
            }

            // Fin des articles
            if (
                str_starts_with($line, 'Sous-total:')
                || str_starts_with($line, 'livraison ')
                || str_starts_with($line, 'VAT (')
                || str_starts_with($line, 'Total général:')
                || str_starts_with($line, 'Payé ')
                || str_starts_with($line, 'Total dû:')
                || str_starts_with($line, 'Informations supplémentaires')
                || str_starts_with($line, 'Account Manager')
            ) {
                $insideItems = false;
                continue;
            }

            // Ligne de fin d'article :
            // 107081017201 PLN 103,19 1 PLN 103,19
            if (preg_match('/^(\d{9,15})\s+PLN\s+([\d,]+)\s+(\d+)\s+PLN\s+([\d,]+)$/u', $line, $m)) {
                $label = trim(implode(' ', $currentLabel));

                if ($label !== '') {
                    $lineNumber++;

                    $rows[] = [
                        'line_number' => $lineNumber,
                        'sku' => $m[1],
                        'label' => $label,
                        'qty' => (int) $m[3],
                        'unit_price' => $this->toFloat($m[2]),
                        'total_ht' => $this->toFloat($m[4]),
                        'vat' => '0%',
                        'ean' => null,
                        'imei' => null,
                        'source_lines' => [...$currentLabel, $line],
                    ];

                    $matchedLines[] = [
                        'type' => 'mobilesentrix_item',
                        'index' => $index,
                        'line' => $line,
                        'sku' => $m[1],
                        'label' => $label,
                    ];
                } else {
                    $unmatchedCandidates[] = [
                        'index' => $index,
                        'raw' => $line,
                        'normalized' => $line,
                    ];
                }

                $currentLabel = [];
                continue;
            }

            $currentLabel[] = $line;
        }

        return [
            'source_type' => 'purchase_order',
            'document_code' => $documentCode,
            'document_date' => $documentDate,
            'rows' => $rows,
            'matched_lines' => $matchedLines,
            'unmatched_candidates' => $unmatchedCandidates,
            'raw_lines' => $lines,
        ];
    }

    private function normalize(string $line): string
    {
        $line = str_replace(["\t", "￾"], ' ', $line);
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        return trim($line);
    }

    private function toFloat(string $value): float
    {
        $value = str_replace(',', '.', trim($value));
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function isIgnorableLine(string $line): bool
    {
        if ($line === '' || $line === 'Quantité' || $line === 'Commandé' || $line === 'Sous-total') {
            return true;
        }

        if (preg_match('/^Page \d+ of \d+$/i', $line)) {
            return true;
        }

        if (str_starts_with($line, 'MobileSentrix Europe |')) {
            return true;
        }

        if (str_starts_with($line, 'De Keten 4,')) {
            return true;
        }

        if (str_starts_with($line, 'VAT exempt')) {
            return true;
        }

        if ($line === 'Select' || preg_match('/^\d{5}$/', $line)) {
            return true;
        }

        if (str_starts_with($line, 'Numéro de suivi:')) {
            return true;
        }

        if ($line === 'Total 13') {
            return true;
        }

        if (str_starts_with($line, 'E: ') || str_starts_with($line, 'T: ')) {
            return true;
        }

        return false;
    }
}