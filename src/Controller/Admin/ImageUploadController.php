<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;

#[Route('/api/v2/admin/images')]
class ImageUploadController extends AbstractController
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
    ) {}

    #[Route('/upload', name: 'admin_image_upload', methods: ['POST'])]
    public function uploadImage(Request $request, ParameterBagInterface $params, CacheManager $cacheManager): JsonResponse
    {
        // 1) Fichier (champ "image")
        $file = $request->files->get('image');
        if (!$file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu (champ "image").'], Response::HTTP_BAD_REQUEST);
        }

        // 2) Résoudre le channel (header -> query -> hostname)
        $channel = $this->resolveChannel($request);
        if (!$channel) {
            return new JsonResponse([
                'error' => 'Channel introuvable. Passe X-Sylius-Channel-Code ou ?channelCode=, ou configure le hostname du channel.',
            ], Response::HTTP_BAD_REQUEST);
        }
        $channelCode = $channel->getCode();

        // 3) Dossier destination
        $projectDir = $params->get('kernel.project_dir');
        $relativeDir = "/public/media/gallery/images/{$channelCode}";
        $uploadDir = $projectDir . $relativeDir;

        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            return new JsonResponse(['error' => 'Impossible de créer le dossier de destination.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4) Nom de fichier (sécurisé)
        $ext = strtolower($file->guessExtension() ?: pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION) ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'bin';
        $fileName = uniqid('', true) . '.' . $ext;

        try {
            $file->move($uploadDir, $fileName);
        } catch (FileException $e) {
            return new JsonResponse(['error' => 'Erreur lors de l’écriture du fichier: '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 5) Chemin public
        $publicPath = "/media/image/gallery/images/{$channelCode}/{$fileName}";

        // 6) (Option) URL optimisée LiipImagine
        // $optimizedUrl = $cacheManager->getBrowserPath($publicPath, 'sylius_medium');

        return new JsonResponse([
            'success'       => true,
            'channel'       => $channelCode,
            'original_url'  => $publicPath,
            // 'image_url'   => $optimizedUrl,
        ], Response::HTTP_CREATED);
    }

    private function resolveChannel(Request $request): ?ChannelInterface
    {
        $code = $request->headers->get('X-Sylius-Channel-Code')
             ?: $request->request->get('channelCode')
             ?: $request->query->get('channelCode');

        if ($code) {
            return $this->channelRepository->findOneByCode($code);
        }

        $host = $request->getHost();
        if (method_exists($this->channelRepository, 'findOneByHostname')) {
            $c = $this->channelRepository->findOneByHostname($host);
            if ($c) { return $c; }
        }
        return $this->channelRepository->findOneBy(['hostname' => $host]);
    }
}
