<?php

namespace App\Controller\Admin\Stock;

use App\Entity\Stock\Boite;
use App\Repository\Stock\BoiteRepository;
use App\Service\BoiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/boites', name: 'admin_boite_')]
class BoiteController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(BoiteRepository $repo): Response
    {
        $items = $repo->createQueryBuilder('b')
            ->leftJoin('b.intervention', 'i')->addSelect('i')
            ->leftJoin('b.rachat', 'r')->addSelect('r')
            ->leftJoin('b.items', 'ri')->addSelect('ri')
            ->leftJoin('ri.dossier', 'd')->addSelect('d')
            ->orderBy('b.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('@SyliusAdmin/Boite/index.html.twig', [
            'items' => $items,
            'roots' => $repo->findRoots(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, BoiteManager $manager): Response
    {
        try {
            $selectedRoot = trim((string) $request->request->get('root', ''));
            $newRoot = trim((string) $request->request->get('new_root', ''));

            $root = $newRoot !== '' ? $newRoot : $selectedRoot;

            $boite = $manager->createNext(null, $root);

            $this->addFlash('success', sprintf('Boîte %s créée.', $boite->getCode()));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_boite_index');
    }

    #[Route('/{id}/release', name: 'release', methods: ['POST'])]
    public function release(Boite $boite, BoiteManager $manager): Response
    {
        $manager->release($boite);

        $this->addFlash('success', sprintf('Boîte %s libérée.', $boite->getCode()));

        return $this->redirectToRoute('admin_boite_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Boite $boite, EntityManagerInterface $em): Response
    {
        if (!$boite->isAvailable()) {
            $this->addFlash('error', sprintf(
                'La boîte %s n’est pas libre et ne peut pas être supprimée.',
                $boite->getCode()
            ));

            return $this->redirectToRoute('admin_boite_index');
        }

        $em->remove($boite);
        $em->flush();

        $this->addFlash('success', sprintf('Boîte %s supprimée.', $boite->getCode()));

        return $this->redirectToRoute('admin_boite_index');
    }
}