<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat\RachatDossier;
use App\Service\HiboutikClient;
use App\Service\PdfThumbnailService;
use App\Service\Rachat\RachatDossierCustomerResolver;
use App\Service\Rachat\RachatLegacyConverter;
use App\Service\Rachat\RachatDossierManager;
use App\Service\Rachat\RachatDossierPdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Form\Rachat\RachatDossierType;

use App\Service\Rachat\RachatDossierCiManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use Doctrine\ORM\Tools\Pagination\Paginator;

use App\Entity\Rachat\RachatItem;
use App\Service\Rachat\RachatMediaManager;
use App\Service\Rachat\RachatDossierSignatureManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Cache\CacheItemPoolInterface;

use App\Service\HiboutikReferentialService;
use App\Service\Rachat\RachatDossierFinalizeService;


#[Route('/admin/rachats-v2', name: 'admin_rachats_v2_')]
final class RachatDossierController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PdfThumbnailService $pdfThumbnailService,
        private RachatDossierManager $manager,
        private HiboutikClient $hiboutikClient,
        private RachatDossierCiManager $ciManager,
        private RachatDossierPdfGenerator $pdfGenerator,
        private CacheInterface $cache,
        private HiboutikReferentialService $hibReferential,
    ) {
    }

#[Route('/', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
    $customerFilter = trim((string) $request->query->get('customer_filter', 'without_virtual'));
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = 25;

    $baseQb = $this->em
        ->getRepository(RachatDossier::class)
        ->createQueryBuilder('d')
        ->orderBy('d.reference', 'DESC')
        ->addOrderBy('d.id', 'DESC');

    switch ($customerFilter) {
        case 'no_client':
            $baseQb->andWhere('COALESCE(d.hibCustomerId, 0) = 0');
            break;

        case 'real_client':
            $baseQb->andWhere('COALESCE(d.hibCustomerId, 0) > 0')
                ->andWhere('(d.customerLinkStatus IS NULL OR d.customerLinkStatus != :virtualStatus)')
                ->setParameter('virtualStatus', RachatDossier::CUSTOMER_VIRTUAL);
            break;

        case 'virtual_client':
            $baseQb->andWhere('d.customerLinkStatus = :virtualStatus')
                ->setParameter('virtualStatus', RachatDossier::CUSTOMER_VIRTUAL);
            break;

        case 'all':
            break;

        case 'without_virtual':
        default:
            $customerFilter = 'without_virtual';
            $baseQb->andWhere('(d.customerLinkStatus IS NULL OR d.customerLinkStatus != :virtualStatus)')
                ->setParameter('virtualStatus', RachatDossier::CUSTOMER_VIRTUAL);
            break;
    }

    $countQb = clone $baseQb;
    $totalCount = (int) $countQb
        ->resetDQLPart('orderBy')
        ->resetDQLPart('select')
        ->select('COUNT(d.id)')
        ->getQuery()
        ->getSingleScalarResult();

    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $idsQb = clone $baseQb;
    $idRows = $idsQb
        ->resetDQLPart('select')
        ->select('d.id AS id')
        ->setFirstResult($offset)
        ->setMaxResults($perPage)
        ->getQuery()
        ->getScalarResult();

    $pageIds = array_map(static fn(array $row) => (int) $row['id'], $idRows);

    $dossiers = [];

    if ($pageIds) {
        $rows = $this->em
            ->getRepository(RachatDossier::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.items', 'i')
            ->addSelect('i')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', $pageIds)
            ->orderBy('d.reference', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $dossier) {
            $indexed[$dossier->getId()] = $dossier;
        }

        foreach ($pageIds as $id) {
            if (isset($indexed[$id])) {
                $dossiers[] = $indexed[$id];
            }
        }
    }

    return $this->render('@SyliusAdmin/Rachat/RachatDossier/index.html.twig', [
        'dossiers' => $dossiers,
        'customerFilter' => $customerFilter,
        'virtualStatus' => RachatDossier::CUSTOMER_VIRTUAL,
        'page' => $page,
        'perPage' => $perPage,
        'totalCount' => $totalCount,
        'totalPages' => $totalPages,
    ]);
}

#[Route('/{id}/details-row', name: 'details_row', requirements: ['id' => '\d+'], methods: ['GET'])]
public function detailsRow(int $id): Response
{
    $dossier = $this->em
        ->getRepository(RachatDossier::class)
        ->createQueryBuilder('d')
        ->leftJoin('d.items', 'i')
        ->addSelect('i')
        ->where('d.id = :id')
        ->setParameter('id', $id)
        ->getQuery()
        ->getOneOrNullResult();

    if (!$dossier) {
        return new Response('<div class="ui negative message">Dossier introuvable.</div>', 404);
    }

    return new Response($this->renderView(
        '@SyliusAdmin/Rachat/RachatDossier/_details_row.html.twig',
        [
            'dossier' => $dossier,
        ]
    ));
}

    #[Route('/bulk/resync', name: 'bulk_resync', methods: ['POST'])]
    public function bulkResync(
        Request $request,
        RachatLegacyConverter $converter
    ): Response {
        if (!$this->isCsrfTokenValid('bulk_rachats_v2', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->request->all('ids')
        ))));

        if (!$ids) {
            $this->addFlash('error', 'Aucun dossier sélectionné.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $dossiers = $this->em->getRepository(RachatDossier::class)->findBy(['id' => $ids]);

        $done = 0;
        $errors = [];

        foreach ($dossiers as $dossier) {
            try {
                $converter->resyncDossier($dossier, false);
                $done++;
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Dossier #%d : %s',
                    $dossier->getId(),
                    $e->getMessage()
                );
            }
        }

        $this->em->flush();

        if ($done > 0) {
            $this->addFlash('success', sprintf('%d dossier(s) resynchronisé(s).', $done));
        }

        foreach (array_slice($errors, 0, 5) as $msg) {
            $this->addFlash('error', $msg);
        }

        if (count($errors) > 5) {
            $this->addFlash('error', sprintf('%d autres erreurs non affichées.', count($errors) - 5));
        }

        return $this->redirectToRoute('admin_rachats_v2_index');
    }

    #[Route('/{id}/resync', name: 'resync_one', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resyncOne(
        int $id,
        Request $request,
        RachatLegacyConverter $converter
    ): Response {
        if (!$this->isCsrfTokenValid('resync_dossier_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        try {
            $converter->resyncDossier($dossier, true);
            $this->addFlash('success', sprintf('Dossier #%d resynchronisé.', $dossier->getId()));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Resync impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rachats_v2_index');
    }

    #[Route('/{id}/display', name: 'display', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function display(int $id): Response
    {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        return $this->render('@SyliusAdmin/Rachat/RachatDossier/display.html.twig', [
            'dossier' => $dossier,
        ]);
    }

    #[Route('/{id}/customer/search', name: 'customer_search', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function searchCustomer(
        int $id,
        RachatDossierCustomerResolver $resolver
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        $candidates = $resolver->searchCandidates($dossier);

        return $this->render('@SyliusAdmin/Rachat/RachatDossier/customer_search.html.twig', [
            'dossier' => $dossier,
            'candidates' => $candidates,
        ]);
    }

    #[Route('/{id}/customer/create', name: 'customer_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createCustomer(
        int $id,
        RachatDossierCustomerResolver $resolver
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        try {
            $customerId = $resolver->createCustomerFromDossier($dossier);

            $this->addFlash('success', sprintf(
                'Client Hiboutik #%d créé et affecté au dossier.',
                $customerId
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Création client impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rachats_v2_show', [
            'id' => $dossier->getId(),
        ]);
    }

    #[Route('/{id}/customer/assign-virtual', name: 'customer_assign_virtual', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignVirtualCustomer(
        int $id,
        RachatDossierCustomerResolver $resolver
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        try {
            $customerId = $resolver->assignVirtualCustomer($dossier);

            $this->addFlash('success', sprintf(
                'Client virtuel Hiboutik #%d affecté.',
                $customerId
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Affectation du client virtuel impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rachats_v2_show', [
            'id' => $dossier->getId(),
        ]);
    }

    #[Route('/{id}/customer/assign/{customerId}', name: 'customer_assign', requirements: ['id' => '\d+', 'customerId' => '\d+'], methods: ['POST'])]
    public function assignCustomer(
        int $id,
        int $customerId,
        RachatDossierCustomerResolver $resolver
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        try {
            $resolver->assignExistingCustomer($dossier, $customerId);
            $this->addFlash('success', 'Client Hiboutik affecté au dossier.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Affectation impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rachats_v2_show', [
            'id' => $dossier->getId(),
        ]);
    }

    #[Route('/bulk/assign-virtual', name: 'bulk_assign_virtual', methods: ['POST'])]
    public function bulkAssignVirtual(
        Request $request,
        RachatDossierCustomerResolver $resolver
    ): Response {
        if (!$this->isCsrfTokenValid('bulk_rachats_v2', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->request->all('ids')
        ))));

        if (!$ids) {
            $this->addFlash('error', 'Aucun dossier sélectionné.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $dossiers = $this->em->getRepository(RachatDossier::class)->findBy(['id' => $ids]);

        $done = 0;
        $already = 0;
        $errors = [];

        foreach ($dossiers as $dossier) {
            try {
                if ($dossier->getHibCustomerId()) {
                    $already++;
                    continue;
                }

                $resolver->assignVirtualCustomer($dossier);
                $done++;
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Dossier #%d : %s',
                    $dossier->getId(),
                    $e->getMessage()
                );
            }
        }

        if ($done > 0) {
            $this->addFlash('success', sprintf('%d dossier(s) affecté(s) au client virtuel.', $done));
        }

        if ($already > 0) {
            $this->addFlash('info', sprintf('%d dossier(s) avaient déjà un client.', $already));
        }

        foreach (array_slice($errors, 0, 5) as $msg) {
            $this->addFlash('error', $msg);
        }

        if (count($errors) > 5) {
            $this->addFlash('error', sprintf('%d autres erreurs non affichées.', count($errors) - 5));
        }

        return $this->redirectToRoute('admin_rachats_v2_index');
    }

    #[Route('/bulk/create-customers', name: 'bulk_create_customers', methods: ['POST'])]
    public function bulkCreateCustomers(
        Request $request,
        RachatDossierCustomerResolver $resolver
    ): Response {
        if (!$this->isCsrfTokenValid('bulk_rachats_v2', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->request->all('ids')
        ))));

        if (!$ids) {
            $this->addFlash('error', 'Aucun dossier sélectionné.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $dossiers = $this->em->getRepository(RachatDossier::class)->findBy(['id' => $ids]);

        $created = 0;
        $assigned = 0;
        $already = 0;
        $errors = [];

        foreach ($dossiers as $dossier) {
            try {
                if ($dossier->getHibCustomerId()) {
                    $already++;
                    continue;
                }

                $result = $resolver->assignByPhoneOrCreate($dossier);

                if (($result['mode'] ?? '') === 'assigned') {
                    $assigned++;
                } elseif (($result['mode'] ?? '') === 'created') {
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Dossier #%d : %s',
                    $dossier->getId(),
                    $e->getMessage()
                );
            }
        }

        if ($assigned > 0) {
            $this->addFlash('success', sprintf(
                '%d dossier(s) affecté(s) à un client existant grâce au téléphone.',
                $assigned
            ));
        }

        if ($created > 0) {
            $this->addFlash('success', sprintf(
                '%d client(s) Hiboutik créé(s).',
                $created
            ));
        }

        if ($already > 0) {
            $this->addFlash('info', sprintf(
                '%d dossier(s) avaient déjà un client.',
                $already
            ));
        }

        foreach (array_slice($errors, 0, 5) as $msg) {
            $this->addFlash('error', $msg);
        }

        if (count($errors) > 5) {
            $this->addFlash('error', sprintf('%d autres erreurs non affichées.', count($errors) - 5));
        }

        return $this->redirectToRoute('admin_rachats_v2_index');
    }

    #[Route('/{id}/customer/show', name: 'customer_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showCustomer(
        int $id,
        HiboutikClient $hib
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        if (!$dossier->getHibCustomerId()) {
            $this->addFlash('error', 'Aucun client Hiboutik affecté à ce dossier.');
            return $this->redirectToRoute('admin_rachats_v2_index');
        }

        $customer = $hib->getCustomer((int) $dossier->getHibCustomerId());

        return $this->render('@SyliusAdmin/Rachat/RachatDossier/customer_show.html.twig', [
            'dossier' => $dossier,
            'customer' => $customer,
        ]);
    }

    #[Route('/{id}/customer/partial', name: 'customer_partial', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function customerPartial(
        int $id,
        HiboutikClient $hib
    ): Response {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            return new Response('<div class="ui negative message">Dossier introuvable.</div>', 404);
        }

        $customer = null;

        if ($dossier->getHibCustomerId()) {
            try {
                $customer = $hib->getCustomer((int) $dossier->getHibCustomerId());
            } catch (\Throwable $e) {
                return new Response(
                    '<div class="ui negative message">Impossible de charger le client Hiboutik : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</div>',
                    500
                );
            }
        }

        return new Response($this->renderView(
            '@SyliusAdmin/Rachat/RachatDossier/_customer_partial.html.twig',
            [
                'dossier' => $dossier,
                'customer' => $customer,
            ]
        ));
    }

    #[Route('/{id}/pdf-thumb', name: 'pdf_thumb', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdfThumb(int $id): Response
    {
        $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

        if (!$dossier) {
            return new Response('', 404);
        }

        if (!$dossier->getPdfUrl()) {
            return new Response('', 404);
        }

        try {
            $thumbPath = $this->pdfThumbnailService->getThumbnailPathFromUrl(
                $dossier->getPdfUrl(),
                'rachat_dossier_' . $dossier->getId(),
                240
            );
        } catch (\Throwable $e) {
            return new Response('Impossible de générer la miniature PDF : ' . $e->getMessage(), 500);
        }

        if (!$thumbPath || !is_file($thumbPath)) {
            return new Response('', 404);
        }

        return new BinaryFileResponse($thumbPath, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }




    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dossier = $this->manager->createDraft();

        return $this->handleForm($request, $dossier, true);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, RachatDossier $dossier): Response
    {
        return $this->handleForm($request, $dossier, false);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(RachatDossier $dossier): Response
    {
        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }

private function handleForm(Request $request, RachatDossier $dossier, bool $isNew): Response
{
    if (!$isNew && $dossier->isLocked() && $request->isMethod('POST')) {
        $this->addFlash('error', 'Ce dossier est verrouillé.');
        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }
   if (!$dossier->getPaidMethod()) {
    $dossier->setPaidMethod('ESP');
}
    $brands = $this->hibReferential->getBrandsRows();
$categoryMeta = $this->hibReferential->buildCategoryChoicesAndDisabled();

$form = $this->createForm(RachatDossierType::class, $dossier, [
    'brands_choices' => $this->hibReferential->buildBrandChoices(),
    'categories_choices' => $categoryMeta['choices'],
    'categories_disabled' => $categoryMeta['disabled'],
]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->applyPostedItemBrands($request, $form);

        $this->manager->prepareForSave($dossier);

        $this->em->persist($dossier);
        $this->em->flush();

        $this->manager->generateReferenceIfNeeded($dossier);
        $this->em->flush();

        $this->addFlash('success', $isNew ? 'Dossier V2 créé.' : 'Dossier V2 mis à jour.');

        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }

    return $this->render('@SyliusAdmin/Rachat/RachatDossier/edit.html.twig', [
        'form' => $form->createView(),
        'dossier' => $dossier,
        'pieceIdentite' => $this->ciManager->decode($dossier->getPieceIdentiteUrl()),
        'brands' => $brands,
    ]);
}


 private function buildBrandChoices(): array
{
    return $this->cache->get('rachat_v2_hib_brands_choices', function (ItemInterface $item) {
        $item->expiresAfter(3600);

        $rows = [];

        try {
            if (method_exists($this->hiboutikClient, 'getBrands')) {
                $rows = $this->hiboutikClient->getBrands();
            }
        } catch (\Throwable) {
            $rows = [];
        }

        $choices = [];

        foreach ($rows as $row) {
            $id = $row['brand_id'] ?? $row['id'] ?? null;
            if (!$id) {
                continue;
            }

            $label = trim((string) ($row['brand_name'] ?? $row['name'] ?? ('Marque #' . $id)));
            $choices[$label] = (string) $id;
        }

        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    });
}

private function buildCategoryChoices(): array
{
    return $this->cache->get('rachat_v2_hib_categories_choices', function (ItemInterface $item) {
        $item->expiresAfter(3600);

        $rows = [];

        try {
            if (method_exists($this->hiboutikClient, 'getCategories')) {
                $rows = $this->hiboutikClient->getCategories();
            }
        } catch (\Throwable) {
            $rows = [];
        }

        $choices = [];

        foreach ($rows as $row) {
            $id = $row['category_id'] ?? $row['id'] ?? null;
            if (!$id) {
                continue;
            }

            $label = trim((string) ($row['category_name'] ?? $row['name'] ?? ('Catégorie #' . $id)));
            $choices[$label] = (string) $id;
        }

        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    });
}


    #[Route('/{id}/upload-ci', name: 'upload_ci', requirements: ['id' => '\d+'], methods: ['POST'])]
public function uploadCi(Request $request, RachatDossier $dossier): JsonResponse
{
    $token = (string) $request->request->get('_token', '');

    if (!$this->isCsrfTokenValid('rachat_dossier_ci_' . $dossier->getId(), $token)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Jeton CSRF invalide.',
        ], 403);
    }

    $kind = (string) $request->request->get('kind', '');
    if (!in_array($kind, ['recto', 'verso'], true)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Type invalide.',
        ], 400);
    }

    $file = $request->files->get('ci');
    if (!$file instanceof UploadedFile) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Fichier manquant.',
        ], 400);
    }

    try {
        $url = $this->ciManager->storeUploadedCi($dossier, $file, $kind);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'kind' => $kind,
            'url' => $url,
            'pieceIdentite' => $this->ciManager->decode($dossier->getPieceIdentiteUrl()),
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

#[Route('/{id}/lock', name: 'lock', requirements: ['id' => '\d+'], methods: ['POST'])]
public function lock(Request $request, RachatDossier $dossier): Response
{
    if (!$this->isCsrfTokenValid('lock_dossier_' . $dossier->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
    }

    if ($dossier->isLocked()) {
        $this->addFlash('warning', 'Ce dossier est déjà verrouillé.');

        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }

    $this->manager->lock($dossier);
    $this->em->flush();

    $this->addFlash('success', 'Le dossier a été verrouillé.');

    return $this->redirectToRoute('admin_rachats_v2_edit', [
        'id' => $dossier->getId(),
    ]);
}
#[Route('/{id}/unlock', name: 'unlock', requirements: ['id' => '\d+'], methods: ['POST'])]
public function unlock(Request $request, RachatDossier $dossier): Response
{
    if (!$this->isCsrfTokenValid('unlock_dossier_' . $dossier->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
    }

    if (!$dossier->isLocked()) {
        $this->addFlash('warning', 'Ce dossier n’est pas verrouillé.');

        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }

    $this->manager->unlock($dossier);
    $this->em->flush();

    $this->addFlash('success', 'Le dossier a été déverrouillé.');

    return $this->redirectToRoute('admin_rachats_v2_edit', [
        'id' => $dossier->getId(),
    ]);
}

#[Route('/{id}/generate-pdf', name: 'generate_pdf', requirements: ['id' => '\d+'], methods: ['POST'])]
public function generatePdf(Request $request, RachatDossier $dossier): Response
{
    if (!$this->isCsrfTokenValid('generate_pdf_dossier_' . $dossier->getId(), (string) $request->request->get('_token'))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
    }

    $shop = [
        'name' => 'Multimédia Services & Cash',
        'address' => 'À compléter',
        'zip' => '00000',
        'city' => 'À compléter',
        'country' => 'France',
        'phone' => 'À compléter',
        'email' => 'À compléter',
        'site' => 'À compléter',
        'tax_number' => '',
        'company_number' => '',
        'legal_status' => '',
        'code_naf' => '',
        'non_assujetti_tva' => '0',
    ];

    $result = $this->pdfGenerator->generate($dossier, [
    'shop' => $shop,
    'generated_at' => new \DateTimeImmutable(),
]);

$dossier->setPdfUrl($result['url']);
$this->em->flush();

$this->addFlash('success', 'PDF généré.');

return $this->redirectToRoute('admin_rachats_v2_pdf_preview', [
    'id' => $dossier->getId(),
]);
}
#[Route('/{id}/pdf-preview', name: 'pdf_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
public function pdfPreview(Request $request, RachatDossier $dossier): Response
{
    if (!$dossier->getPdfUrl()) {
        $this->addFlash('error', 'Aucun PDF généré pour ce dossier.');
        return $this->redirectToRoute('admin_rachats_v2_edit', [
            'id' => $dossier->getId(),
        ]);
    }

    $pageCount = $this->pdfThumbnailService->getPageCountFromUrl($dossier->getPdfUrl());
    $page = max(1, min($pageCount > 0 ? $pageCount : 1, (int) $request->query->get('page', 1)));

    return $this->render('@SyliusAdmin/Rachat/RachatDossier/pdf_preview.html.twig', [
        'dossier' => $dossier,
        'pageCount' => $pageCount,
        'currentPage' => $page,
        'tabletSignUrl' => $this->buildTabletSignDossierUrl($dossier),
        'tablet_socket_token' => (string) $this->getParameter('tablet_socket_token'),
            
    ]);
}

#[Route('/{id}/pdf-thumb/{page}', name: 'pdf_thumb_page', requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
public function pdfThumbPage(int $id, int $page): Response
{
    $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

    if (!$dossier || !$dossier->getPdfUrl()) {
        return new Response('', 404);
    }

    try {
        $thumbPath = $this->pdfThumbnailService->getThumbnailPathFromUrlAndPage(
            $dossier->getPdfUrl(),
            'rachat_dossier_' . $dossier->getId(),
            $page,
            260
        );
    } catch (\Throwable $e) {
        return new Response('Impossible de générer la miniature PDF : ' . $e->getMessage(), 500);
    }

    if (!$thumbPath || !is_file($thumbPath)) {
        return new Response('', 404);
    }

    return new BinaryFileResponse($thumbPath, 200, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

#[Route('/{id}/pdf-preview-image/{page}', name: 'pdf_preview_image', requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
public function pdfPreviewImage(int $id, int $page): Response
{
    $dossier = $this->em->getRepository(RachatDossier::class)->find($id);

    if (!$dossier || !$dossier->getPdfUrl()) {
        return new Response('', 404);
    }

    try {
        $thumbPath = $this->pdfThumbnailService->getThumbnailPathFromUrlAndPage(
            $dossier->getPdfUrl(),
            'rachat_dossier_' . $dossier->getId() . '_preview',
            $page,
            1400
        );
    } catch (\Throwable $e) {
        return new Response('Impossible de générer l’aperçu PDF : ' . $e->getMessage(), 500);
    }

    if (!$thumbPath || !is_file($thumbPath)) {
        return new Response('', 404);
    }

    return new BinaryFileResponse($thumbPath, 200, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

#[Route('/item/{id}/upload-photo', name: 'item_upload_photo', requirements: ['id' => '\d+'], methods: ['POST'])]
public function uploadItemPhoto(
    Request $request,
    RachatItem $item,
    RachatMediaManager $mediaManager
): JsonResponse {
    $token = (string) $request->request->get('_token', '');

    if (!$this->isCsrfTokenValid('rachat_item_photo_' . $item->getId(), $token)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Jeton CSRF invalide.',
        ], 403);
    }

    $slot = (string) $request->request->get('slot', '');
    if (!in_array($slot, ['photo1', 'photo2', 'photo3'], true)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Slot photo invalide.',
        ], 400);
    }

    $file = $request->files->get('file');
    if (!$file instanceof UploadedFile) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Fichier manquant.',
        ], 400);
    }

    try {
        $url = $mediaManager->storeItemPhoto($item, $file, $slot);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'slot' => $slot,
            'url' => $url,
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

#[Route('/{id}/upload-signature', name: 'upload_signature', requirements: ['id' => '\d+'], methods: ['POST'])]
public function uploadSignature(
    Request $request,
    RachatDossier $dossier,
    RachatDossierFinalizeService $finalizer
): JsonResponse {
    $token = (string) $request->request->get('_token', '');

    if (!$this->isCsrfTokenValid('rachat_dossier_signature_' . $dossier->getId(), $token)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Jeton CSRF invalide.',
        ], 403);
    }

    $dataUrl = (string) $request->request->get('signature', '');
    if ($dataUrl === '') {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Signature manquante.',
        ], 400);
    }

    $accepted = (string) $request->request->get('accept_rachat_conditions', '0') === '1';

    try {
        $result = $finalizer->finalizeFromTabletSignature($dossier, $dataUrl, $accepted);

        return new JsonResponse([
            'ok' => true,
            'url' => $result['signature_url'] ?? null,
            'pdfUrl' => $result['pdf_url'] ?? null,
            'redirect' => $this->generateUrl('admin_rachats_v2_pdf_preview', [
                'id' => $dossier->getId(),
            ]),
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

#[Route('/{id}/sign', name: 'sign', requirements: ['id' => '\d+'], methods: ['GET'])]
public function sign(RachatDossier $dossier): Response
{
    return $this->render('@SyliusAdmin/Rachat/RachatDossier/sign.html.twig', [
        'dossier' => $dossier,
    ]);
}


private function buildTabletSignDossierUrl(RachatDossier $dossier): string
{
    $token = bin2hex(random_bytes(16));

    $item = $this->cache->getItem('tablet_sign_dossier_' . $dossier->getId() . '_' . $token);
    $item->set(1);
    $item->expiresAfter(900);
    $this->cache->save($item);

    return $this->generateUrl('tablet_sign_dossier', [
        'id' => $dossier->getId(),
        'token' => $token,
    ], UrlGeneratorInterface::ABSOLUTE_URL);
}

#[Route('/{id}/tablet-link', name: 'tablet_link', requirements: ['id' => '\d+'], methods: ['POST'])]
public function tabletLink(int $id, CacheItemPoolInterface $cache): JsonResponse
{
    $dossier = $this->em->getRepository(RachatDossier::class)->find($id);
    if (!$dossier) {
        return $this->json(['ok' => false, 'error' => 'Dossier introuvable'], 404);
    }

    $token = bin2hex(random_bytes(16));
    $key = 'tablet_sign_dossier_' . $id . '_' . $token;

    $item = $cache->getItem($key);
    $item->set(1);
    $item->expiresAfter(300);
    $cache->save($item);

    $base = $this->generateUrl('tablet_sign_dossier', [
        'id' => $id,
    ], UrlGeneratorInterface::ABSOLUTE_URL);

    $url = $base . '?token=' . urlencode($token);

    return $this->json([
        'ok' => true,
        'url' => $url,
        'token' => $token,
    ]);
}


private function applyPostedItemBrands(Request $request, $form): void
{
    $postedBrandUiValues = (array) $request->request->all('item_brand_ui_value');

    foreach ($form->get('items') as $key => $itemForm) {
        /** @var \App\Entity\Rachat\RachatItem|null $item */
        $item = $itemForm->getData();

        if (!$item) {
            continue;
        }

        $brandUiValue = trim((string) ($postedBrandUiValues[$key] ?? ''));

        if ($brandUiValue !== '' && ctype_digit($brandUiValue)) {
            $item->setHibBrandId((int) $brandUiValue);
        } else {
            $item->setHibBrandId(null);
        }
    }
}


private function getHibBrandsRows(): array
{
    return $this->cache->get('rachat_v2_hib_brands_rows', function (ItemInterface $item) {
        $item->expiresAfter(3600);

        try {
            if (method_exists($this->hiboutikClient, 'listBrands')) {
                $res = $this->hiboutikClient->listBrands();

                return (($res['ok'] ?? false) && is_array($res['data'] ?? null))
                    ? $res['data']
                    : [];
            }

            if (method_exists($this->hiboutikClient, 'getBrands')) {
                $rows = $this->hiboutikClient->getBrands();
                return is_array($rows) ? $rows : [];
            }
        } catch (\Throwable) {
        }

        return [];
    });
}

private function getHibCategoriesRows(): array
{
    return $this->cache->get('rachat_v2_hib_categories_rows', function (ItemInterface $item) {
        $item->expiresAfter(3600);

        try {
            if (method_exists($this->hiboutikClient, 'listCategories')) {
                $res = $this->hiboutikClient->listCategories();

                return (($res['ok'] ?? false) && is_array($res['data'] ?? null))
                    ? $res['data']
                    : [];
            }

            if (method_exists($this->hiboutikClient, 'getCategories')) {
                $rows = $this->hiboutikClient->getCategories();
                return is_array($rows) ? $rows : [];
            }
        } catch (\Throwable) {
        }

        return [];
    });
}
private function buildBrandChoicesFromRows(array $rows): array
{
    $choices = [];

    foreach ($rows as $row) {
        $id = $row['brand_id'] ?? $row['id'] ?? null;
        if (!$id) {
            continue;
        }

        $label = trim((string) ($row['brand_name'] ?? $row['name'] ?? ('Marque #' . $id)));
        $choices[$label] = (string) $id;
    }

    ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

    return $choices;
}


private function buildCategoryChoicesAndDisabledFromRows(array $categories): array
{
    $categoryOptions = $this->buildCategorySelectOptions($categories);

    $choices = [];
    $disabled = [];

    foreach ($categoryOptions as $opt) {
        $label = $opt['is_parent']
            ? '[Parent] ' . $opt['label']
            : $opt['label'];

        $value = (string) $opt['id'];

        $choices[$label] = $value;
        $disabled[$value] = !$opt['selectable'];
    }

    return [$choices, $disabled];
}

private function normalizeCategoryLabel(string $label): string
{
    $label = mb_strtolower(trim($label), 'UTF-8');
    $label = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    $label = preg_replace('/[^a-z0-9]+/', ' ', (string) $label);
    $label = trim((string) $label);

    return $label;
}

private function buildCategorySelectOptions(array $categories): array
{
    $byId = [];
    $childrenByParent = [];

    foreach ($categories as $c) {
        $id = (int) ($c['category_id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $c;
        }
    }

    foreach ($categories as $c) {
        $pid = (int) ($c['category_id_parent'] ?? 0);
        $childrenByParent[$pid] ??= [];
        $childrenByParent[$pid][] = $c;
    }

    foreach ($childrenByParent as &$kids) {
        usort($kids, function ($a, $b) {
            $pa = (int) ($a['category_position'] ?? 0);
            $pb = (int) ($b['category_position'] ?? 0);

            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp(
                (string) ($a['category_name'] ?? ''),
                (string) ($b['category_name'] ?? '')
            );
        });
    }
    unset($kids);

    $roots = [];
    foreach ($categories as $c) {
        $pid = (int) ($c['category_id_parent'] ?? 0);
        if ($pid === 0 || !isset($byId[$pid])) {
            $roots[] = $c;
        }
    }

    $options = [];

    $walk = function (array $nodes, array $parents = []) use (&$walk, &$options, $childrenByParent) {
        foreach ($nodes as $c) {
            $id = (int) ($c['category_id'] ?? 0);
            $name = trim((string) ($c['category_name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $children = $childrenByParent[$id] ?? [];
            $hasChildren = count($children) > 0;

            $pathParts = array_merge($parents, [$name]);
            $fullLabel = implode(' › ', $pathParts);

            $options[] = [
                'id' => $id,
                'label' => $fullLabel,
                'selectable' => !$hasChildren,
                'is_parent' => $hasChildren,
                'level' => count($parents),
            ];

            if ($hasChildren) {
                $walk($children, $pathParts);
            }
        }
    };

    $walk($roots, []);

    return $options;
}


private function normalizeBrandName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = str_replace(['&', '+'], ' and ', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = preg_replace('/[^a-z0-9]+/', '', (string) $name);

    return (string) $name;
}

private function resolveOrCreateBrandId(string $newBrandName): int
{
    $brandsData = $this->getHibBrandsRows();

    $brandId = 0;
    $targetNorm = $this->normalizeBrandName($newBrandName);

    foreach ($brandsData as $b) {
        $bid = (int) ($b['brand_id'] ?? 0);
        $bname = trim((string) ($b['brand_name'] ?? $b['name'] ?? ''));

        if ($bid > 0 && $this->normalizeBrandName($bname) === $targetNorm) {
            $brandId = $bid;
            break;
        }
    }

    if ($brandId > 0) {
        return $brandId;
    }

    $maxPosition = 0;
    foreach ($brandsData as $b) {
        $pos = (int) ($b['brand_position'] ?? 0);
        if ($pos > $maxPosition) {
            $maxPosition = $pos;
        }
    }

    if (!method_exists($this->hiboutikClient, 'createBrand')) {
        throw new \RuntimeException('La méthode createBrand() est absente du client Hiboutik.');
    }

    $createRes = $this->hiboutikClient->createBrand([
        'brand_name' => $newBrandName,
        'brand_enabled' => 1,
        'brand_enabled_www' => 0,
        'brand_position' => $maxPosition + 1,
    ]);

    if (!($createRes['ok'] ?? false)) {
        throw new \RuntimeException('Impossible de créer la nouvelle marque Hiboutik.');
    }

    $brandId = (int) ($createRes['data']['brand_id'] ?? $createRes['brand_id'] ?? 0);

    if ($brandId <= 0) {
        $brandsReload = $this->getHibBrandsRows();

        foreach ($brandsReload as $b) {
            $bid = (int) ($b['brand_id'] ?? 0);
            $bname = trim((string) ($b['brand_name'] ?? $b['name'] ?? ''));

            if ($bid > 0 && $this->normalizeBrandName($bname) === $targetNorm) {
                $brandId = $bid;
                break;
            }
        }
    }

    if ($brandId <= 0) {
        throw new \RuntimeException('La marque a peut-être été créée, mais son identifiant est introuvable.');
    }

    $this->cache->delete('rachat_v2_hib_brands_rows');

    return $brandId;
}


#[Route('/{id}/delete-ci', name: 'delete_ci', requirements: ['id' => '\d+'], methods: ['POST'])]
public function deleteCi(Request $request, RachatDossier $dossier): JsonResponse
{
    $token = (string) $request->request->get('_token', '');

    if (!$this->isCsrfTokenValid('rachat_dossier_ci_' . $dossier->getId(), $token)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Jeton CSRF invalide.',
        ], 403);
    }

    $kind = (string) $request->request->get('kind', '');
    if (!in_array($kind, ['recto', 'verso'], true)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Type invalide.',
        ], 400);
    }

    try {
        $this->ciManager->deleteCi($dossier, $kind);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'kind' => $kind,
            'pieceIdentite' => $this->ciManager->decode($dossier->getPieceIdentiteUrl()),
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

#[Route('/item/{id}/delete-photo', name: 'item_delete_photo', requirements: ['id' => '\d+'], methods: ['POST'])]
public function deleteItemPhoto(
    Request $request,
    RachatItem $item,
    RachatMediaManager $mediaManager
): JsonResponse
{
    $token = (string) $request->request->get('_token', '');

    if (!$this->isCsrfTokenValid('rachat_item_photo_' . $item->getId(), $token)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Jeton CSRF invalide.',
        ], 403);
    }

    $slot = (string) $request->request->get('slot', '');
    if (!in_array($slot, ['photo1', 'photo2', 'photo3'], true)) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Slot photo invalide.',
        ], 400);
    }

    try {
        $mediaManager->deleteItemPhoto($item, $slot);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'slot' => $slot,
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}



}