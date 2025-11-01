<?php

namespace App\Controller\Admin\Product\ProductImage;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface; // <-- CORRECT
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse; // <-- pense à l’importer aussi
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ChannelImagesController extends AbstractController
{
    public function __construct(
        private ChannelContextInterface $channelContext,
        private ChannelRepositoryInterface $channelRepository, // <-- typehint corrigé
        private ProductRepositoryInterface $productRepository,
    ) {}
    
    #[Route('/api/v2/admin/products/channel-images', name: 'admin_channel_images_get', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Optionnel : si route stateless sous /api/v2/admin, tu dois envoyer ton JWT admin
        // et ici la vérif fonctionnera :
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // 1) Déterminer le canal
        $channelCode =
            $request->query->get('channel') // ?channel=WEB_FR
            ?: $request->headers->get('X-Sylius-Channel-Code') // header
            ?: null;

        /** @var ChannelInterface|null $channel */
        $channel = null;

        if ($channelCode) {
            $channel = $this->channelRepository->findOneByCode($channelCode);
            if (null === $channel) {
                return new JsonResponse(['error' => sprintf('Unknown channel "%s"', $channelCode)], Response::HTTP_BAD_REQUEST);
            }
        } else {
            // Dernier recours : essayer le ChannelContext (ne marche que si ton host ou header est mappé)
            try {
                $channel = $this->channelContext->getChannel();
            } catch (\Throwable $e) {
                return new JsonResponse([
                    'error' => 'Channel not resolved. Pass ?channel=CODE or header X-Sylius-Channel-Code.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $channelCode = $channel->getCode();

        // ✅ Test rapide : renvoyer juste le canal résolu
        // (décommente le bloc de requête quand tu veux lister réellement les images)
        /*
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(200, max(10, (int) $request->query->get('per_page', 50)));
        $offset  = ($page - 1) * $perPage;

        $qb = $this->productRepository->createQueryBuilder('p')
            ->innerJoin('p.variants', 'v')
            ->innerJoin('v.channelPricings', 'cp')
            ->andWhere('cp.channelCode = :channelCode')
            ->setParameter('channelCode', $channelCode)
            ->leftJoin('p.images', 'i')->addSelect('i')
            ->andWhere('i.id IS NOT NULL')
            ->andWhere('p.enabled = true')
            ->groupBy('p.id, i.id')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        $products = $qb->getQuery()->getResult();

        $images = [];
        foreach ($products as $product) {
            foreach ($product->getImages() as $image) {
                $images[] = [
                    'productCode' => $product->getCode(),
                    'type'        => $image->getType(),
                    'path'        => $image->getPath(),
                    'id'          => $image->getId(),
                ];
            }
        }

        $countQb = $this->productRepository->createQueryBuilder('p')
            ->select('COUNT(DISTINCT i.id)')
            ->innerJoin('p.variants', 'v')
            ->innerJoin('v.channelPricings', 'cp')
            ->leftJoin('p.images', 'i')
            ->andWhere('cp.channelCode = :channelCode')
            ->andWhere('i.id IS NOT NULL')
            ->andWhere('p.enabled = true')
            ->setParameter('channelCode', $channelCode);

        $totalImages = (int) $countQb->getQuery()->getSingleScalarResult();
        */

        return new JsonResponse([
            'success' => true,
            'channel' => $channelCode,
            // 'images'  => $images,
            // 'total'   => $totalImages,
        ], Response::HTTP_OK);
    }
}
