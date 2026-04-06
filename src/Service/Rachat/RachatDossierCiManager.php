<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatDossier;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

class RachatDossierCiManager
{
    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    public function decode(?string $rawCi): array
    {
        $recto = '';
        $verso = '';

        if (is_string($rawCi) && $rawCi !== '') {
            $decoded = json_decode($rawCi, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (!empty($decoded['recto']) && is_string($decoded['recto'])) {
                    $recto = $decoded['recto'];
                }
                if (!empty($decoded['verso']) && is_string($decoded['verso'])) {
                    $verso = $decoded['verso'];
                }

                if ($recto === '' && !empty($decoded[0]) && is_string($decoded[0])) {
                    $recto = $decoded[0];
                }
                if ($verso === '' && !empty($decoded[1]) && is_string($decoded[1])) {
                    $verso = $decoded[1];
                }
            } else {
                $recto = $rawCi;
            }
        }

        return [
            'recto' => $recto,
            'verso' => $verso,
        ];
    }

    public function encode(array $ci): ?string
    {
        $recto = trim((string) ($ci['recto'] ?? ''));
        $verso = trim((string) ($ci['verso'] ?? ''));

        if ($recto === '' && $verso === '') {
            return null;
        }

        if ($recto !== '' && $verso === '') {
            return $recto;
        }

        return json_encode([
            'recto' => $recto,
            'verso' => $verso,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function storeUploadedCi(RachatDossier $dossier, UploadedFile $file, string $kind): string
    {
        if (!$dossier->getId()) {
            throw new \RuntimeException('Le dossier doit être enregistré avant upload de la pièce d’identité.');
        }

        if (!in_array($kind, ['recto', 'verso'], true)) {
            throw new \InvalidArgumentException('Type de pièce invalide.');
        }

        $targetDir = $this->kernel->getProjectDir() . '/public/uploads/rachats-v2/ci/' . $dossier->getId();

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossible de créer le dossier de stockage CI.');
        }

        $extension = strtolower((string) $file->guessExtension());
        if ($extension === '') {
            $extension = 'jpg';
        }

        $filename = sprintf(
            '%s-%s-%s.%s',
            $kind,
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            $extension
        );

        $file->move($targetDir, $filename);

        $publicUrl = '/uploads/rachats-v2/ci/' . $dossier->getId() . '/' . $filename;

        $current = $this->decode($dossier->getPieceIdentiteUrl());
        $current[$kind] = $publicUrl;

        $dossier->setPieceIdentiteUrl($this->encode($current));

        return $publicUrl;
    }
public function deleteCi(RachatDossier $dossier, string $kind): void
{
    if (!in_array($kind, ['recto', 'verso'], true)) {
        throw new \RuntimeException('Type invalide.');
    }

    $data = $this->decode($dossier->getPieceIdentiteUrl());

    if (!is_array($data)) {
        $data = [];
    }

    unset($data[$kind]);

    $dossier->setPieceIdentiteUrl(
        $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null
    );

    // Ici, si ton service connaît déjà le chemin physique exact du fichier,
    // supprime aussi le fichier local correspondant.
    // Exemple :
    // $path = $this->getCiPath($dossier, $kind);
    // if (is_file($path)) { @unlink($path); }
}



}