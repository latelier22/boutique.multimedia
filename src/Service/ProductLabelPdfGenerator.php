<?php

namespace App\Service;

use Twig\Environment as Twig;
use Knp\Snappy\Pdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class ProductLabelPdfGenerator
{
    public function __construct(
        private Twig $twig,
        private Pdf $pdf,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    /**
     * @param array{
     *   product: array,
     *   qr_code_data_uri?: string|null
     * } $viewData
     *
     * @return array{path:string,url:string}
     */
    public function generateCenteredA4(array $viewData, string $filename): array
    {
        $dir = $this->projectDir . '/public/uploads/labels';
        (new Filesystem())->mkdir($dir, 0775);

        $path = $dir . '/' . $filename;
        $url  = '/uploads/labels/' . $filename;

        $html = $this->twig->render('@SyliusAdmin/Hiboutik/Products/label_a4_center.html.twig', $viewData);

        $binary = $this->pdf->getOutputFromHtml($html, [
            'enable-local-file-access' => true,
            'page-size'       => 'A4',
            'margin-top'      => '0mm',
            'margin-bottom'   => '0mm',
            'margin-left'     => '0mm',
            'margin-right'    => '0mm',
            'dpi'             => 300,
            'print-media-type'=> true,
        ]);

        file_put_contents($path, $binary);

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
     *
     * @return array{path:string,url:string}
     */
    public function generateA5ThreeSameLabels(array $viewData, string $filename): array
    {
        $dir = $this->projectDir . '/public/uploads/labels';
        (new Filesystem())->mkdir($dir, 0775);

        $path = $dir . '/' . $filename;
        $url  = '/uploads/labels/' . $filename;

        $html = $this->twig->render(
            '@SyliusAdmin/Hiboutik/Products/labels_a5_x3.html.twig',
            $viewData
        );

       $binary = $this->pdf->getOutputFromHtml($html, [
    'enable-local-file-access' => true,
    'page-size' => 'A5',
    'orientation' => 'Portrait',
    'margin-top' => '0mm',
    'margin-bottom' => '0mm',
    'margin-left' => '0mm',
    'margin-right' => '0mm',
    'disable-smart-shrinking' => true,
    'zoom' => 1,
    'print-media-type' => true,
    'dpi' => 300,
]);

        file_put_contents($path, $binary);

        return [
            'path' => $path,
            'url'  => $url,
        ];
    }


public function generateA5FourSameLabels(array $viewData, string $filename): array
{
    $dir = $this->projectDir . '/public/uploads/labels';
    (new Filesystem())->mkdir($dir, 0775);

    $path = $dir . '/' . $filename;
    $url  = '/uploads/labels/' . $filename;

    $html = $this->twig->render(
        '@SyliusAdmin/Hiboutik/Products/labels_a5_x4.html.twig',
        $viewData
    );

    if (file_exists($path)) {
        @unlink($path);
    }

    $binary = $this->pdf->getOutputFromHtml($html, [
        'enable-local-file-access' => true,
        'page-width'  => '148mm',
        'page-height' => '210mm',
        'margin-top' => '0mm',
        'margin-bottom' => '0mm',
        'margin-left' => '0mm',
        'margin-right' => '0mm',
        'disable-smart-shrinking' => true,
        'zoom' => 1,
        'print-media-type' => true,
        'dpi' => 96,
        'image-dpi' => 96,
        'viewport-size' => '560x794',
    ]);

    file_put_contents($path, $binary);

    return [
        'path' => $path,
        'url'  => $url,
    ];
}
public function generateBusinessCardLabel(array $viewData, string $filename): array
{
    $dir = $this->projectDir . '/public/uploads/labels';
    (new Filesystem())->mkdir($dir, 0775);

    $path = $dir . '/' . $filename;
    $url  = '/uploads/labels/' . $filename;

    $html = $this->twig->render(
        '@SyliusAdmin/Hiboutik/Products/label_business_card.html.twig',
        $viewData
    );

    $binary = $this->pdf->getOutputFromHtml($html, [
    'enable-local-file-access' => true,
    'page-width' => '55mm',
    'page-height' => '85mm',
    'margin-top' => '0mm',
    'margin-bottom' => '0mm',
    'margin-left' => '0mm',
    'margin-right' => '0mm',
    'disable-smart-shrinking' => true,
    'viewport-size' => '208x321',
    'zoom' => 1,
    'print-media-type' => true,
    'dpi' => 300,
]);

    file_put_contents($path, $binary);

    return [
        'path' => $path,
        'url'  => $url,
    ];
}

}