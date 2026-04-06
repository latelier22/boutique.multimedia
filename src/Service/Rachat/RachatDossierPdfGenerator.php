<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use App\Entity\Rachat\RachatItem;
use Knp\Snappy\Pdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment as Twig;

final class RachatDossierPdfGenerator
{
    public function __construct(
        private Twig $twig,
        private Pdf $pdf,
        private RachatDossierCiManager $ciManager,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%app.rachat_conditions_pdf%')] private string $rachatConditionsPdf,
        #[Autowire('%app.rachat_conditions_accept_label%')] private string $rachatConditionsAcceptLabel,
        #[Autowire('%app.rachat_conditions_tablet%')] private string $rachatConditionsTablet,
    ) {
    }

    /**
     * @param array{
     *   shop: array,
     *   generated_at?: \DateTimeInterface
     * } $context
     *
     * @return array{path:string,url:string}
     */
    public function generate(RachatDossier $dossier, array $context = [], ?string $filename = null): array
    {
        if (!$dossier->getId()) {
            throw new \RuntimeException('Le dossier doit être enregistré avant génération du PDF.');
        }

        $dir = $this->projectDir . '/public/uploads/rachats-v2/' . $dossier->getId();
        (new Filesystem())->mkdir($dir, 0775);

        $filename ??= 'dossier-' . $dossier->getId() . '.pdf';

        $path = $dir . '/' . $filename;
        $url = '/uploads/rachats-v2/' . $dossier->getId() . '/' . $filename;

        $brandDir = $this->projectDir . '/var/private/brand';
        $companySignDataUri = $this->toDataUriIfExists($brandDir . '/signature-multimedia.png');
        $logoDataUri = $this->toDataUriIfExists($brandDir . '/logo-multimedia.png');

        $ci = $this->ciManager->decode($dossier->getPieceIdentiteUrl());
        $ciDataUris = array_values(array_filter([
            $this->publicUrlToDataUri($ci['recto'] ?? null),
            $this->publicUrlToDataUri($ci['verso'] ?? null),
        ]));

        $signatureDataUri = $this->publicUrlToDataUri($dossier->getSignatureUrl());

        $items = [];
        foreach ($dossier->getItems() as $item) {
            $items[] = [
                'item' => $item,
                'photos_data_uris' => $this->extractItemPhotosDataUris($item),
                'attributes' => $this->normalizeAttributes($item->getAttributes()),
            ];
        }

        $html = $this->twig->render('@SyliusAdmin/Rachat/RachatDossier/pdf.html.twig', [
            'dossier' => $dossier,
            'items' => $items,
            'shop' => $context['shop'] ?? [],
            'generated_at' => $context['generated_at'] ?? new \DateTimeImmutable(),
            'company_logo_data_uri' => $logoDataUri,
            'company_sign_data_uri' => $companySignDataUri,
            'pieces_identite_data' => $ciDataUris,
            'seller_sign_data_uri' => $signatureDataUri,
            'rachat_conditions_pdf' => $this->rachatConditionsPdf,
            'rachat_conditions_accept_label' => $this->rachatConditionsAcceptLabel,
            'rachat_conditions_tablet' => $this->rachatConditionsTablet,
        ]);

        $binary = $this->pdf->getOutputFromHtml($html, [
            'enable-local-file-access' => true,
            'print-media-type' => true,
            'margin-top' => '8mm',
            'margin-bottom' => '8mm',
            'margin-left' => '10mm',
            'margin-right' => '10mm',
            'dpi' => 96,
            'encoding' => 'utf-8',
        ]);

        file_put_contents($path, $binary);

        return [
            'path' => $path,
            'url' => $url,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractItemPhotosDataUris(RachatItem $item): array
    {
        $urls = [];

        foreach ([$item->getPhoto1(), $item->getPhoto2(), $item->getPhoto3()] as $photo) {
            if (!empty($photo)) {
                $urls[] = $photo;
            }
        }

        $photosJson = $item->getPhotosJson();
        if (is_string($photosJson) && $photosJson !== '') {
            $decoded = json_decode($photosJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $photo) {
                    if (is_string($photo) && $photo !== '') {
                        $urls[] = $photo;
                    }
                }
            }
        } elseif (is_array($photosJson)) {
            foreach ($photosJson as $photo) {
                if (is_string($photo) && $photo !== '') {
                    $urls[] = $photo;
                }
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        $dataUris = [];
        foreach ($urls as $url) {
            $dataUri = $this->publicUrlToDataUri($url);
            if ($dataUri) {
                $dataUris[] = $dataUri;
            }
        }

        return $dataUris;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAttributes(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function publicUrlToDataUri(?string $url): ?string
    {
        if (!$url || !str_starts_with($url, '/')) {
            return null;
        }

        $path = $this->projectDir . '/public' . $url;

        return $this->toDataUriIfExists($path);
    }

    private function toDataUriIfExists(string $absPath): ?string
    {
        if (!is_file($absPath)) {
            return null;
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($absPath));
    }
}