<?php

namespace App\Service\Pdf;

use Smalot\PdfParser\Parser;

class UtopyaMultiInvoicePointingParser
{
    public function parse(string $filePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        $rows = [];
        $matchedLines = [];
        $unmatchedCandidates = [];
        $rawLines = [];
        $globalLineNumber = 0;

        foreach ($pdf->getPages() as $pageIndex => $page) {
            $pageText = $page->getText();

            $pageLines = preg_split('/\r\n|\r|\n/', $pageText) ?: [];
            $pageLines = array_values(array_filter(array_map(
                fn ($l) => $this->normalize((string) $l),
                $pageLines
            ), fn ($l) => $l !== ''));

            $rawLines = array_merge($rawLines, $pageLines);

            $invoiceNumber = $this->extractInvoiceNumber($pageLines);
            $invoiceDate = $this->extractInvoiceDate($pageLines);

            $current = null;
            $pending = null;

            foreach ($pageLines as $index => $line) {
                if ($this->isIgnorableLine($line)) {
                    continue;
                }

                if ($current && preg_match('/^EAN\s*:\s*(.+)$/iu', $line, $m)) {
                    $ean = trim((string) $m[1]);
                    $ean = preg_replace('/\s*\/\s*Garantie\s*:.*$/iu', '', $ean);
                    $current['ean'] = $ean !== '' ? trim((string) $ean) : null;
                    continue;
                }

              if ($current && preg_match('/^IMEI\s*:\s*(.+)$/iu', $line, $m)) {
    $imeiRaw = trim((string) $m[1]);
    $imeis = $this->splitImeis($imeiRaw);

    $current['imei'] = $imeis[0] ?? null;   // IMEI principal seulement
    $current['imeis'] = $imeis;             // liste complète

    continue;
}

                if ($pending && preg_match('/^(\d+)\s+([\d,]+)[€¤]\s+([\d,]+)[€¤]\s*([0-9]+%[*]?)$/u', $line, $m)) {
                    $globalLineNumber++;

                    $current = [
                        'line_number' => $globalLineNumber,
                        'page_number' => $pageIndex + 1,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'sku' => $pending['sku'],
                        'label' => trim((string) $pending['label']),
                        'qty' => (int) $m[1],
                        'unit_price' => $this->toFloat($m[2]),
                        'total_ht' => $this->toFloat($m[3]),
                        'vat' => trim((string) $m[4]),
                        'ean' => null,
                        'imei' => null,
                        'imeis' => [],
                        'source_lines' => $pending['source_lines'],
                    ];

                    $matchedLines[] = [
                        'type' => 'multiline',
                        'page' => $pageIndex + 1,
                        'index' => $index,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'line' => $line,
                        'sku' => $current['sku'],
                        'label' => $current['label'],
                    ];

                    $pending = null;
                    continue;
                }

                if (preg_match('/^([A-Z0-9\-@]{4,})\s+(.+?)\s+(\d+)\s+([\d,]+)[€¤]\s+([\d,]+)[€¤]\s*([0-9]+%[*]?)$/u', $line, $m)) {
                    if ($this->isShippingLine($m[1], $m[2])) {
                        continue;
                    }

                    if ($current) {
                        $rows[] = $current;
                    }

                    $globalLineNumber++;

                    $current = [
                        'line_number' => $globalLineNumber,
                        'page_number' => $pageIndex + 1,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'sku' => $m[1],
                        'label' => trim((string) $m[2]),
                        'qty' => (int) $m[3],
                        'unit_price' => $this->toFloat($m[4]),
                        'total_ht' => $this->toFloat($m[5]),
                        'vat' => trim((string) $m[6]),
                        'ean' => null,
                        'imei' => null,
                        'imeis' => [],
                        'source_lines' => [$line],
                    ];

                    $matchedLines[] = [
                        'type' => 'single',
                        'page' => $pageIndex + 1,
                        'index' => $index,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'line' => $line,
                        'groups' => $m,
                    ];

                    continue;
                }

                if (
                    preg_match('/^([A-Z0-9\-@]{4,})\s+(.+)$/u', $line, $m)
                    && !$this->looksLikeTailPriceLine($line)
                    && !$this->isShippingLine($m[1], $m[2])
                ) {
                    if ($current) {
                        $rows[] = $current;
                        $current = null;
                    }

                    $pending = [
                        'sku' => $m[1],
                        'label' => trim((string) $m[2]),
                        'source_lines' => [$line],
                    ];

                    continue;
                }

                if ($pending) {
                    $pending['label'] .= ' ' . $line;
                    $pending['source_lines'][] = $line;
                    continue;
                }

                if (
                    str_contains($line, '€')
                    || str_contains($line, '¤')
                    || preg_match('/^[A-Z0-9\-@]{4,}\s+/u', $line)
                ) {
                    $unmatchedCandidates[] = [
                        'page' => $pageIndex + 1,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceDate,
                        'index' => $index,
                        'raw' => $line,
                        'normalized' => $line,
                    ];
                }
            }

            if ($current) {
                $rows[] = $current;
            }
        }

        return [
            'rows' => $rows,
            'matched_lines' => $matchedLines,
            'unmatched_candidates' => $unmatchedCandidates,
            'raw_lines' => $rawLines,
        ];
    }

    private function extractInvoiceNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/Facture\s*#\s*([A-Z0-9\-]+)/iu', $line, $m)) {
                return trim((string) $m[1]);
            }
        }

        return null;
    }

    private function extractInvoiceDate(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/\b(\d{2}\/\d{2}\/\d{4})\b/', $line, $m)) {
                return trim((string) $m[1]);
            }
        }

        return null;
    }

   private function splitImeis(string $raw): array
{
    $parts = preg_split('/[\s,;\/]+/', $raw) ?: [];

    $parts = array_values(array_filter(array_map(
        static function ($v): string {
            return preg_replace('/\D+/', '', trim((string) $v)) ?? '';
        },
        $parts
    ), static fn ($v) => $v !== ''));

    $parts = array_values(array_unique($parts));

    return $parts;
}

    private function normalize(string $line): string
    {
        $line = str_replace("\t", ' ', $line);
        $line = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $line);
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

        return trim($line);
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    private function looksLikeTailPriceLine(string $line): bool
    {
        return (bool) preg_match('/^\d+\s+[\d,]+[€¤]\s+[\d,]+[€¤]\s*[0-9]+%[*]?$/u', $line);
    }

    private function isShippingLine(string $sku, string $label): bool
    {
        $full = mb_strtolower($this->normalize($sku . ' ' . $label), 'UTF-8');

        return str_starts_with($full, 'shipping cost');
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

        if (str_starts_with($line, 'Restant à payer :')) {
            return true;
        }

        if (str_starts_with($line, 'e-wallet UTOPYA')) {
            return true;
        }

        if (str_starts_with($line, 'Facture #')) {
            return true;
        }

        if ($line === 'Ivan Gourdel' || $line === 'MULTIMEDIASERVICES' || $line === 'MULTIMEDIA SERVICES') {
            return true;
        }

        if (preg_match('/^\d{5}\s+/u', $line)) {
            return true;
        }

        if (str_starts_with($line, 'Shipping cost')) {
            return true;
        }

        return false;
    }
}