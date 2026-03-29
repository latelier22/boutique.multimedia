<?php

namespace App\Service;

use TCPDF;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Picqer\Barcode\BarcodeGeneratorPNG;

final class ProductLabelTcpdfGenerator
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}




    private function barcodePngDataUri(string $barcode): ?string
    {
        $barcode = preg_replace('/\s+/', '', $barcode);
        $barcode = trim((string) $barcode);

        if ($barcode === '') {
            return null;
        }

        try {
            $generator = new BarcodeGeneratorPNG();

            $png = $generator->getBarcode(
                $barcode,
                $generator::TYPE_CODE_128,              
                2,   // largeur barre
                60   // hauteur
            );

            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            return null;
        }
    }



    /**
     * @param array{
     *   product: array,
     *   qr_code_data_uri?: string|null
     * } $viewData
     *
     * @return array{path:string,url:string}
     */
    public function generateA5FourLabels60x105(array $viewData, string $filename): array
    {
        $dir = $this->projectDir . '/public/uploads/labels';
        (new Filesystem())->mkdir($dir, 0775);

        $path = $dir . '/' . $filename;
        $url  = '/uploads/labels/' . $filename;

        if (file_exists($path)) {
            @unlink($path);
        }

        $pdf = new TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
        $pdf->SetCreator('Multimédia Services');
        $pdf->SetAuthor('Multimédia Services');
        $pdf->SetTitle('Étiquettes A5 x4');
        $pdf->SetSubject('Étiquettes 60x105');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCellPadding(0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->AddPage();

        // A5 portrait = 148 x 210 mm
        // 2 colonnes de 60 mm = 120 mm
        // reste 28 mm => marge gauche/droite = 14 mm
        $slots = [
            ['x' => 14.0, 'y' => 0.0,   'w' => 60.0, 'h' => 105.0],
            ['x' => 74.0, 'y' => 0.0,   'w' => 60.0, 'h' => 105.0],
            ['x' => 14.0, 'y' => 105.0, 'w' => 60.0, 'h' => 105.0],
            ['x' => 74.0, 'y' => 105.0, 'w' => 60.0, 'h' => 105.0],
        ];

        foreach ($slots as $slot) {
            $this->drawLabel($pdf, $slot['x'], $slot['y'], $slot['w'], $slot['h'], $viewData);
        }

        $this->drawCropMarks($pdf);

        $pdf->Output($path, 'F');

        return [
            'path' => $path,
            'url'  => $url,
        ];
    }

    /**
     * @param array{
     *   product: array,
     *   qr_code_data_uri?: string|null
     * } $viewData
     */
    private function drawLabel(TCPDF $pdf, float $x, float $y, float $w, float $h, array $viewData): void
    {
        $product = $viewData['product'] ?? [];
        $qrDataUri = $viewData['qr_code_data_uri'] ?? null;

        // Fond
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $w, $h, 'F');

        // Bordure
        $pdf->SetDrawColor(34, 34, 34);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($x, $y, $w, $h);

        // Ref
        $ref = (string)($product['product_barcode'] ?? $product['product_id'] ?? '');
        $pdf->SetFont('helvetica', '', 5);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY($x + $w - 22, $y + 1.2);
        $pdf->Cell(20, 2, $ref, 0, 0, 'R');

        // Marque
        $brand = trim((string)($product['brand_name'] ?? ''));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY($x + 3, $y + 5);
        $pdf->MultiCell($w - 6, 5, strtoupper($brand), 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);

        // Modèle
        $model = trim((string)($product['product_model'] ?? ''));
        $pdf->SetFont('helvetica', 'B', 10.5);
        $pdf->SetXY($x + 3, $y + 13);
        $pdf->MultiCell($w - 6, 4, strtoupper($model), 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);

        // Stockage
        $storage = trim((string)($product['storage'] ?? ''));
        if ($storage !== '') {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetXY($x + 3, $y + 25);
            $pdf->Cell($w - 6, 4, $storage, 0, 0, 'L');
        }

        // Etat
        $stateLabel = trim((string)($product['state_label'] ?? 'BON ÉTAT'));
        $stateClass = (string)($product['state_class'] ?? 'state-yellow');
        [$r, $g, $b, $textR, $textG, $textB] = $this->stateColors($stateClass);

        $stateX = $x + 3;
        $stateY = $y + 37;
        $stateW = 22;
        $stateH = 5;

        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($stateX, $stateY, $stateW, $stateH, 'F');

        $pdf->SetTextColor($textR, $textG, $textB);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetXY($stateX, $stateY + 0.5);
        $pdf->Cell($stateW, 4, strtoupper($stateLabel), 0, 0, 'C');

        // QR code
        if ($qrDataUri && preg_match('#^data:image/[^;]+;base64,(.+)$#', $qrDataUri, $m)) {
            $tmp = tempnam(sys_get_temp_dir(), 'qr_');
            file_put_contents($tmp, base64_decode($m[1]));
            $pdf->Image($tmp, $x + $w - 18 - 3, $y + 30, 18, 18, '', '', '', false, 300, '', false, false, 0, false, false, false);
            @unlink($tmp);
        }

        // Prix
        $price = (string)($product['price_label'] ?? '0,00');

        $priceY = $y + 50;

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 28);
        $pdf->SetXY($x + 3, $priceY);
        $pdf->Cell(0, 10, $price . '€', 0, 0, 'L');

        // Specs
        // Specs
$specY = $y + 68;
$footerH = 7.9; // bandeau +5%
$bottomTopY = $y + $h - $footerH;

// zone dispo pour les attributs
$specAreaHeight = $bottomTopY - $specY - 1.5;

$rows = $product['misc_rows'] ?? [];
$specRows = [];

foreach ($rows as $row) {
    $label = trim((string)($row['label'] ?? ''));
    $value = trim((string)($row['value'] ?? ''));

    if ($label === '' || $value === '') {
        continue;
    }
    if (mb_strtolower($label) === 'stockage') {
        continue;
    }

    $specRows[] = [
        'label' => $label,
        'value' => $value,
    ];
}

// police augmentée
$specFontSize = 7.8;
$lineH = 3.9;

// si trop de lignes, on réduit un peu
if (count($specRows) >= 4) {
    $specFontSize = 7.0;
    $lineH = 3.5;
}
if (count($specRows) >= 5) {
    $specFontSize = 6.4;
    $lineH = 3.2;
}

$currentY = $specY;
$pdf->SetTextColor(34, 34, 34);

foreach ($specRows as $row) {
    if ($currentY + $lineH > $bottomTopY - 0.8) {
        break;
    }

    $label = $row['label'] . ' : ';
    $value = $row['value'];

    $startX = $x + 3;
    $maxW = $w - 6;

    // label en gras
    $pdf->SetFont('helvetica', 'B', $specFontSize);
    $pdf->SetXY($startX, $currentY);
    $pdf->Cell(0, $lineH, $label, 0, 0, 'L');

    $labelWidth = $pdf->GetStringWidth($label, 'helvetica', 'B', $specFontSize);

    // valeur normale
    $pdf->SetFont('helvetica', '', $specFontSize);
    $pdf->SetXY($startX + $labelWidth, $currentY);
    $pdf->Cell($maxW - $labelWidth, $lineH, $value, 0, 0, 'L');

    $currentY += $lineH;
}

$barcode = trim((string) ($product['product_barcode'] ?? ''));
$barcodePng = $this->barcodePngDataUri($barcode);

$footerH = 7.9;
$barcodeH = 10;
$barcodeGap = 3;
$bottomTopY = $y + $h - $footerH - $barcodeH - $barcodeGap;

if ($barcodePng) {
    if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $barcodePng, $m)) {
        $tmp = tempnam(sys_get_temp_dir(), 'bar_');
        file_put_contents($tmp, base64_decode($m[1]));

        $barcodeX = $x + 3;
        $barcodeW = $w - 6;
        $barcodeH = 10;
        $barcodeY = $y + $h - $footerH - $barcodeH - 2.5;

        $pdf->Image(
            $tmp,
            $barcodeX,
            $barcodeY,
            $barcodeW,
            $barcodeH,
            'PNG',
            '',
            '',
            false,
            300,
            '',
            false,
            false,
            0,
            false,
            false,
            false
        );

        @unlink($tmp);
    }
}

        // Bandeau bas orange
        $footer = trim((string)($product['footer_label'] ?? 'GARANTIE 2 ANS'));
        $footerH = 10; // hauteur du bandeau +5% de marge
        $pdf->SetFillColor(227, 120, 32);
        $pdf->Rect($x, $y + $h - $footerH, $w, $footerH, 'F');

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetXY($x, $y + $h - $footerH + 1.5);
        $pdf->Cell($w, 4, strtoupper($footer), 0, 0, 'C');
    }

    private function drawCropMarks(TCPDF $pdf): void
    {
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);

        // horizontaux sur la ligne médiane
        $pdf->Line(10, 105, 14, 105);
        $pdf->Line(134, 105, 138, 105);

        // verticaux sur haut / milieu / bas
        $pdf->Line(14, 0, 14, 4);
        $pdf->Line(74, 0, 74, 4);
        $pdf->Line(134, 0, 134, 4);

        $pdf->Line(14, 103, 14, 107);
        $pdf->Line(74, 103, 74, 107);
        $pdf->Line(134, 103, 134, 107);

        $pdf->Line(14, 206, 14, 210);
        $pdf->Line(74, 206, 74, 210);
        $pdf->Line(134, 206, 134, 210);
    }

    /**
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int}
     */
    private function stateColors(string $stateClass): array
    {
        return match ($stateClass) {
            'state-blue'   => [30, 136, 229, 255, 255, 255],
            'state-green'  => [46, 125, 50, 255, 255, 255],
            'state-red'    => [198, 40, 40, 255, 255, 255],
            default        => [241, 196, 15, 17, 17, 17],
        };
    }

public function generateA5Slots60x105(array $slotsData, string $filename): array
{
    $dir = $this->projectDir . '/public/uploads/labels';
    (new Filesystem())->mkdir($dir, 0775);

    $path = $dir . '/' . $filename;
    $url  = '/uploads/labels/' . $filename;

    if (file_exists($path)) {
        @unlink($path);
    }

    $pdf = new \TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
    $pdf->SetCreator('Multimédia Services');
    $pdf->SetAuthor('Multimédia Services');
    $pdf->SetTitle('Étiquettes A5 x4');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0, true);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->SetCellPadding(0);
    $pdf->AddPage();

    $positions = [
        ['x' => 14.0, 'y' => 0.0,   'w' => 60.0, 'h' => 105.0],
        ['x' => 74.0, 'y' => 0.0,   'w' => 60.0, 'h' => 105.0],
        ['x' => 14.0, 'y' => 105.0, 'w' => 60.0, 'h' => 105.0],
        ['x' => 74.0, 'y' => 105.0, 'w' => 60.0, 'h' => 105.0],
    ];

    foreach ($positions as $i => $pos) {
        $slotData = $slotsData[$i] ?? null;

        if ($slotData !== null) {
            $this->drawLabel($pdf, $pos['x'], $pos['y'], $pos['w'], $pos['h'], $slotData);
        } else {
            // facultatif : cadre vide
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect($pos['x'], $pos['y'], $pos['w'], $pos['h']);
        }
    }

    $this->drawCropMarks($pdf);

    $pdf->Output($path, 'F');

    return [
        'path' => $path,
        'url'  => $url,
    ];
}





}