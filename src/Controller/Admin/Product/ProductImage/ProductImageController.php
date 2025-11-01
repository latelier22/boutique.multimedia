<?php

namespace App\Controller\Admin\Product\ProductImage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;            // ✅
use Sylius\Component\Core\Model\ProductImageInterface;

class ProductImageController extends AbstractController
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,      // ✅
        private FactoryInterface $productImageFactory,              // ✅ @sylius.factory.product_image
    ) {}

    #[Route('/api/v2/admin/products/{code}/images', name: 'app_admin_product_add_image', methods: ['POST'], priority: 1000)]
    public function addProductImage(Request $request, string $code): JsonResponse
    {
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->findOneByCode($code);
        if (!$product) {
            return new JsonResponse(['error' => 'Product not found', 'code' => $code], Response::HTTP_NOT_FOUND);
        }

        // JSON: { "path": "...", "type": "..."? }
        $data = json_decode($request->getContent(), true) ?? [];
        $path = $data['path'] ?? null;
        if (!$path) {
            return new JsonResponse(['error' => 'Missing "path" in JSON body'], Response::HTTP_BAD_REQUEST);
        }
        $type = $data['type'] ?? null;

        // Normalise le path: on stocke un chemin relatif à /media/image
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'media/image/')) {
            $path = substr($path, strlen('media/image/'));
        }

        /** @var ProductImageInterface $image */
        $image = $this->productImageFactory->createNew();          // ✅
        $image->setPath($path);                                    // on associe un fichier déjà présent
        if ($type) { $image->setType($type); }

        $product->addImage($image);
        $this->productRepository->add($product);                   // persist + flush

        return new JsonResponse([
            '@context' => '/api/v2/contexts/ProductImage',
            '@id'      => sprintf('/api/v2/admin/product-images/%d', $image->getId()),
            '@type'    => 'ProductImage',
            'id'       => $image->getId(),
            'type'     => $image->getType(),
            'path'     => '/media/image/' . $image->getPath(),     // chemin public complet
            'owner'    => sprintf('/api/v2/admin/products/%s', $product->getCode()),
        ], Response::HTTP_CREATED, ['Content-Type' => 'application/ld+json']);
    }
}
