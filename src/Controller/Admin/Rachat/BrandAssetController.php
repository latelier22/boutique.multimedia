<?php
// src/Controller/BrandAssetController.php
namespace App\Controller\Admin\Rachat;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

final class BrandAssetController extends AbstractController
{
    #[Route('/admin/assets/brand/{file}', name: 'admin_brand_asset', methods: ['GET'], requirements: ['file' => '.+'])]
    public function show(string $file): BinaryFileResponse   // 👈 plus "stream"
    {
        $base = realpath($this->getParameter('kernel.project_dir').'/var/private/brand');
        $path = realpath($base.'/'.$file);

        dump($base);
        dump($path);

        if (!$base || !$path || !str_starts_with($path, $base) || !is_file($path)) {
            throw $this->createNotFoundException('Brand asset introuvable');
        }

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }
}
