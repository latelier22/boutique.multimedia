<?php

namespace App\Service\Hiboutik;

use App\Service\Pdf\UtopyaInvoiceParser;
use App\Service\Pdf\JensMobilePdfParser;
use App\Service\Pdf\MobileSentrixPdfParser;

class ImportParserRegistry
{
    public function __construct(
        private UtopyaInvoiceParser $utopyaInvoiceParser,
        private JensMobilePdfParser $jensMobilePdfParser,
        private MobileSentrixPdfParser $mobileSentrixPdfParser,
        private MobileImportParser $mobileImportParser,
    ) {
    }

    public function parse(string $parserKey, string $filePath): array
    {
        return match ($parserKey) {
    'utopya' => $this->mapUtopya($this->utopyaInvoiceParser->parse($filePath)),
    'jens_mobile' => $this->mapJens($this->jensMobilePdfParser->parse($filePath)),
    'mobilesentrix' => $this->mapMobileSentrix($this->mobileSentrixPdfParser->parse($filePath)),
    'generic_spreadsheet' => $this->mobileImportParser->parse($filePath),
    default => throw new \RuntimeException('Parser inconnu : ' . $parserKey),
};
    }

    private function mapUtopya(array $pdfData): array
    {
        return [
            'source_type' => 'invoice',
            'document_code' => $pdfData['document_code'] ?? null,
            'document_date' => $pdfData['document_date'] ?? null,
            'meta' => [
                'parser' => 'utopya_pdf',
                'row_count' => count($pdfData['rows'] ?? []),
            ],
            'rows' => array_map(function (array $r, int $idx): array {
                return [
                    'line_number' => $idx + 1,
                    'unit_index' => 1,
                    'sku' => $r['sku'] ?? null,
                    'ean' => $r['ean'] ?? null,
                    'raw_label' => (string) ($r['label'] ?? ''),
                    'imei' => $r['imei'] ?? null,
                    'quantity' => (int) ($r['qty'] ?? 1),
                    'buy_price' => (float) ($r['unit_price'] ?? 0),
                    'currency_code' => 'EUR',
                    'parsed_brand' => null,
                    'parsed_model' => null,
                    'parsed_storage' => null,
                    'parsed_color' => null,
                    'parsed_grade' => null,
                    'requires_imei' => false,
                    'status' => 'draft',
                    'raw_data' => $r,
                ];
            }, $pdfData['rows'] ?? [], array_keys($pdfData['rows'] ?? [])),
        ];
    }

    private function mapJens(array $pdfData): array
    {
        return [
            'source_type' => (string) ($pdfData['source_type'] ?? 'purchase_order'),
            'document_code' => $pdfData['document_code'] ?? null,
            'document_date' => $pdfData['document_date'] ?? null,
            'meta' => [
                'parser' => 'jens_mobile_pdf',
                'row_count' => count($pdfData['rows'] ?? []),
            ],
            'rows' => array_map(function (array $r, int $idx): array {
                return [
                    'line_number' => (int) ($r['line_number'] ?? ($idx + 1)),
                    'unit_index' => 1,
                    'sku' => $r['sku'] ?? null,
                    'ean' => $r['ean'] ?? null,
                    'raw_label' => (string) ($r['label'] ?? ''),
                    'imei' => $r['imei'] ?? null,
                    'quantity' => (int) ($r['qty'] ?? 1),
                    'buy_price' => (float) ($r['unit_price'] ?? 0),
                    'currency_code' => 'EUR',
                    'parsed_brand' => null,
                    'parsed_model' => null,
                    'parsed_storage' => null,
                    'parsed_color' => null,
                    'parsed_grade' => null,
                    'requires_imei' => false,
                    'status' => 'draft',
                    'raw_data' => $r,
                ];
            }, $pdfData['rows'] ?? [], array_keys($pdfData['rows'] ?? [])),
        ];
    }

    private function mapMobileSentrix(array $pdfData): array
{
    return [
        'source_type' => (string) ($pdfData['source_type'] ?? 'purchase_order'),
        'document_code' => $pdfData['document_code'] ?? null,
        'document_date' => $pdfData['document_date'] ?? null,
        'meta' => [
            'parser' => 'mobilesentrix_pdf',
            'row_count' => count($pdfData['rows'] ?? []),
        ],
        'rows' => array_map(function (array $r, int $idx): array {
            return [
                'line_number' => (int) ($r['line_number'] ?? ($idx + 1)),
                'unit_index' => 1,
                'sku' => $r['sku'] ?? null,
                'ean' => $r['ean'] ?? null,
                'raw_label' => (string) ($r['label'] ?? ''),
                'imei' => $r['imei'] ?? null,
                'quantity' => (int) ($r['qty'] ?? 1),
                'buy_price' => (float) ($r['unit_price'] ?? 0),
                'currency_code' => 'PLN',
                'parsed_brand' => null,
                'parsed_model' => null,
                'parsed_storage' => null,
                'parsed_color' => null,
                'parsed_grade' => null,
                'requires_imei' => false,
                'status' => 'draft',
                'raw_data' => $r,
            ];
        }, $pdfData['rows'] ?? [], array_keys($pdfData['rows'] ?? [])),
    ];
}
}