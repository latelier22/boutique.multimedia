<?php

namespace App\Service\Hiboutik;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class MobileImportParser
{
    public function parse(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Fichier introuvable.');
        }

        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return $this->parseCsvFile($filePath);
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->parseSpreadsheetFile($filePath);
        }

        if ($ext === 'pdf') {
            throw new \RuntimeException('Les PDF ne sont pas encore pris en charge pour la préparation automatique.');
        }

        throw new \RuntimeException('Format non pris en charge : .' . $ext);
    }

    private function parseSpreadsheetFile(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de lire le fichier Excel : ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
            throw new \RuntimeException('Fichier Excel vide ou illisible.');
        }

        $header = $rows[0];

        if ($this->looksLikeDeliveryNote($header)) {
            return $this->parseDeliveryNote($rows);
        }

        if ($this->looksLikePurchaseOrder($header)) {
            return $this->parsePurchaseOrder($rows);
        }

        throw new \RuntimeException('Format Excel non reconnu.');
    }

 private function parseCsvFile(string $filePath): array
{
    $delimiter = $this->guessCsvDelimiter($filePath);

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
    $reader->setDelimiter($delimiter);
    $reader->setEnclosure('"');
    $reader->setSheetIndex(0);

    try {
        $spreadsheet = $reader->load($filePath);
    } catch (\Throwable $e) {
        throw new \RuntimeException('Impossible de lire le CSV : ' . $e->getMessage());
    }

    $sheet = $spreadsheet->getSheet(0);
    $rows = $sheet->toArray(null, true, true, false);

    if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
        throw new \RuntimeException('CSV vide ou illisible.');
    }

    $header = $rows[0];
    $normalizedHeader = array_map([$this, 'normalizeHeader'], $header);

    if ($this->looksLikeDeliveryNote($header)) {
        return $this->parseDeliveryNote($rows);
    }

    if ($this->looksLikePurchaseOrder($header)) {
        return $this->parsePurchaseOrder($rows);
    }

    $expectedDelivery = ['sku', 'ean', 'produit', 'imei', 'quantit*', 'prixachat*'];
    $expectedOrder = ['descriptionduproduit', 'quantityordered', 'prixunitaire'];

    throw new \RuntimeException(
        "Format CSV non reconnu.\n"
        . "Délimiteur détecté: '{$delimiter}'\n"
        . "Colonnes reçues: " . json_encode($header, JSON_UNESCAPED_UNICODE) . "\n"
        . "Colonnes normalisées: " . json_encode($normalizedHeader, JSON_UNESCAPED_UNICODE) . "\n"
        . "Attendu pour BL: " . json_encode($expectedDelivery, JSON_UNESCAPED_UNICODE) . "\n"
        . "Attendu pour BC: " . json_encode($expectedOrder, JSON_UNESCAPED_UNICODE)
    );
}

    private function guessCsvDelimiter(string $filePath): string
    {
        $sample = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sample = array_slice($sample ?: [], 0, 5);
        $text = implode("\n", $sample);

        $counts = [
            ';' => substr_count($text, ';'),
            ',' => substr_count($text, ','),
            "\t" => substr_count($text, "\t"),
        ];

        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        return $delimiter !== '' ? $delimiter : ';';
    }

    private function looksLikeDeliveryNote(array $header): bool
    {
        $norm = array_map([$this, 'normalizeHeader'], $header);

        return in_array('sku', $norm, true)
            && in_array('ean', $norm, true)
            && in_array('produit', $norm, true)
            && in_array('imei', $norm, true);
    }

    private function looksLikePurchaseOrder(array $header): bool
    {
        $norm = array_map([$this, 'normalizeHeader'], $header);

        return in_array('descriptionduproduit', $norm, true)
            && in_array('quantityordered', $norm, true)
            && in_array('prixunitaire', $norm, true);
    }

    private function parseDeliveryNote(array $rows): array
    {
        $header = $rows[0];

        $iSku   = $this->findIndexStartsWith($header, ['sku']);
        $iEan   = $this->findIndexStartsWith($header, ['ean']);
        $iLabel = $this->findIndexStartsWith($header, ['produit']);
        $iImei  = $this->findIndexStartsWith($header, ['imei']);
        $iQty   = $this->findIndexStartsWith($header, ['quantit']);
        $iPrice = $this->findIndexStartsWith($header, ['prixachat', 'prixdac']);

        $out = [];

        for ($line = 1; $line < count($rows); $line++) {
            $r = $rows[$line];

            $label = trim((string) ($r[$iLabel] ?? ''));
$imeiRaw = trim((string) ($r[$iImei] ?? ''));
$imei = $imeiRaw !== '' ? preg_replace('/\D+/', '', $imeiRaw) : null;

if ($label === '') {
    continue;
}

            $parsed = $this->parseHumanLabel($label);

            $out[] = [
                'line_number' => $line + 1,
                'unit_index' => 1,
                'sku' => trim((string) ($r[$iSku] ?? '')),
                'ean' => trim((string) ($r[$iEan] ?? '')),
                'raw_label' => $label,
                'imei' => $imei,
                'quantity' => max(1, $this->parseInt($r[$iQty] ?? 1)),
                'buy_price' => $this->parseMoney($r[$iPrice] ?? null),
                'currency_code' => 'EUR',
                'parsed_brand' => $parsed['brand'],
                'parsed_model' => $parsed['model'],
                'parsed_storage' => $parsed['storage'],
                'parsed_color' => $parsed['color'],
                'parsed_grade' => $parsed['grade'],
                'requires_imei' => false,
                'status' => 'draft',
                'raw_data' => [
                    'source_row' => $r,
                ],
            ];
        }

        return [
            'source_type' => 'delivery_note',
            'document_code' => null,
            'meta' => [
                'detected_rows' => count($out),
            ],
            'rows' => $out,
        ];
    }

    private function parsePurchaseOrder(array $rows): array
    {
        $header = $rows[0];

        $iOrder = $this->findIndex($header, ['Numéro de commande', 'Numero de commande']);
        $iDesc = $this->findIndex($header, ['Description du produit']);
        $iSku = $this->findIndex($header, ['SKU']);
        $iUnitPrice = $this->findIndex($header, ['Prix unitaire']);
        $iQty = $this->findIndex($header, ['Quantity Ordered']);
        $iCurrency = $this->findIndex($header, ['Currency Code']);

        $documentCode = null;

        for ($line = 1; $line < count($rows); $line++) {
            $candidate = trim((string) ($rows[$line][$iOrder] ?? ''));
            if ($candidate !== '') {
                $documentCode = $candidate;
                break;
            }
        }

        $out = [];

        for ($line = 1; $line < count($rows); $line++) {
            $r = $rows[$line];

            $desc = trim((string) ($r[$iDesc] ?? ''));
            if ($desc === '') {
                continue;
            }

            if (mb_strtolower($desc, 'UTF-8') === 'total') {
                continue;
            }

            $qty = $this->parseInt($r[$iQty] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $parsed = $this->parseHumanLabel($desc);
            $currency = trim((string) ($r[$iCurrency] ?? 'EUR'));
            $price = $this->parseMoney($r[$iUnitPrice] ?? null);

            for ($u = 1; $u <= $qty; $u++) {
                $out[] = [
                    'line_number' => $line + 1,
                    'unit_index' => $u,
                    'sku' => trim((string) ($r[$iSku] ?? '')),
                    'ean' => null,
                    'raw_label' => $desc,
                    'imei' => null,
                    'quantity' => 1,
                    'buy_price' => $price,
                    'currency_code' => $currency !== '' ? $currency : 'EUR',
                    'parsed_brand' => $parsed['brand'],
                    'parsed_model' => $parsed['model'],
                    'parsed_storage' => $parsed['storage'],
                    'parsed_color' => $parsed['color'],
                    'parsed_grade' => $parsed['grade'],
                    'requires_imei' => true,
                    'status' => 'draft',
                    'raw_data' => [
                        'source_row' => $r,
                        'ordered_qty' => $qty,
                    ],
                ];
            }
        }

        return [
            'source_type' => 'purchase_order',
            'document_code' => $documentCode,
            'meta' => [
                'detected_units' => count($out),
            ],
            'rows' => $out,
        ];
    }

    private function parseHumanLabel(string $label): array
    {
        $work = trim($label);
        $work = preg_replace('/^Pre-Owned Device\s*-\s*/i', '', $work);
        $work = trim((string) $work);

        $grade = null;
        if (preg_match('/(?:Grade\s*([A-Z]))/i', $work, $m)) {
            $grade = strtoupper(trim((string) $m[1]));
        }

        $storage = null;
        if (preg_match('/\b(\d+)\s?(GB|Go|TB)\b/i', $work, $m)) {
            $storage = $m[1] . ' ' . $m[2];
        }

        $brand = null;
        if (preg_match('/\biPhone\b/i', $work) || preg_match('/\biPad\b/i', $work)) {
            $brand = 'Apple';
        } elseif (preg_match('/\bGalaxy\b/i', $work)) {
            $brand = 'Samsung';
        } elseif (preg_match('/\bPOCO\b/i', $work)) {
            $brand = 'POCO';
        } elseif (preg_match('/\bXiaomi\b/i', $work) || preg_match('/\bRedmi\b/i', $work)) {
            $brand = 'Xiaomi';
        }

        $colors = [
            'Black', 'Noir', 'Silver', 'Argent', 'White', 'Blanc', 'Blue', 'Bleu',
            'Red', 'Rouge', 'Green', 'Vert', 'Purple', 'Violet', 'Pink', 'Rose',
            'Gold', 'Or', 'Gray', 'Grey', 'Gris', 'Titanium', 'Titane',
        ];

        $color = null;
        foreach ($colors as $candidate) {
            if (preg_match('/\b' . preg_quote($candidate, '/') . '\b/i', $work)) {
                $color = $candidate;
            }
        }

        $model = $work;
        $model = preg_replace('/\([^)]*\)/', ' ', (string) $model);
        $model = preg_replace('/\b\d+\s?(GB|Go|TB)\b/i', ' ', (string) $model);
        $model = preg_replace('/\bGrade\s*[A-Z]\b/i', ' ', (string) $model);

        foreach ($colors as $candidate) {
            $model = preg_replace('/\b' . preg_quote($candidate, '/') . '\b/i', ' ', (string) $model);
        }

        $model = preg_replace('/\s+/', ' ', (string) $model);
        $model = trim((string) $model, " -");

        if ($brand !== null) {
    $brandNorm = mb_strtolower($brand, 'UTF-8');
    $modelNorm = mb_strtolower($model, 'UTF-8');

    if (str_starts_with($modelNorm, $brandNorm . ' ')) {
        $model = trim(substr($model, mb_strlen($brand, 'UTF-8')));
    }
}

        return [
            'brand' => $brand,
            'model' => $model !== '' ? $model : null,
            'storage' => $storage,
            'color' => $color,
            'grade' => $grade,
        ];
    }

    private function parseMoney(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $raw = preg_replace('/[^\d,.\-]/u', '', $raw);
        $raw = str_replace(',', '.', (string) $raw);

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    private function parseInt(mixed $value): int
    {
        $raw = preg_replace('/\D+/', '', (string) $value);
        return $raw !== '' ? (int) $raw : 0;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = preg_replace('/[^a-z0-9]+/', '', (string) $value);

        return (string) $value;
    }

    private function findIndex(array $header, array $needles): int
    {
        $normalized = array_map([$this, 'normalizeHeader'], $header);
        $needles = array_map([$this, 'normalizeHeader'], $needles);

        foreach ($needles as $needle) {
            $index = array_search($needle, $normalized, true);
            if ($index !== false) {
                return (int) $index;
            }
        }

        throw new \RuntimeException('Colonne introuvable : ' . implode(' / ', $needles));
    }

    private function findIndexStartsWith(array $header, array $prefixes): int
    {
        $normalized = array_map([$this, 'normalizeHeader'], $header);
        $prefixes = array_map([$this, 'normalizeHeader'], $prefixes);

        foreach ($normalized as $index => $value) {
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($value, $prefix)) {
                    return (int) $index;
                }
            }
        }

        throw new \RuntimeException('Colonne introuvable (prefix): ' . implode(' / ', $prefixes));
    }
}