<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class RachatDossierSignatureManager
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function storeSignatureDataUrl(RachatDossier $dossier, string $dataUrl): string
    {
        if (!$dossier->getId()) {
            throw new \RuntimeException('Le dossier doit être enregistré avant la signature.');
        }

        if (!preg_match('#^data:image/(png|jpeg);base64,#', $dataUrl)) {
            throw new \RuntimeException('Format de signature invalide.');
        }

        [$meta, $content] = explode(',', $dataUrl, 2);
        $binary = base64_decode($content, true);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Signature vide ou corrompue.');
        }

        $ext = str_contains($meta, 'image/jpeg') ? 'jpg' : 'png';

        $dir = $this->projectDir . '/public/uploads/rachats-v2/' . $dossier->getId();
        (new Filesystem())->mkdir($dir, 0775);

        $filename = 'signature-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $filename;

        file_put_contents($path, $binary);

        $url = '/uploads/rachats-v2/' . $dossier->getId() . '/' . $filename;
        $dossier->setSignatureUrl($url);

        return $url;
    }
}