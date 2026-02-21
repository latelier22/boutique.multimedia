<?php

namespace App\Controller\Admin\Revendeur;

use App\Entity\Revendeur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse};
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

#[Route('/admin/revendeurs', name: 'admin_revendeurs_')]
final class RevendeurCiController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CsrfTokenManagerInterface $csrf
    ) {}

    #[Route('/{id}/ci/{kind}', name: 'ci', requirements: ['id'=>'\d+', 'kind'=>'recto|verso'], methods: ['GET'])]
    public function streamCi(int $id, string $kind): BinaryFileResponse
    {
        $path = sprintf('%s/ci_%s.jpg', $this->getVarPrivateDir($id), $kind);
        if (!is_file($path)) {
            throw $this->createNotFoundException("CI $kind introuvable");
        }

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }

    #[Route('/{id}/upload-ci', name: 'upload_ci', requirements: ['id'=>'\d+'], methods: ['POST'])]
    public function uploadCi(int $id, Request $req): Response
    {
        $token = new CsrfToken('revendeur_ci_'.$id, (string)$req->request->get('_token'));
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        $kind = (string)$req->request->get('kind', 'recto'); // recto|verso
        if (!in_array($kind, ['recto','verso'], true)) {
            $this->addFlash('error', 'Kind invalide.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        /** @var Revendeur|null $rev */
        $rev = $this->em->getRepository(Revendeur::class)->find($id);
        if (!$rev) {
            $this->addFlash('error', 'Revendeur introuvable.');
            return $this->redirectToRoute('admin_revendeurs_index');
        }

        $file = $req->files->get('ci');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        $base = $this->getVarPrivateDir($id);
        @mkdir($base, 0775, true);

        $dst = sprintf('%s/ci_%s.jpg', $base, $kind);

        // Simple move (tu peux remplacer par ta compression GD si tu veux)
        $file->move($base, 'ci_'.$kind.'.jpg');

        $url = $this->generateUrl('admin_revendeurs_ci', [
            'id' => $id,
            'kind' => $kind,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($kind === 'recto') $rev->setCiRectoUrl($url);
        if ($kind === 'verso') $rev->setCiVersoUrl($url);

        $this->em->flush();

        $this->addFlash('success', 'CI '.$kind.' mise à jour.');
        return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
    }

    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/revendeurs/$id";
    }
}
