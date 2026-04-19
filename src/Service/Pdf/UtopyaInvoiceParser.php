<?php

namespace App\Service\Pdf;

use Smalot\PdfParser\Parser;

class UtopyaInvoiceParser
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
        $pending = null;
        $lineNumber = 0;

        foreach ($lines as $index => $rawLine) {
            $line = $this->normalize($rawLine);

            if ($this->isIgnorableLine($line)) {
                continue;
            }

            // EAN
            if ($current && preg_match('/^EAN\s*:\s*([A-Z0-9\-]+)/iu', $line, $m)) {
                $current['ean'] = $m[1];
                continue;
            }

            // IMEI
            if ($current && preg_match('/^IMEI\s*:\s*([0-9]+)/u', $line, $m)) {
                $current['imei'] = $m[1];
                continue;
            }

            // Fin d'un produit multi-lignes
            // format réel : QTE PRIX_UNITAIRE TOTAL TVA
            if ($pending && preg_match('/^(\d+)\s+([\d,]+)€\s+([\d,]+)€\s*([0-9]+%[*]?)$/u', $line, $m)) {
                $lineNumber++;

                $current = [
                    'line_number' => $lineNumber,
                    'sku' => $pending['sku'],
                    'label' => trim($pending['label']),
                    'qty' => (int) $m[1],
                    'unit_price' => $this->toFloat($m[2]),
                    'total_ht' => $this->toFloat($m[3]),
                    'vat' => trim($m[4]),
                    'ean' => null,
                    'imei' => null,
                    'source_lines' => $pending['source_lines'],
                ];

                $matchedLines[] = [
                    'type' => 'multiline',
                    'index' => $index,
                    'line' => $line,
                    'sku' => $current['sku'],
                    'label' => $current['label'],
                ];

                $pending = null;
                continue;
            }

            // Ligne produit complète sur une seule ligne
            // format réel : SKU LIBELLE QTE PRIX_UNITAIRE TOTAL TVA
            if (preg_match('/^([A-Z0-9\-]{4,})\s+(.+?)\s+(\d+)\s+([\d,]+)€\s+([\d,]+)€\s*([0-9]+%[*]?)$/u', $line, $m)) {
                if ($current) {
                    $rows[] = $current;
                }

                $lineNumber++;

                $current = [
                    'line_number' => $lineNumber,
                    'sku' => $m[1],
                    'label' => trim($m[2]),
                    'qty' => (int) $m[3],
                    'unit_price' => $this->toFloat($m[4]),
                    'total_ht' => $this->toFloat($m[5]),
                    'vat' => trim($m[6]),
                    'ean' => null,
                    'imei' => null,
                    'source_lines' => [$rawLine],
                ];

                $matchedLines[] = [
                    'type' => 'single',
                    'index' => $index,
                    'line' => $line,
                    'groups' => $m,
                ];

                continue;
            }

            // Début d’un produit potentiellement sur plusieurs lignes
            if (preg_match('/^([A-Z0-9\-]{4,})\s+(.+)$/u', $line, $m) && !$this->looksLikeTailPriceLine($line)) {
                if ($current) {
                    $rows[] = $current;
                    $current = null;
                }

                $pending = [
                    'sku' => $m[1],
                    'label' => trim($m[2]),
                    'source_lines' => [$rawLine],
                ];

                continue;
            }

            // Suite de libellé multi-lignes
            if ($pending) {
                $pending['label'] .= ' ' . $line;
                $pending['source_lines'][] = $rawLine;
                continue;
            }

            // Pour debug : lignes qui ressemblent à des lignes produit mais non reconnues
            if (str_contains($line, '€') || preg_match('/^[A-Z0-9\-]{4,}\s+/u', $line)) {
                $unmatchedCandidates[] = [
                    'index' => $index,
                    'raw' => $rawLine,
                    'normalized' => $line,
                ];
            }
        }

        if ($current) {
            $rows[] = $current;
        }

        return [
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
        return (float) str_replace(',', '.', trim($value));
    }

    private function looksLikeTailPriceLine(string $line): bool
    {
        return (bool) preg_match('/^\d+\s+[\d,]+€\s+[\d,]+€\s*[0-9]+%[*]?$/' , $line);
    }

    private function isIgnorableLine(string $line): bool
    {
        if ($line === 'UTOPYA' || $line === 'France') {
            return true;
        }

        if (str_starts_with($line, 'TVA :')) {
            return true;
        }

        if (str_starts_with($line, 'EORI :')) {
            return true;
        }

        if (str_starts_with($line, 'Adresse de facturation')) {
            return true;
        }

        if (str_starts_with($line, 'Adresse de livraison')) {
            return true;
        }

        if (str_starts_with($line, 'Mode de paiement')) {
            return true;
        }

        if (str_starts_with($line, 'Paiement bancaire')) {
            return true;
        }

        if (str_starts_with($line, 'SKU Produits')) {
            return true;
        }

        if (str_starts_with($line, 'Facture #')) {
            return true;
        }

        if (str_starts_with($line, 'Produits commandés')) {
            return true;
        }

        if (str_starts_with($line, 'Total HT:')) {
            return true;
        }

        if (str_starts_with($line, 'TVA:')) {
            return true;
        }

        if (str_starts_with($line, 'Total TTC:')) {
            return true;
        }

        if (str_starts_with($line, 'TOTAL HT')) {
            return true;
        }

        if (str_starts_with($line, 'Escompte pour règlement anticipé')) {
            return true;
        }

        if (str_starts_with($line, 'UTOPYA - SAS')) {
            return true;
        }

        if (str_starts_with($line, 'T:')) {
            return true;
        }

        if (preg_match('/^\d{5}\s+/u', $line)) {
            return true;
        }

        if ($line === 'Ivan Gourdel' || $line === 'MULTIMEDIASERVICES') {
            return true;
        }

        if (str_starts_with($line, 'Shipping cost')) {
            return true;
        }

        return false;
    }
}