<?php

namespace App\Controller\Admin\Rachat;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/rachats')]
class RachatAttributesController extends AbstractController
{
    #[Route('/attributes-config', name: 'admin_rachats_attributes_config')]
    public function index(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('admin/rachat/attributes_config.html.twig');
    }

    #[Route('/attributes-definitions/{category}', methods: ['GET'])]
    public function list(string $category, EntityManagerInterface $em): JsonResponse
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            "SELECT * FROM attributes_definitions WHERE category = ? ORDER BY id ASC",
            [$category]
        );

        foreach ($rows as &$r) {
            $r['options'] = $r['options'] ? json_decode($r['options'], true) : [];
        }

        return $this->json($rows);
    }

    #[Route('/attributes-definitions', methods: ['POST'])]
    public function create(Request $req, EntityManagerInterface $em): JsonResponse
    {
        $d = json_decode($req->getContent(), true);

        $em->getConnection()->insert('attributes_definitions', [
            'category' => $d['category'],
            'code' => $d['code'],
            'label' => $d['label'],
            'type' => $d['type'],
            'options' => json_encode($d['options'] ?? [])
        ]);

        return $this->json(['ok'=>true]);
    }

    #[Route('/attributes-definitions/{id}', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $em): JsonResponse
    {
        $em->getConnection()->delete('attributes_definitions', ['id'=>$id]);
        return $this->json(['ok'=>true]);
    }

    #[Route('/attributes/{categoryId}', name: 'attributes_list', methods: ['GET'])]
public function attributesList(int $categoryId): JsonResponse
{
    try {

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT * FROM hib_attributes WHERE category_id = ?",
            [$categoryId]
        );

        return new JsonResponse($rows);

    } catch (\Throwable $e) {

        return new JsonResponse([
            'error' => $e->getMessage()
        ], 500);
    }
}
}