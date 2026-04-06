<?php

namespace App\Controller\Admin\Hiboutik;

use App\Service\HiboutikReferentialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/hiboutik/referential', name: 'admin_hiboutik_referential_')]
final class HiboutikReferentialController extends AbstractController
{
    public function __construct(
        private HiboutikReferentialService $referential,
    ) {}

    #[Route('/brands', name: 'brands', methods: ['GET'])]
    public function brands(): JsonResponse
    {
        $rows = $this->referential->getBrandsRows();

        return $this->json([
            'ok' => true,
            'items' => array_map(static function (array $row) {
                return [
                    'id' => (int)($row['brand_id'] ?? $row['id'] ?? 0),
                    'label' => trim((string)($row['brand_name'] ?? $row['name'] ?? '')),
                ];
            }, $rows),
        ]);
    }

    #[Route('/brands/create', name: 'brands_create', methods: ['POST'])]
    public function createBrand(Request $request): JsonResponse
    {
        $data = json_decode((string)$request->getContent(), true) ?? [];
        $name = trim((string)($data['name'] ?? ''));

        if ($name === '') {
            return $this->json(['ok' => false, 'error' => 'name_required'], 400);
        }

        try {
            $brandId = $this->referential->resolveOrCreateBrandId($name);
            $this->referential->clearCache();
            return $this->json([
                'ok' => true,
                'item' => [
                    'id' => $brandId,
                    'label' => $name,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/categories/tree', name: 'categories_tree', methods: ['GET'])]
    public function categoriesTree(): JsonResponse
    {
        $tree = $this->referential->buildCategoryTree();
        $choices = $this->referential->buildCategoryChoicesAndDisabled();

        return $this->json([
            'ok' => true,
            'tree' => $tree,
            'choices' => $choices['choices'],
            'disabled' => $choices['disabled'],
        ]);
    }

    #[Route('/categories/create', name: 'categories_create', methods: ['POST'])]
    public function createCategory(Request $request): JsonResponse
    {
        $data = json_decode((string)$request->getContent(), true) ?? [];

        $name = trim((string)($data['name'] ?? ''));
        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : null;

        if ($name === '') {
            return $this->json(['ok' => false, 'error' => 'name_required'], 400);
        }

        try {
            $created = $this->referential->createCategory($name, $parentId);
            $choices = $this->referential->buildCategoryChoicesAndDisabled();

            $reponse = $this->json([
                'ok' => true,
                'item' => [
                    'id' => $created['id'],
                    'label' => $created['name'],
                    'parent_id' => $created['parent_id'],
                ],
                'choices' => $choices['choices'],
                'disabled' => $choices['disabled'],
            ]);
            $this->referential->clearCache();

            return $this->json([
                'ok' => true,
                'item' => [
                    'id' => $created['id'],
                    'label' => $created['name'],
                    'parent_id' => $created['parent_id'],
                ],
                'choices' => $choices['choices'],
                'disabled' => $choices['disabled'],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}