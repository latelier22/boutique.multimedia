<?php
namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ImageUploadController extends AbstractController
{
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $uploadedFiles = $request->files->get('upload_type')['files'] ?? []; // “upload_type” = nom automatique du formulaire

        $paths = [];
        foreach ($uploadedFiles as $file) {
            $newName = uniqid().'.'.$file->guessExtension();
            $file->move($this->getParameter('kernel.project_dir').'/public/uploads', $newName);
            $paths[] = '/uploads/'.$newName;
        }

        // Dropzone attend une réponse JSON
        return new JsonResponse(['success' => true, 'paths' => $paths]);
    }
}
