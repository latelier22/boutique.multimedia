<?php

namespace App\Controller\Widget;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WidgetController extends AbstractController
{
    #[Route('/widgets/rachats/{id}/{filename}', name: 'widget_rachat_image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function rachatImage(int $id, string $filename): Response
    {
        // ✅ Dossier privé réel
        $baseDir = $this->getParameter('kernel.project_dir') . '/var/private/rachats';
        $file = sprintf('%s/%d/%s', $baseDir, $id, $filename);

        // 🔍 Vérification stricte
       if (!is_file($file)) {
    file_put_contents('/tmp/widget_debug.log', "MISS: $file\n", FILE_APPEND);
    return new Response('Fichier introuvable', 404);
}


        // ✅ Réponse binaire avec type MIME correct
        $response = new BinaryFileResponse($file);
        $mime = mime_content_type($file) ?: 'application/octet-stream';
        $response->headers->set('Content-Type', $mime);

        // ✅ Affichage inline
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($file));

        // ✅ Headers pour autoriser Hiboutik (iframe externe)
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->headers->set('X-Frame-Options', 'ALLOW-FROM https://multimediaservices22.hiboutik.com');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' https://multimediaservices22.hiboutik.com");

        return $response;
    }
}
