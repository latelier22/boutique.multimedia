<?php

namespace App\Controller\Admin\Stock;

use App\Entity\Stock\Boite;
use App\Repository\Stock\BoiteRepository;
use App\Service\BoiteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/boites', name: 'admin_boite_')]
class BoiteController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BoiteRepository $repo): Response
    {
        return $this->render('@SyliusAdmin/Boite/index.html.twig', [
            'items' => $repo->findBy([], ['code' => 'ASC']),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(BoiteManager $manager): Response
    {
        $boite = $manager->createNext();

        $this->addFlash('success', sprintf('Boîte %s créée.', $boite->getCode()));
        return $this->redirectToRoute('admin_boite_index');
    }

    #[Route('/{id}/release', name: 'release', methods: ['POST'])]
    public function release(Boite $boite, BoiteManager $manager): Response
    {
        $manager->release($boite);

        $this->addFlash('success', sprintf('Boîte %s libérée.', $boite->getCode()));
        return $this->redirectToRoute('admin_boite_index');
    }
}