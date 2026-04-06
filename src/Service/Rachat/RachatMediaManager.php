<?php

namespace App\Service\Rachat;

use App\Entity\Rachat\RachatItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;

final class RachatMediaManager
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function storeItemPhoto(RachatItem $item, UploadedFile $file, string $slot): string
    {
        if (!$item->getId()) {
            throw new \RuntimeException('L’item doit être enregistré avant upload photo.');
        }

        if (!in_array($slot, ['photo1', 'photo2', 'photo3'], true)) {
            throw new \RuntimeException('Slot photo invalide.');
        }

        $dir = $this->projectDir . '/public/uploads/rachats-v2/items/' . $item->getId();
        (new Filesystem())->mkdir($dir, 0775);

        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $filename = sprintf('%s-%s.%s', $slot, bin2hex(random_bytes(8)), $ext);
        $file->move($dir, $filename);

        $url = '/uploads/rachats-v2/items/' . $item->getId() . '/' . $filename;

        match ($slot) {
            'photo1' => $item->setPhoto1($url),
            'photo2' => $item->setPhoto2($url),
            'photo3' => $item->setPhoto3($url),
        };

        return $url;
    }


    public function deleteItemPhoto(RachatItem $item, string $slot): void
{
    if (!in_array($slot, ['photo1', 'photo2', 'photo3'], true)) {
        throw new \RuntimeException('Slot photo invalide.');
    }

    $url = match ($slot) {
        'photo1' => $item->getPhoto1(),
        'photo2' => $item->getPhoto2(),
        'photo3' => $item->getPhoto3(),
    };

    if ($url) {
        $path = $this->projectDir . '/public' . $url;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    match ($slot) {
        'photo1' => $item->setPhoto1(null),
        'photo2' => $item->setPhoto2(null),
        'photo3' => $item->setPhoto3(null),
    };
}
}