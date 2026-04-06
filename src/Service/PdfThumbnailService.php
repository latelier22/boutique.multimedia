<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PdfThumbnailService
{
    public function __construct(
        private ParameterBagInterface $params,
    ) {
    }

    public function getThumbnailPathFromUrl(string $pdfUrl, string $cacheKey, int $width = 260): ?string
    {
        return $this->getThumbnailPathFromUrlAndPage($pdfUrl, $cacheKey, 1, $width);
    }

    public function getThumbnailPathFromUrlAndPage(string $pdfUrl, string $cacheKey, int $page = 1, int $width = 260): ?string
    {
        $pdfPath = $this->resolveLocalPath($pdfUrl);
        if (!$pdfPath || !is_file($pdfPath)) {
            return null;
        }

        $page = max(1, $page);

        $cacheDir = $this->params->get('kernel.cache_dir') . '/pdf_thumbs';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $thumbPath = sprintf(
            '%s/%s_p%s_w%s.jpg',
            $cacheDir,
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cacheKey),
            $page,
            $width
        );

        $pdfMtime = @filemtime($pdfPath) ?: time();
        $thumbMtime = is_file($thumbPath) ? (@filemtime($thumbPath) ?: 0) : 0;

        if (!is_file($thumbPath) || $thumbMtime < $pdfMtime) {
            $this->generateThumbnail($pdfPath, $thumbPath, $page, $width);
        }

        return $thumbPath;
    }

    public function getPageCountFromUrl(string $pdfUrl): int
    {
        $pdfPath = $this->resolveLocalPath($pdfUrl);
        if (!$pdfPath || !is_file($pdfPath)) {
            return 0;
        }

        if (!class_exists(\Imagick::class)) {
            throw new \RuntimeException('Imagick non disponible.');
        }

        $imagick = new \Imagick();
        $imagick->pingImage($pdfPath);
        $count = $imagick->getNumberImages();
        $imagick->clear();
        $imagick->destroy();

        return max(0, (int) $count);
    }

    public function resolveLocalPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $projectDir = rtrim((string) $this->params->get('kernel.project_dir'), '/');
        $publicDir = $projectDir . '/public';

        $url = rawurldecode($url);

        if (str_starts_with($url, '/') && is_file($url)) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $candidate = $publicDir . $url;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $parts = parse_url($url);
        if (!empty($parts['path'])) {
            $candidate = $publicDir . rawurldecode($parts['path']);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $candidate = $publicDir . '/' . ltrim($url, '/');
        if (is_file($candidate)) {
            return $candidate;
        }

        $candidate = $projectDir . '/' . ltrim($url, '/');
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

   private function generateThumbnail(string $pdfPath, string $thumbPath, int $page, int $width): void
{
    if (!class_exists(\Imagick::class)) {
        throw new \RuntimeException('Imagick non disponible.');
    }

    $pageIndex = max(0, $page - 1);

    $resolution = 110;
    $quality = 82;

    if ($width >= 1400) {
        $resolution = 190;
        $quality = 90;
    } elseif ($width >= 1000) {
        $resolution = 170;
        $quality = 88;
    } elseif ($width >= 700) {
        $resolution = 140;
        $quality = 86;
    }

    $imagick = new \Imagick();
    $imagick->setResolution($resolution, $resolution);
    $imagick->readImage($pdfPath . '[' . $pageIndex . ']');
    $imagick->setImageFormat('jpeg');
    $imagick->setImageBackgroundColor('white');
    $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
    $imagick->thumbnailImage($width, 0);
    $imagick->setImageCompressionQuality($quality);
    $imagick->writeImage($thumbPath);
    $imagick->clear();
    $imagick->destroy();
}
}