<?php
// src/Service/RachatPdfGenerator.php
namespace App\Service;

use Twig\Environment as Twig;
use Knp\Snappy\Pdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use App\Entity\Rachat\Rachat;

final class RachatPdfGenerator
{
    public function __construct(
        private Twig $twig,
        private Pdf $pdf,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    /**
     * @param array{
     *   r:Rachat,
     *   generated_at:\DateTimeInterface,
     *   shop: array,
     *   photos?: string[],
     *   pieces_identite_data?: string[],
     *   piece_identite_url?: string|null,
     *   seller_sign_data_uri?: string|null
     * } $viewData
     * @return array{path:string,url:string}
     */
    public function generate(array $viewData, string $filename): array
    {
        /** @var Rachat $r */
        $r = $viewData['r'];

        // Sortie publique vers /public/uploads/rachats/{id}
        $dir = $this->projectDir . '/public/uploads/rachats/' . $r->getId();
        (new Filesystem())->mkdir($dir, 0775);
        $path = $dir . '/' . $filename;
        $url  = '/uploads/rachats/' . $r->getId() . '/' . $filename;

        // Assets PRIVÉS (non exposés) → embarqués en base64
        $brandDir         = $this->projectDir . '/var/private/brand';
        $companySigPath   = $brandDir . '/signature-multimedia.png';
        $logoPath         = $brandDir . '/logo-multimedia.png';

        $companySignDataUri = $this->toDataUriIfExists($companySigPath);
        $logoDataUri        = $this->toDataUriIfExists($logoPath);

        // === Fusion recto/verso si présents ===
        $piecesIdentiteData = [];

        // si le contrôleur envoie déjà pieces_identite_data (recto/verso en data URI)
        if (!empty($viewData['pieces_identite_data']) && is_array($viewData['pieces_identite_data'])) {
            $piecesIdentiteData = $viewData['pieces_identite_data'];
        }
        // sinon compat ancienne version (une seule URL)
        elseif (!empty($viewData['piece_identite_url'])) {
            $piecesIdentiteData[] = $viewData['piece_identite_url'];
        }

        // HTML
        $html = $this->twig->render('@SyliusAdmin/Rachat/pdf.html.twig', $viewData + [
            'company_logo_data_uri' => $logoDataUri,
            'company_sign_data_uri' => $companySignDataUri,
            'pieces_identite_data'  => $piecesIdentiteData,
        ]);

        // PDF (wkhtmltopdf via KnpSnappy)
        $binary = $this->pdf->getOutputFromHtml($html, [
            'enable-local-file-access' => true,
            'margin-top'    => '10mm',
            'margin-bottom' => '10mm',
            'margin-left'   => '10mm',
            'margin-right'  => '10mm',
            'dpi'           => 96,
        ]);
        file_put_contents($path, $binary);

        return ['path' => $path, 'url' => $url];
    }

    /**
     * Génération d'un bon de cession MULTI-rachats.
     *
     * @param array $viewData
     *  Attendu (ce que tu envoies depuis le contrôleur) :
     *   - rachats_items: array<array{r:Rachat, photos:string[]}>
     *   - generated_at:\DateTimeInterface
     *   - shop: array
     *   - pieces_identite_data?: string[]
     *   - seller_sign_data_uri?: string|null
     *   - ids_string?: string   (ex: "12, 13, 14")
     *
     * @return array{path:string,url:string}
     */
    public function generateMulti(array $viewData, string $filename): array
    {
        // Sortie publique vers /public/uploads/rachats/multi
        $dir = $this->projectDir . '/public/uploads/rachats/multi';
        (new Filesystem())->mkdir($dir, 0775);
        $path = $dir . '/' . $filename;
        $url  = '/uploads/rachats/multi/' . $filename;

        // Logo & signature boutique en base64 (comme pour le simple)
        $brandDir         = $this->projectDir . '/var/private/brand';
        $companySigPath   = $brandDir . '/signature-multimedia.png';
        $logoPath         = $brandDir . '/logo-multimedia.png';

        $companySignDataUri = $this->toDataUriIfExists($companySigPath);
        $logoDataUri        = $this->toDataUriIfExists($logoPath);

        // On complète le contexte avec les data URI, sauf si déjà fourni
        $html = $this->twig->render('@SyliusAdmin/Rachat/pdf_multi.html.twig', $viewData + [
            'company_logo_data_uri' => $viewData['company_logo_data_uri'] ?? $logoDataUri,
            'company_sign_data_uri' => $viewData['company_sign_data_uri'] ?? $companySignDataUri,
        ]);

        // PDF (toujours Knp\Snappy)
        $binary = $this->pdf->getOutputFromHtml($html, [
            'enable-local-file-access' => true,
            'margin-top'    => '10mm',
            'margin-bottom' => '10mm',
            'margin-left'   => '10mm',
            'margin-right'  => '10mm',
            'dpi'           => 96,
        ]);
        file_put_contents($path, $binary);

        return ['path' => $path, 'url' => $url];
    }

    private function toDataUriIfExists(string $absPath): ?string
    {
        if (!is_file($absPath)) return null;
        $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'       => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default     => 'application/octet-stream',
        };
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($absPath));
    }
}
