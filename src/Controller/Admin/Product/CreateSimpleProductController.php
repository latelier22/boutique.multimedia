<?php

namespace App\Controller\Admin\Product;

use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response; // ✅ AJOUT ICI
use Symfony\Contracts\HttpClient\HttpClientInterface;

use Sylius\Component\Core\Model\ChannelInterface;

use Sylius\Component\Resource\Factory\FactoryInterface;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;

use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

use Sylius\Component\Product\Model\ProductTranslationInterface; // <= important

class CreateSimpleProductController extends AbstractController
{
    public function __construct(
        private FactoryInterface $productFactory,           // '@sylius.factory.product'
        private FactoryInterface $variantFactory,           // '@sylius.factory.product_variant'
        private FactoryInterface $channelPricingFactory,    // '@sylius.factory.channel_pricing'
        private FactoryInterface $productTaxonFactory,      // '@sylius.factory.product_taxon'  <<< AJOUT ICI
        private ProductRepositoryInterface $productRepository,
        private ProductVariantRepositoryInterface $variantRepository,
        private ChannelRepositoryInterface $channelRepository,
        private TaxonRepositoryInterface $taxonRepository,
        private HttpClientInterface $httpClient
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data   = json_decode($request->getContent(), true);
        $locale = $data['locale'] ?? 'fr_FR';

        foreach (['code','name','slug','channelCode','variantCode','price'] as $f) {
            if (empty($data[$f])) {
                return new JsonResponse(['error' => "Missing field: $f"], 400);
            }
        }

        $channel = $this->channelRepository->findOneByCode($data['channelCode']);
        if (!$channel) {
            return new JsonResponse(['error' => 'Channel not found'], 404);
        }

        /** @var ProductInterface $product */
        $product = $this->productFactory->createNew();
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->getTranslation($locale)->setName($data['name']);
        $product->getTranslation($locale)->setSlug($data['slug']);
        $product->setCode($data['code']);
        $product->setEnabled(true);
        $product->addChannel($channel);

        // Main taxon via FACTORY (retourne bien App\Entity\Product\ProductTaxon)
        if (!empty($data['mainTaxonCode'])) {
            /** @var TaxonInterface|null $taxon */
            $taxon = $this->taxonRepository->findOneBy(['code' => $data['mainTaxonCode']]);
            if (!$taxon) {
                return new JsonResponse(['error' => 'Main taxon not found'], 404);
            }

            $productTaxon = $this->productTaxonFactory->createNew();
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($taxon);
            $product->addProductTaxon($productTaxon);

            $product->setMainTaxon($taxon);
        }

        $this->productRepository->add($product);

        /** @var ProductVariantInterface $variant */
        $variant = $this->variantFactory->createNew();
        $variant->setCurrentLocale($locale);
        $variant->setFallbackLocale($locale);
        $variant->getTranslation($locale)->setName($data['variantName'] ?? $data['name']);
        $variant->setCode($data['variantCode']);
        $variant->setProduct($product);
        $variant->setOnHand($data['onHand'] ?? 100);
        $variant->setTracked(false);

        $pricing = $this->channelPricingFactory->createNew();
        $pricing->setChannelCode($channel->getCode());
        $pricing->setPrice((int) $data['price']);
        if (!empty($data['originalPrice'])) {
            $pricing->setOriginalPrice((int) $data['originalPrice']);
        }
        if (!empty($data['minimumPrice']))  {
            $pricing->setMinimumPrice((int) $data['minimumPrice']);
        }
        $variant->addChannelPricing($pricing);

        $this->variantRepository->add($variant);

        // Fetch interne optionnel
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return new JsonResponse([
                'status' => 'partial_success',
                'productCode' => $product->getCode(),
                'variantCode' => $variant->getCode(),
                'fetch_error' => ['status' => 400, 'body' => 'Missing Authorization header'],
            ], 201);
        }

        $baseUrl = $request->getSchemeAndHttpHost();
        $apiUrl  = sprintf('%s/api/v2/admin/products/%s', $baseUrl, $product->getCode());

        try {
            $response = $this->httpClient->request('GET', $apiUrl, [
                'headers' => [
                    'Authorization' => $authHeader,
                    'Accept'        => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                return new JsonResponse([
                    'status' => 'partial_success',
                    'productCode' => $product->getCode(),
                    'variantCode' => $variant->getCode(),
                    'fetch_error' => [
                        'status' => $response->getStatusCode(),
                        'body'   => $response->getContent(false),
                    ],
                ], 201);
            }

            return new JsonResponse([
                'status' => 'success',
                'productCode' => $product->getCode(),
                'variantCode' => $variant->getCode(),
                'product' => $response->toArray(),
            ], 201);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'status' => 'partial_success',
                'productCode' => $product->getCode(),
                'variantCode' => $variant->getCode(),
                'fetch_error' => [
                    'status' => 0,
                    'body'   => $e->getMessage(),
                ],
            ], 201);
        }
    }


    /**
     * Bascule l'état enabled d'un produit identifié par {code}.
     */
    #[Route('api/v2/admin/products/{code}/toggle-enable', name: 'api_admin_product_toggle_enable', methods: ['POST'])]
    public function toggleEnable(Request $request, string $code): JsonResponse
    {
        // (Optionnel) Vérif de droits admin
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->findOneByCode($code);
        if (!$product) {
            return new JsonResponse(['error' => 'Product not found', 'code' => $code], 404);
        }

        $newEnabled = !$product->isEnabled();
        $product->setEnabled($newEnabled);
        // Sylius repo add() = persist + flush
        $this->productRepository->add($product);

        return new JsonResponse([
            'status'   => 'success',
            'code'     => $code,
            'enabled'  => $newEnabled,
        ], 200);
    }

    /**
     * Batch toggle: body JSON { "codes": ["P001","P002", ...] }
     */
    #[Route('api/v2/admin/products/toggle-enable', name: 'api_admin_product_toggle_enable_batch', methods: ['POST'])]
    public function toggleEnableBatch(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data  = json_decode($request->getContent(), true) ?? [];
        $codes = $data['codes'] ?? null;

        if (!\is_array($codes) || empty($codes)) {
            return new JsonResponse(['error' => 'Missing "codes" array in body'], 400);
        }

        $results = [];
        $allOk   = true;

        foreach ($codes as $code) {
            $product = $this->productRepository->findOneByCode($code);
            if (!$product) {
                $results[$code] = ['ok' => false, 'error' => 'not_found'];
                $allOk = false;
                continue;
            }

            $newEnabled = !$product->isEnabled();
            $product->setEnabled($newEnabled);
            $this->productRepository->add($product);

            $results[$code] = ['ok' => true, 'enabled' => $newEnabled];
        }

        return new JsonResponse([
            'status'  => $allOk ? 'success' : 'partial_success',
            'results' => $results,
        ], 200);
    }


/**
     * Batch toggle: body JSON { "codes": ["P001","P002", ...] }
     */
    #[Route('api/v2/admin/test-products', name: 'api_admin_products_test', methods: ['GET'])]
    public function test(): JsonResponse
    {
        if (!$this->isGranted('ROLE_API_ACCESS')) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }


        return new JsonResponse([
            'status'  => $allOk ? 'success' : 'partial_success',
            'results' => 'SALUT',
        ], 200);
    }


    #[Route('/api/v2/admin/products', name: 'app_admin_products_list', methods: ['GET'])]
   public function list(Request $request): JsonResponse
{
    if (!$this->isGranted('ROLE_API_ACCESS')) {
        return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
    }

    // 1) Channel depuis hostname / header / ?channelCode=
    $channel = $this->resolveChannelFromRequest($request);
    if (!$channel) {
        return new JsonResponse([
            'error' => 'Channel non résolu (hostname inconnu). Passe X-Sylius-Channel-Code ou ?channelCode='
        ], Response::HTTP_BAD_REQUEST);
    }

    // 2) Pagination / options
    $limit  = (int)($request->query->get('itemsPerPage', 30));
    $limit  = max(1, min(500, $limit));
    $page   = max(1, (int)$request->query->get('page', 1));
    $offset = ($page - 1) * $limit;

    $locale = $request->headers->get('X-Sylius-Locale')
           ?: $request->query->get('locale', 'fr_FR');

    $enabledOnly = filter_var($request->query->get('enabledOnly', 'false'), FILTER_VALIDATE_BOOLEAN);

    // 3) Query produits rattachés au channel (optionnel: enabledOnly)
    $qb = $this->productRepository->createQueryBuilder('p')
        ->addSelect('t')
        ->leftJoin('p.translations', 't')
        ->innerJoin('p.channels', 'ch')
        ->andWhere('ch = :channel')
        ->setParameter('channel', $channel)
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->orderBy('p.code', 'ASC');

    if ($enabledOnly) {
        $qb->andWhere('p.enabled = true');
    }

    $rows = $qb->getQuery()->getResult(); // objets

    // 4) Total pour ce channel (pour hydra:view)
    $total = (int) $this->productRepository->createQueryBuilder('p')
        ->select('COUNT(p.id)')
        ->innerJoin('p.channels', 'ch')
        ->andWhere('ch = :channel')
        ->setParameter('channel', $channel)
        ->getQuery()->getSingleScalarResult();




$base = $request->getSchemeAndHttpHost();
$iriShop  = fn(string $path) => $path; // on met des IRIs en /api/v2/shop/... comme ton exemple
$iriAdmin = fn(string $path) => $path; // si tu préfères /api/v2/admin/ remplace ci-dessous

$items = array_map(function (\Sylius\Component\Core\Model\ProductInterface $p) use ($locale, $iriShop) {
    $tr = $p->getTranslations()->get($locale) ?? $p->getTranslations()->first();
    $name = $tr?->getName();
    $slug = $tr?->getSlug();

    // productTaxons -> IRIs shop
    $productTaxonsIris = [];
    foreach ($p->getProductTaxons() as $pt) {
        if (null !== $pt->getId()) {
            $productTaxonsIris[] = $iriShop(sprintf('/api/v2/shop/product-taxons/%d', $pt->getId()));
        }
    }

    // mainTaxon -> IRI shop par code
    $mainTaxonIri = null;
    if ($p->getMainTaxon()) {
        $mainTaxonIri = $iriShop(sprintf('/api/v2/shop/taxons/%s', $p->getMainTaxon()->getCode()));
    }

    // images -> objets comme shop
    $mediaPATH = "/media/image/";
    $images = [];
    foreach ($p->getImages() as $img) {
        if (null === $img->getId()) { continue; }
        $images[] = [
            '@id'   => $iriShop(sprintf('/api/v2/shop/product-images/%d', $img->getId())),
            '@type' => 'ProductImage',
            'id'    => $img->getId(),
            'type'  => $img->getType(),
            'path'  => $mediaPATH . $img->getPath(),
        ];
    }

    // variants -> IRIs shop par code
    $variantsIris = [];
    foreach ($p->getVariants() as $v) {
        if ($v->getCode()) {
            $variantsIris[] = $iriShop(sprintf('/api/v2/shop/product-variants/%s', $v->getCode()));
        }
    }

    // defaultVariant -> on prend le premier variant
    $defaultVariantIri = null;
    $firstVariant = $p->getVariants()->first() ?: null;
    if ($firstVariant && $firstVariant->getCode()) {
        $defaultVariantIri = $iriShop(sprintf('/api/v2/shop/product-variants/%s', $firstVariant->getCode()));
    }

    return [
        '@id'   => sprintf('/api/v2/admin/products/%s', $p->getCode()),
        '@type' => 'Product',

        // — Champs “shop” portés en admin —
        'productTaxons'    => $productTaxonsIris,
        'mainTaxon'        => $mainTaxonIri,
        'reviews'          => [], // simple pour coller à ton exemple
        'averageRating'    => (float) $p->getAverageRating(),
        'images'           => $images,
        'id'               => $p->getId(),
        'code'             => $p->getCode(),
        'variants'         => $variantsIris,
        'options'          => [], // à remplir si tu veux les vrais
        'associations'     => [], // idem
        'createdAt'        => $p->getCreatedAt()?->format('Y-m-d H:i:s'),
        'updatedAt'        => $p->getUpdatedAt()?->format('Y-m-d H:i:s'),
        'shortDescription' => $tr?->getShortDescription(),
        'name'             => $name,
        'description'      => $tr?->getDescription(),
        'slug'             => $slug,
        'defaultVariant'   => $defaultVariantIri,

        // — Spécifique admin que tu peux conserver —
        'enabled'          => $p->isEnabled(),
    ];
}, $rows);

    // 6) hydra:view (pagination)
    $collectionIri = $this->currentIri($request);
    $lastPage      = max(1, (int)ceil($total / $limit));
    $view = [
        '@id'   => $this->withPage($request, $page),
        '@type' => 'hydra:PartialCollectionView',
        'hydra:first' => $this->withPage($request, 1),
        'hydra:last'  => $this->withPage($request, $lastPage),
    ];
    if ($page > 1)            { $view['hydra:previous'] = $this->withPage($request, $page - 1); }
    if ($page < $lastPage)    { $view['hydra:next']     = $this->withPage($request, $page + 1); }

    // 7) hydra:search (gabarit de filtres dispo)
    $search = [
        '@type'     => 'hydra:IriTemplate',
        'hydra:template' => $collectionIri . '{?itemsPerPage,page,locale,enabledOnly,channelCode,order[code]}',
        'hydra:variableRepresentation' => 'BasicRepresentation',
        'hydra:mapping' => [
            ['@type'=>'IriTemplateMapping','variable'=>'itemsPerPage','property'=>null,'required'=>false],
            ['@type'=>'IriTemplateMapping','variable'=>'page','property'=>null,'required'=>false],
            ['@type'=>'IriTemplateMapping','variable'=>'locale','property'=>null,'required'=>false],
            ['@type'=>'IriTemplateMapping','variable'=>'enabledOnly','property'=>null,'required'=>false],
            ['@type'=>'IriTemplateMapping','variable'=>'channelCode','property'=>null,'required'=>false],
            ['@type'=>'IriTemplateMapping','variable'=>'order[code]','property'=>'code','required'=>false],
        ],
    ];

    $payload = [
        '@context'       => '/api/v2/contexts/Product',
        '@id'            => $view['@id'],
        '@type'          => 'hydra:Collection',
        'hydra:member'   => $items,
        'hydra:totalItems' => $total,
        'hydra:view'     => $view,
        'hydra:search'   => $search,
    ];

    $response = new JsonResponse($payload, Response::HTTP_OK);
    $response->headers->set('Content-Type', 'application/ld+json'); // pour matcher l’API
    return $response;
}


#[Route(
    path: '/api/v2/admin/products/{code}',
    name: 'app_admin_product_show',
    methods: ['GET'],
    priority: 1000 // ✅ pour passer AVANT la route ApiPlatform
)]
public function show(Request $request, string $code): JsonResponse
{
    if (!$this->isGranted('ROLE_API_ACCESS')) {
        return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
    }

    // 1) Channel depuis hostname / header / ?channelCode=
    $channel = $this->resolveChannelFromRequest($request);
    if (!$channel) {
        return new JsonResponse([
            'error' => 'Channel non résolu (hostname inconnu). Passe X-Sylius-Channel-Code ou ?channelCode='
        ], Response::HTTP_BAD_REQUEST);
    }

    // 2) Locale demandée
    $locale = $request->headers->get('X-Sylius-Locale')
           ?: $request->query->get('locale', 'fr_FR');

    // 3) Produit par code
    /** @var ProductInterface|null $p */
    $p = $this->productRepository->findOneByCode($code);
    if (!$p) {
        return new JsonResponse(['error' => 'Product not found', 'code' => $code], Response::HTTP_NOT_FOUND);
    }

    // 4) Vérifier l’appartenance au channel (on autorise disabled)
    if (!$p->getChannels()->contains($channel)) {
        return new JsonResponse([
            'error' => 'Product not available in this channel',
            'code'  => $code,
            'channel' => $channel->getCode()
        ], Response::HTTP_NOT_FOUND);
    }

    // 5) Build JSON façon “admin”
    $tr = $p->getTranslations()->get($locale) ?? $p->getTranslations()->first();
    $mediaPATH = "/media/image/";

    // productTaxons (IRIs admin)
    $productTaxonsIris = [];
    foreach ($p->getProductTaxons() as $pt) {
        if (null !== $pt->getId()) {
            $productTaxonsIris[] = sprintf('/api/v2/admin/product-taxons/%d', $pt->getId());
        }
    }

    // mainTaxon (IRI admin par code)
    $mainTaxonIri = null;
    if ($p->getMainTaxon()) {
        $mainTaxonIri = sprintf('/api/v2/admin/taxons/%s', $p->getMainTaxon()->getCode());
    }

    // images (objets admin)
    $images = [];
    foreach ($p->getImages() as $img) {
        if (null === $img->getId()) { continue; }
        $images[] = [
            '@id'   => sprintf('/api/v2/admin/product-images/%d', $img->getId()),
            '@type' => 'ProductImage',
            'id'    => $img->getId(),
            'type'  => $img->getType(),
            'path'  => $mediaPATH . $img->getPath(),
        ];
    }

    // variants (IRIs admin)
    $variantsIris = [];
    foreach ($p->getVariants() as $v) {
        if ($v->getCode()) {
            $variantsIris[] = sprintf('/api/v2/admin/product-variants/%s', $v->getCode());
        }
    }

    $translations = [];
foreach ($p->getTranslations() as $t) {
    if (!$t instanceof ProductTranslationInterface) {
        continue;
    }
    $loc = $t->getLocale() ?: $locale; // sécurité si locale absente
    $translations[$loc] = [
        '@id'   => $t->getId() ? sprintf('/api/v2/admin/product-translations/%d', $t->getId()) : null,
        '@type' => 'ProductTranslation',
        'id'    => $t->getId(),
        'name'  => $t->getName(),
        'slug'  => $t->getSlug(),
        'shortDescription' => $t->getShortDescription(),
        'description'      => $t->getDescription(),
        'metaKeywords'     => $t->getMetaKeywords(),
        'metaDescription'  => $t->getMetaDescription(),
    ];
}

    $payload = [
        '@context' => '/api/v2/contexts/Product',
        '@id'      => sprintf('/api/v2/admin/products/%s', $p->getCode()),
        '@type'    => 'Product',

        'productTaxons' => $productTaxonsIris,
        'mainTaxon'     => $mainTaxonIri,
        'reviews'       => [],

        'images'        => $images,
        'id'            => $p->getId(),
        'code'          => $p->getCode(),
        'enabled'       => $p->isEnabled(),
        'variants'      => $variantsIris,
        'options'       => [],

        // Dates comme en /shop (pas ISO)
        'createdAt'     => $p->getCreatedAt()?->format('Y-m-d H:i:s'),
        'updatedAt'     => $p->getUpdatedAt()?->format('Y-m-d H:i:s'),

        'translations'  => $translations,
    ];

    $resp = new JsonResponse($payload, Response::HTTP_OK);
    $resp->headers->set('Content-Type', 'application/ld+json');
    return $resp;
}





#[Route(
    path: '/api/v2/admin/products/{code}',
    name: 'app_admin_product_patch',
    methods: ['PATCH']
)]
public function patch(Request $request, string $code): JsonResponse
{
    if (!$this->isGranted('ROLE_API_ACCESS')) {
        return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
    }

    /** @var ProductInterface|null $product */
    $product = $this->productRepository->findOneByCode($code);
    if (!$product) {
        return new JsonResponse(['error' => 'Product not found', 'code' => $code], Response::HTTP_NOT_FOUND);
    }

    $data = json_decode($request->getContent(), true);
    if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    // Exemple de champs qu’on peut mettre à jour
    if (isset($data['enabled'])) {
        $product->setEnabled((bool) $data['enabled']);
    }

    if (isset($data['translations']) && is_array($data['translations'])) {
        foreach ($data['translations'] as $locale => $tr) {
            $translation = $product->getTranslation($locale);
            if (isset($tr['name'])) {
                $translation->setName($tr['name']);
            }
            if (isset($tr['slug'])) {
                $translation->setSlug($tr['slug']);
            }
            if (isset($tr['description'])) {
                $translation->setDescription($tr['description']);
            }
            if (isset($tr['shortDescription'])) {
                $translation->setShortDescription($tr['shortDescription']);
            }
        }
    }

    if (isset($data['mainTaxon'])) {
        $codeTaxon = basename($data['mainTaxon']); // ex: "/api/v2/admin/taxons/MONTRES" → "MONTRES"
        $taxon = $this->taxonRepository->findOneBy(['code' => $codeTaxon]);
        if ($taxon) {
            $product->setMainTaxon($taxon);
        }
    }

    $this->productRepository->add($product);

    return new JsonResponse([
        'status' => 'success',
        'code'   => $product->getCode(),
        'enabled'=> $product->isEnabled(),
    ], Response::HTTP_OK);
}













// ===== Helpers =====

// IRI courant sans modifier les autres query params
private function currentIri(Request $request): string
{
    return $request->getSchemeAndHttpHost() . $request->getPathInfo();
}
private function withPage(Request $request, int $page): string
{
    $q = $request->query->all();
    $q['page'] = $page;
    return $this->currentIri($request) . (empty($q) ? '' : '?' . http_build_query($q));
}



    private function resolveChannelFromRequest(Request $request): ?ChannelInterface
    {
        // 1) Header prioritaire
        $code = $request->headers->get('X-Sylius-Channel-Code')
             ?: $request->query->get('channelCode');
        if ($code) {
            return $this->channelRepository->findOneByCode($code);
        }

        // 2) Déduction par hostname (ce que tu veux “normalement”)
        $host = $request->getHost();

        // Si le repo a une méthode dédiée
        if (method_exists($this->channelRepository, 'findOneByHostname')) {
            $ch = $this->channelRepository->findOneByHostname($host);
            if ($ch) return $ch;
        }

        // Fallback générique par propriété
        return $this->channelRepository->findOneBy(['hostname' => $host]);
    }




#[Route(
    path: '/products/check-slug',
    name: 'app_admin_product_check_slug',
    methods: ['GET']
)]
public function checkSlug(Request $request): JsonResponse
{
    

    $slug   = $request->query->get('slug');
    $locale = $request->query->get('locale', 'fr_FR');
    $exclude = $request->query->get('exclude'); // code à exclure

    if (!$slug) {
        return new JsonResponse(['error' => 'Missing slug parameter'], 400);
    }

    // Vérifie dans les traductions produit
    $qb = $this->productRepository->createQueryBuilder('p')
        ->leftJoin('p.translations', 't')
        ->andWhere('t.slug = :slug')
        ->andWhere('t.locale = :locale')
        ->setParameter('slug', $slug)
        ->setParameter('locale', $locale);

    if ($exclude) {
        $qb->andWhere('p.code != :exclude')->setParameter('exclude', $exclude);
    }


    $exists = (bool) $qb->getQuery()->getOneOrNullResult();

    return new JsonResponse([
        'slug'   => $slug,
        'locale' => $locale,
        'exists' => $exists,
        'message' => 'Slug ' . ($exists ? 'already exists' : 'is available')
        ]);
}






}





