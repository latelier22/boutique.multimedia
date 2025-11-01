<?php

namespace App\Controller\Admin\Product\ProductImage;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/admin/products/images/channel')]
class ProductImageListController extends AbstractController
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private ProductRepositoryInterface $productRepository,
    ) {}

    #[Route('', name: 'app_admin_product_images_by_channel', methods: ['GET'], priority: 1500)]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // 1) Resolve channel
        $channel = $this->resolveChannel($request);
        if (!$channel instanceof ChannelInterface) {
            return new JsonResponse([
                'error' => 'Channel introuvable. Utilisez un hostname connu ou header X-Sylius-Channel-Code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2) Pagination + filters
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        $q    = trim((string) $request->query->get('q', ''));     // filter on product code (contains)
        $type = trim((string) $request->query->get('type', ''));  // filter on image type

        // 3) Build query: products in channel + their images
        $qb = $this->productRepository->createQueryBuilder('p')
            ->innerJoin('p.channels', 'ch')
            ->leftJoin('p.images', 'i')
            ->addSelect('i')
            ->andWhere('ch = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('p.code', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($q !== '') {
            $qb->andWhere('p.code LIKE :q')->setParameter('q', '%'.$q.'%');
        }
        if ($type !== '') {
            $qb->andWhere('i.type = :type')->setParameter('type', $type);
        }

        /** @var ProductInterface[] $products */
        $products = $qb->getQuery()->getResult();

        // 4) Total (for pagination)
        $countQb = $this->productRepository->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->innerJoin('p.channels', 'ch2')
            ->andWhere('ch2 = :channel2')
            ->setParameter('channel2', $channel);
        if ($q !== '') {
            $countQb->andWhere('p.code LIKE :q2')->setParameter('q2', '%'.$q.'%');
        }
        $totalProducts = (int) $countQb->getQuery()->getSingleScalarResult();

        // 5) Flatten images
        $images = [];
        foreach ($products as $p) {
            foreach ($p->getImages() as $img) {
                if ($type !== '' && $img->getType() !== $type) {
                    continue;
                }
                $images[] = [
                    'product' => $p->getCode(),
                    'image'   => [
                        'id'   => $img->getId(),
                        'type' => $img->getType(),
                        // Sylius stores relative path to /media/image
                        'path' => '/media/image/' . ltrim((string) $img->getPath(), '/'),
                        '@id'  => $img->getId() ? sprintf('/api/v2/admin/product-images/%d', $img->getId()) : null,
                    ],
                ];
            }
        }

        return new JsonResponse([
            'channel' => $channel->getCode(),
            'page'    => $page,
            'limit'   => $limit,
            'totalProducts' => $totalProducts,
            'imagesCount'   => count($images),
            'filters' => [
                'q'    => $q,
                'type' => $type,
            ],
            'items' => $images,
        ], Response::HTTP_OK);
    }

    private function resolveChannel(Request $request): ?ChannelInterface
    {
        $code = $request->headers->get('X-Sylius-Channel-Code')
             ?: $request->query->get('channelCode');

        if ($code) {
            $c = $this->channelRepository->findOneByCode($code);
            if ($c instanceof ChannelInterface) {
                return $c;
            }
        }

        $host = $request->getHost();
        if (method_exists($this->channelRepository, 'findOneByHostname')) {
            $c = $this->channelRepository->findOneByHostname($host);
            if ($c instanceof ChannelInterface) {
                return $c;
            }
        }

        return $this->channelRepository->findOneBy(['hostname' => $host]);
    }
}
