<?php

namespace App\Controller\Admin\Product\ProductImage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Model\ProductImageInterface;

class ProductImageDeleteController extends AbstractController
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
    ) {}

    #[Route(
        '/api/v2/admin/products/{code}/images/{id}',
        name: 'app_admin_product_delete_image',
        methods: ['DELETE'],
        priority: 1000
    )]
    public function delete(string $code, int $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->findOneByCode($code);
        if (!$product) {
            return new JsonResponse(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        // Chercher l'image
        /** @var ProductImageInterface|null $image */
        $image = $product->getImages()->filter(fn($img) => $img->getId() === $id)->first();
        if (!$image) {
            return new JsonResponse(['error' => 'Image not found for product'], Response::HTTP_NOT_FOUND);
        }

        // Détacher l’image
        $product->removeImage($image);
        $this->productRepository->add($product); // flush

        return new JsonResponse([
            'success' => true,
            'deleted_image_id' => $id,
            'product' => $code,
        ], Response::HTTP_OK);
    }
}
