<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat;
use App\Entity\Revendeur;
use App\Form\RachatType;
use App\Service\HiboutikClient;
use App\Service\RachatPdfGenerator;
use App\Service\RevendeurHiboutikSync;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    Request,
    Response,
    JsonResponse,
    BinaryFileResponse,
    ResponseHeaderBag,
    File\UploadedFile
};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[Route('/admin/rachats', name: 'admin_rachats_')]
final class RachatController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hib,
        private MailerInterface $mailer,
        private RevendeurHiboutikSync $sync,
        private ?LoggerInterface $logger = null,
    ) {}

    /* ============================================================
     *  TABLET
     * ============================================================ */

    #[Route('/tablet', name: 'tablet', methods: ['GET'])]
    public function tabletWait(Request $req): Response
    {
        $device = (string)($req->query->get('device', 'TAB1'));

        return $this->render('@SyliusAdmin/Rachat/tablet_wait.html.twig', [
            'device' => $device,
            'token'  => $this->getParameter('tablet_socket_token'),
        ]);
    }

    #[Route('/{id}/tablet-link', name: 'tablet_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tabletLink(int $id, Request $req, CacheItemPoolInterface $cache): JsonResponse
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        // token one-shot 5 min
        $token = bin2hex(random_bytes(16));
        $key = 'tablet_sign_' . $id . '_' . $token;

        $item = $cache->getItem($key);
        $item->set(1);
        $item->expiresAfter(300);
        $cache->save($item);

        // ⚠️ la route tablet_sign doit exister dans ton projet
        $base = $this->generateUrl('tablet_sign', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        $url  = $base . '?token=' . urlencode($token);

        return $this->json(['ok' => true, 'url' => $url, 'token' => $token]);
    }

    /* ============================================================
     *  LISTE (INDEX)
     *  -> Sortie “propre” : clés camelCase uniquement
     * ============================================================ */

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // Pattern: POST -> redirect GET (conserve params)
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('admin_rachats_index', $request->request->all());
        }

        // Colonnes renvoyées à Twig (camelCase)
        $columns = [
            'id',
            'enabled',
            'dateCession',
            'pdfUrl',
            'marqueModele',
            'imei',
            'prixAchat',
            'nom',
            'prenom',
            'numeroCi',
            'pieceIdentiteUrl',
            'telephone',
            'email',
            'adresse',
            'codePostal',
            'hibSupplierId',
            'hibProductId',
            'paidMethod',
            'paidAt',
            'photosJson',
            'createdAt',

            // ajouts
            'revendeurId',
            'vendorProcessedAt',
            'hibInventoryInputId',
            'hibArrivageAddedAt',
        ];

        $enabled = $request->query->getInt('enabled', 1);
        $q = trim((string)$request->query->get('q', ''));

        // ym: '' (mois courant) | 'YYYY-MM' | 'all'
        $ym = trim((string)$request->query->get('ym', ''));
        $useMonthFilter = true;

        if ($ym === 'all') {
            $useMonthFilter = false;
        } elseif ($ym === '' || !preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = (new \DateTimeImmutable('now'))->format('Y-m');
        }

        $from = null;
        $to = null;
        if ($useMonthFilter) {
            [$year, $month] = array_map('intval', explode('-', $ym));
            $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
            $to = $from->modify('first day of next month');
        }

        // filtres optionnels
        $paid = (string)$request->query->get('paid', '');
        $revendeur = (string)$request->query->get('revendeur', '');
        $arrivage = (string)$request->query->get('arrivage', '');

        $qb = $this->em->getRepository(Rachat::class)->createQueryBuilder('r')
            ->leftJoin('r.revendeur', 'rev')
            ->addSelect('rev')
            ->andWhere('r.enabled = :e')->setParameter('e', (bool)$enabled)
            ->orderBy('r.id', 'DESC');

        if ($useMonthFilter) {
            $qb->andWhere('(r.dateCession >= :from AND r.dateCession < :to)')
                ->setParameter('from', $from)
                ->setParameter('to', $to);
        }

        if ($paid === '1') $qb->andWhere('r.paidAt IS NOT NULL');
        if ($paid === '0') $qb->andWhere('r.paidAt IS NULL');

        if ($revendeur === '1') $qb->andWhere('r.revendeur IS NOT NULL');
        if ($revendeur === '0') $qb->andWhere('r.revendeur IS NULL');

        if ($arrivage === '1') $qb->andWhere('r.hibInventoryInputId IS NOT NULL');
        if ($arrivage === '0') $qb->andWhere('r.hibInventoryInputId IS NULL');

        if ($q !== '') {
            $qLike = '%' . mb_strtolower($q) . '%';

            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(r.marqueModele) LIKE :q',
                    'LOWER(r.imei) LIKE :q',
                    'LOWER(r.nom) LIKE :q',
                    'LOWER(r.prenom) LIKE :q',
                    'LOWER(r.numeroCi) LIKE :q',
                    'LOWER(r.telephone) LIKE :q',
                    'LOWER(r.email) LIKE :q',
                    'LOWER(r.adresse) LIKE :q',
                    'LOWER(r.codePostal) LIKE :q',
                    'LOWER(rev.nom) LIKE :q',
                    'LOWER(rev.prenom) LIKE :q',
                    'LOWER(rev.email) LIKE :q',
                    'LOWER(rev.telephone) LIKE :q'
                )
            )->setParameter('q', $qLike);

            if (ctype_digit($q)) {
                $qb->orWhere('r.id = :rid')->setParameter('rid', (int)$q);
            }
        }

        $rowsRaw = $qb->getQuery()->getArrayResult();

        $rows = [];
        foreach ($rowsRaw as $row) {
            $idInt = (int)($row['id'] ?? 0);
            if ($idInt <= 0) continue;

            // relation revendeur -> id
            $revId = 0;
            if (isset($row['revendeur']) && is_array($row['revendeur'])) {
                $revId = (int)($row['revendeur']['id'] ?? 0);
            }

            // normalisation dates + null
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('d-m-Y');
                } elseif ($v === null) {
                    $row[$k] = '';
                }
            }

            // champs “UI”
            $editUrl = $this->generateUrl('admin_rachats_edit', ['id' => $idInt]);
            $autreUrl = $this->generateUrl('admin_rachats_autre', ['id' => $idInt]);

            $photosFirst = null;
            $photosCount = 0;
            if (!empty($row['photosJson'] ?? $row['photos_json'] ?? '')) {
                $rawPhotos = (string)($row['photosJson'] ?? $row['photos_json']);
                $arr = json_decode($rawPhotos, true);
                if (is_array($arr) && $arr) {
                    $photosCount = count($arr);
                    $photosFirst = $this->generateUrl('admin_rachats_photo', [
                        'id' => $idInt,
                        'file' => basename((string)$arr[0]),
                    ]);
                }
            }

            $revendeurShowUrl = $revId > 0 ? $this->generateUrl('admin_revendeurs_show', ['id' => $revId]) : null;
            $revendeurEditUrl = $revId > 0 ? $this->generateUrl('admin_revendeurs_edit', ['id' => $revId]) : null;

            // reshape final camelCase + contrôlé par $columns
            $reshaped = [];
            foreach ($columns as $c) {
                $reshaped[$c] = (string)($this->getRowValue($row, $c) ?? '');
            }

            $reshaped['id'] = (string)$idInt;
            $reshaped['revendeurId'] = (string)$revId;

            $reshaped['editUrl'] = $editUrl;
            $reshaped['autreUrl'] = $autreUrl;
            $reshaped['photosFirst'] = $photosFirst ?? '';
            $reshaped['photosCount'] = (string)$photosCount;
            $reshaped['revendeurShowUrl'] = $revendeurShowUrl ?? '';
            $reshaped['revendeurEditUrl'] = $revendeurEditUrl ?? '';

            $rows[] = $reshaped;
        }

        // URLs mois précédent/suivant
        $prevYm = null; $nextYm = null; $prevUrl = null; $nextUrl = null;

        if ($useMonthFilter && $from instanceof \DateTimeImmutable) {
            $prevYm = $from->modify('-1 month')->format('Y-m');
            $nextYm = $from->modify('+1 month')->format('Y-m');

            $base = $request->query->all();
            $base['enabled'] = $enabled;

            $prevQ = $base; $prevQ['ym'] = $prevYm;
            $nextQ = $base; $nextQ['ym'] = $nextYm;

            $prevUrl = $this->generateUrl('admin_rachats_index', $prevQ);
            $nextUrl = $this->generateUrl('admin_rachats_index', $nextQ);
        }

        $colsCfg = $this->getParameter('rachats_columns');

        return $this->render('@SyliusAdmin/Rachat/index.html.twig', [
            'colsCfg' => $colsCfg,
            'columns' => $columns,
            'rows' => $rows,
            'enabled' => $enabled,
            'filters' => [
                'q' => $q,
                'ym' => $useMonthFilter ? $ym : 'all',
                'paid' => $paid,
                'revendeur' => $revendeur,
                'arrivage' => $arrivage,
            ],
            'prevYm' => $prevYm,
            'nextYm' => $nextYm,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
            'monthLabel' => ($useMonthFilter && $from) ? $from->format('m/Y') : 'Tous',
        ]);
    }

    private function getRowValue(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) return $row[$key];

        // snake_case -> camelCase
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
        if (array_key_exists($camel, $row)) return $row[$camel];

        // camelCase -> snake_case (au cas où)
        $snake = strtolower(preg_replace('/[A-Z]/', '_$0', $key) ?? $key);
        if (array_key_exists($snake, $row)) return $row[$snake];

        return null;
    }

    /* ============================================================
     *  CREATE
     * ============================================================ */

    #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            $rachat = new Rachat();
            $rachat->setCreatedAt(new \DateTimeImmutable());
            $rachat->setDateCession(new \DateTimeImmutable('today'));

            $this->em->persist($rachat);
            $this->em->flush();

            return $this->redirectToRoute('admin_rachats_edit', ['id' => $rachat->getId()]);
        }

        return $this->redirectToRoute('admin_rachats_index');
    }

    /* ============================================================
     *  EDIT
     * ============================================================ */

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        /** @var Rachat|null $r */
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        // pré-remplir “today” à l’affichage si vide
        if (!$request->isMethod('POST') && $r->getDateCession() === null) {
            $r->setDateCession(new \DateTimeImmutable('today'));
        }

        // Choices Hiboutik
        $brandsRes = $this->hib->listBrands();
        $catsRes = $this->hib->listCategories();

        $brandChoices = [];
        if (($brandsRes['ok'] ?? false) && is_array($brandsRes['data'] ?? null)) {
            foreach ($brandsRes['data'] as $b) {
                $bid = (int)($b['brand_id'] ?? 0);
                $name = trim((string)($b['brand_name'] ?? $b['name'] ?? ''));
                if ($bid > 0 && $name !== '') $brandChoices[$name] = $bid;
            }
        }

        $catChoices = [];
        if (($catsRes['ok'] ?? false) && is_array($catsRes['data'] ?? null)) {
            foreach ($catsRes['data'] as $c) {
                $cid = (int)($c['category_id'] ?? 0);
                $name = trim((string)($c['category_name'] ?? $c['name'] ?? ''));
                if ($cid > 0 && $name !== '') $catChoices[$name] = $cid;
            }
        }

        $form = $this->createForm(RachatType::class, $r, [
            'hib_brands_choices' => $brandChoices,
            'hib_categories_choices' => $catChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ($r->getDateCession() === null) {
                $r->setDateCession(new \DateTimeImmutable('today'));
            }

            $baseDir = $this->getVarPrivateDir($r->getId());
            @mkdir($baseDir, 0775, true);
            @mkdir($baseDir . '/photos', 0775, true);

            // RECTO
            /** @var UploadedFile|null $recto */
            $recto = $form->get('pieceIdentiteRectoFile')->getData();
            if ($recto instanceof UploadedFile) {
                $dst = $baseDir . '/piece_identite_recto.jpg';
                $this->shrinkToJpegUnder($recto->getPathname(), $dst, 2000, 2000, 1_000_000);
            }

            // VERSO
            /** @var UploadedFile|null $verso */
            $verso = $form->get('pieceIdentiteVersoFile')->getData();
            if ($verso instanceof UploadedFile) {
                $dst = $baseDir . '/piece_identite_verso.jpg';
                $this->shrinkToJpegUnder($verso->getPathname(), $dst, 2000, 2000, 1_000_000);
            }

            // pieceIdentiteUrl : objet {recto,verso}
            $ciUrls = [];
            if (is_file("$baseDir/piece_identite_recto.jpg")) {
                $ciUrls['recto'] = $this->generateUrl('admin_rachats_ci', [
                    'id' => $r->getId(),
                    'kind' => 'recto',
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
            if (is_file("$baseDir/piece_identite_verso.jpg")) {
                $ciUrls['verso'] = $this->generateUrl('admin_rachats_ci', [
                    'id' => $r->getId(),
                    'kind' => 'verso',
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
            $r->setPieceIdentiteUrl($ciUrls ? json_encode($ciUrls, JSON_UNESCAPED_SLASHES) : null);

            // PHOTOS
            /** @var UploadedFile[]|null $photos */
            $photos = $form->get('photoFiles')->getData();
            if (is_array($photos) && $photos) {
                $list = [];
                if ($r->getPhotosJson()) {
                    $arr = json_decode($r->getPhotosJson(), true);
                    if (is_array($arr)) $list = $arr;
                }

                foreach ($photos as $pf) {
                    if (!$pf instanceof UploadedFile) continue;
                    $name = 'photo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
                    $dst = $baseDir . '/photos/' . $name;
                    $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
                    $list[] = $name;
                }

                $r->setPhotosJson(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (method_exists($r, 'setUpdatedAt')) {
                $r->setUpdatedAt(new \DateTimeImmutable());
            }

            $this->em->flush();

            $goSign = (bool)$request->request->get('_go_sign', false);
            $this->addFlash('success', 'Rachat mis à jour.');

            if ($goSign) {
                return $this->redirectToRoute('admin_rachats_sign', ['id' => $r->getId()]);
            }

            return $this->redirectToRoute('admin_rachats_edit', ['id' => $r->getId()]);
        }

        return $this->render('@SyliusAdmin/Rachat/edit.html.twig', [
            'rachat' => $r,
            'form' => $form->createView(),
            'tablet_socket_token' => $this->getParameter('tablet_socket_token'),
        ]);
    }

    /* ============================================================
     *  DELETE
     * ============================================================ */

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Rachat $rachat, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $rachat->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        if (
            $rachat->getPaidAt() !== null ||
            $rachat->getHibProductId() !== null ||
            $rachat->getSignatureUrl() !== null ||
            $rachat->getPieceIdentiteUrl() !== null
        ) {
            $this->addFlash('error', 'Ce rachat ne peut pas être supprimé (paiement, signature ou Hiboutik déjà présent).');
            return $this->redirectToRoute('admin_rachats_show', ['id' => $rachat->getId()]);
        }

        $em->remove($rachat);
        $em->flush();

        $this->addFlash('success', 'Rachat supprimé.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    /* ============================================================
     *  MULTI (procédure)
     * ============================================================ */

    #[Route('/multi', name: 'multi', methods: ['GET', 'POST'])]
    public function multi(Request $req, RachatPdfGenerator $pdfGen, HiboutikClient $hib): Response
    {
        $ids = $req->request->all('ids');
        if (!is_array($ids) || !$ids) {
            $this->addFlash('error', 'Aucun rachat sélectionné pour la procédure multi.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $repo = $this->em->getRepository(Rachat::class);
        $rachats = [];
        foreach ($ids as $id) {
            if ($r = $repo->find((int)$id)) $rachats[] = $r;
        }
        if (!$rachats) {
            $this->addFlash('error', 'Rachats introuvables.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        /** @var Rachat $main */
        $main = $rachats[0];

        // ETAPE 1: afficher formulaire multi
        if (!$req->isMethod('POST') || !$req->request->get('do_multi')) {
            $ci = ['recto' => null, 'verso' => null];
            if ($main->getPieceIdentiteUrl()) {
                $raw = json_decode($main->getPieceIdentiteUrl(), true);
                if (is_array($raw)) {
                    if (array_is_list($raw)) {
                        $ci['recto'] = $raw[0] ?? null;
                        $ci['verso'] = $raw[1] ?? null;
                    } else {
                        $ci['recto'] = $raw['recto'] ?? null;
                        $ci['verso'] = $raw['verso'] ?? null;
                    }
                }
            }

            $total = 0.0;
            foreach ($rachats as $r) {
                $total += (float)str_replace(',', '.', (string)$r->getPrixAchat());
            }

            return $this->render('@SyliusAdmin/Rachat/multi.html.twig', [
                'rachats' => $rachats,
                'main' => $main,
                'ids' => array_map('intval', $ids),
                'ci' => $ci,
                'total' => $total,
            ]);
        }

        // ETAPE 2: traitement CI + paiement + pdf multi
        /** @var UploadedFile|null $recto */
        $recto = $req->files->get('ci_recto');
        /** @var UploadedFile|null $verso */
        $verso = $req->files->get('ci_verso');

        $base = $this->getVarPrivateDir($main->getId());
        @mkdir($base, 0775, true);

        $urls = [];
        if ($recto instanceof UploadedFile) {
            $dst = sprintf('%s/piece_identite_recto.jpg', $base);
            $this->shrinkToJpegUnder($recto->getPathname(), $dst, 2000, 2000, 1_000_000);
        }
        if ($verso instanceof UploadedFile) {
            $dst = sprintf('%s/piece_identite_verso.jpg', $base);
            $this->shrinkToJpegUnder($verso->getPathname(), $dst, 2000, 2000, 1_000_000);
        }

        foreach (['recto', 'verso'] as $kind) {
            $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
            if (is_file($p)) {
                $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
                    'id' => $main->getId(),
                    'kind' => $kind,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }
        if ($urls) $main->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));

        $method = (string)$req->request->get('method', 'ESP');
        $otherLabel = (string)$req->request->get('other_label', '');

        $meta = $this->hib->getDefaultStoreMeta();
        $storeId = (int)($meta['store_id'] ?? 1);
        $currency = (string)($meta['currency_code'] ?? 'EUR');

        $total = 0.0;
        $libProduits = [];
        foreach ($rachats as $r) {
            $price = (float)str_replace(',', '.', (string)$r->getPrixAchat());
            $total += $price;
            $libProduits[] = sprintf('%s %s€', (string)$r->getMarqueModele(), number_format($price, 2, ',', ' '));
        }

        $idsStr = implode(',', array_map(fn($r) => $r->getId(), $rachats));
        $libProduitsStr = implode(' / ', $libProduits);

        $comment = sprintf(
            'RACHATS MULTI (%s) total %s€ / %s / %s %s',
            $idsStr,
            number_format($total, 2, ',', ' '),
            $libProduitsStr,
            (string)$main->getNom(),
            (string)$main->getPrenom()
        );

        if ($method) {
            $suffix = ' — ' . $method;
            if (strtoupper($method) === 'AUTRE' && $otherLabel) $suffix .= ' (' . $otherLabel . ')';
            $comment .= $suffix;
        }

        $res = $hib->tillCashOut($storeId, $total, $currency, $comment);
        $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

        if (!$okHib) {
            $this->addFlash('error', 'Erreur Hiboutik lors de l’encaissement multi.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $now = new \DateTimeImmutable();
        foreach ($rachats as $r) {
            $r->setPaidMethod($method ?: 'ESP');
            $r->setPaidAt($now);
        }
        $this->em->flush();

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shop = [
            'name' => $store['store_name'] ?? '',
            'address' => $addr['address'] ?? '',
            'zip' => $addr['zippostal_code'] ?? '',
            'city' => $addr['city'] ?? '',
            'phone' => $addr['phone'] ?? '',
            'email' => $addr['email'] ?? '',
            'site' => 'https://shop.multimedia-services.fr',
        ];

        $photosByRachat = [];
        foreach ($rachats as $r) {
            $photosByRachat[$r->getId()] = [];
            if (!$r->getPhotosJson()) continue;
            $names = json_decode($r->getPhotosJson(), true) ?: [];
            $baseR = $this->getVarPrivateDir($r->getId());
            foreach (array_slice($names, 0, 4) as $name) {
                $p = $baseR . '/photos/' . basename((string)$name);
                $u = $this->fileToDataUri($p);
                if ($u) $photosByRachat[$r->getId()][] = $u;
            }
        }

        $piecesIdentiteData = [];
        foreach (['recto', 'verso'] as $kind) {
            $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
            $u = $this->fileToDataUri($p);
            if ($u) $piecesIdentiteData[] = $u;
        }

        $ts = (new \DateTimeImmutable())->format('Ymd_His');
        $filename = sprintf('rachat-multi-%s.pdf', $ts);

        $resPdf = $pdfGen->generateMulti([
            'rachats' => $rachats,
            'main' => $main,
            'shop' => $shop,
            'generated_at' => new \DateTimeImmutable(),
            'pieces_identite_data' => $piecesIdentiteData,
            'photos_by_rachat' => $photosByRachat,
            'total' => $total,
        ], $filename);

        $this->addFlash('success', 'Procédure multi effectuée. Total encaissé : ' . number_format($total, 2, ',', ' ') . ' €');

        return $this->redirect((string)($resPdf['url'] ?? $this->generateUrl('admin_rachats_index')));
    }

    /* ============================================================
     *  SIGN MULTI (affichage + submit)
     * ============================================================ */

    #[Route('/multi/sign', name: 'multi_sign', methods: ['GET'])]
    public function multiSign(Request $req): Response
    {
        $idsParam = (string)$req->query->get('ids', '');
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (!$ids) {
            $this->addFlash('error', 'Aucun rachat sélectionné pour le bon multi.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $repo = $this->em->getRepository(Rachat::class);
        $rachats = [];
        foreach ($ids as $id) {
            if ($r = $repo->find($id)) $rachats[] = $r;
        }
        if (!$rachats) {
            $this->addFlash('error', 'Rachats introuvables pour le bon multi.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $first = $rachats[0];
        $nomRef = (string)$first->getNom();
        $prenomRef = (string)$first->getPrenom();

        foreach ($rachats as $r) {
            if ((string)$r->getNom() !== $nomRef || (string)$r->getPrenom() !== $prenomRef) {
                $this->addFlash('error', 'Bon multi impossible : tous les rachats doivent avoir le même vendeur.');
                return $this->redirectToRoute('admin_rachats_index');
            }
        }

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shop = [
            'name' => $store['store_name'] ?? '',
            'company' => $addr['company'] ?? '',
            'address' => $addr['address'] ?? '',
            'zip' => $addr['zippostal_code'] ?? '',
            'city' => $addr['city'] ?? '',
            'country' => $addr['country'] ?? '',
            'tax_number' => $addr['tax_number'] ?? '',
            'company_number' => $addr['company_number'] ?? '',
            'legal_status' => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf' => $addr['code_naf'] ?? '',
            'phone' => $addr['phone'] ?? '',
            'email' => $addr['email'] ?? '',
            'site' => 'https://shop.multimedia-services.fr',
        ];

        return $this->render('@SyliusAdmin/Rachat/sign_multi.html.twig', [
            'rachats' => $rachats,
            'ids' => $ids,
            'ids_string' => implode(',', $ids),
            'shop' => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'generated_at' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/multi/sign/submit', name: 'multi_sign_submit', methods: ['POST'])]
    public function multiSignSubmit(Request $req, RachatPdfGenerator $pdfGen): Response
    {
        $idsParam = (string)$req->request->get('ids', '');
        $ids = array_filter(array_map('intval', explode(',', $idsParam)));

        if (!$ids) {
            $this->addFlash('error', 'Aucun rachat pour le bon multi.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $repo = $this->em->getRepository(Rachat::class);
        $rachats = [];
        foreach ($ids as $id) {
            if ($r = $repo->find($id)) $rachats[] = $r;
        }
        if (!$rachats) {
            $this->addFlash('error', 'Rachats introuvables.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $first = $rachats[0];
        $nomRef = (string)$first->getNom();
        $prenomRef = (string)$first->getPrenom();
        foreach ($rachats as $r) {
            if ((string)$r->getNom() !== $nomRef || (string)$r->getPrenom() !== $prenomRef) {
                $this->addFlash('error', 'Bon multi : vendeurs différents.');
                return $this->redirectToRoute('admin_rachats_index');
            }
        }

        $dataUrl = (string)$req->request->get('signature_dataurl', '');
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            $this->addFlash('error', 'Signature manquante.');
            return $this->redirectToRoute('admin_rachats_multi_sign', ['ids' => implode(',', $ids)]);
        }
        $png = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));

        $projectDir = $this->getParameter('kernel.project_dir');

        foreach ($rachats as $r) {
            $pub = $projectDir . "/public/uploads/rachats/" . $r->getId();
            @mkdir($pub, 0775, true);
            file_put_contents("$pub/signature.png", $png);
            $r->setSignatureUrl("/uploads/rachats/" . $r->getId() . "/signature.png");
        }

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shop = [
            'name' => $store['store_name'] ?? '',
            'company' => $addr['company'] ?? '',
            'address' => $addr['address'] ?? '',
            'zip' => $addr['zippostal_code'] ?? '',
            'city' => $addr['city'] ?? '',
            'country' => $addr['country'] ?? '',
            'tax_number' => $addr['tax_number'] ?? '',
            'company_number' => $addr['company_number'] ?? '',
            'legal_status' => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf' => $addr['code_naf'] ?? '',
            'phone' => $addr['phone'] ?? '',
            'email' => $addr['email'] ?? '',
            'site' => 'https://shop.multimedia-services.fr',
        ];

        $baseFirst = $this->getVarPrivateDir($first->getId());
        $piecesIdentiteData = [];
        foreach (['recto', 'verso'] as $kind) {
            $p = sprintf('%s/piece_identite_%s.jpg', $baseFirst, $kind);
            if (is_file($p)) {
                $u = $this->fileToDataUri($p);
                if ($u) $piecesIdentiteData[] = $u;
            }
        }

        $sellerSigPath = $projectDir . '/public' . $first->getSignatureUrl();
        $sellerSigData = is_file($sellerSigPath) ? $this->fileToDataUri($sellerSigPath) : null;

        $items = [];
        foreach ($rachats as $r) {
            $base = $this->getVarPrivateDir($r->getId());
            $photosData = [];

            if ($r->getPhotosJson()) {
                foreach (array_slice(json_decode($r->getPhotosJson(), true) ?: [], 0, 4) as $name) {
                    $p = $base . '/photos/' . basename((string)$name);
                    $u = $this->fileToDataUri($p);
                    if ($u) $photosData[] = $u;
                }
            }

            $items[] = ['r' => $r, 'photos' => $photosData];
        }

        $idsStr = implode('-', $ids);
        $ts = (new \DateTimeImmutable())->format('Ymd_His');
        $filename = sprintf('rachat-multi-%s-%s.pdf', $idsStr, $ts);

        $res = $pdfGen->generateMulti([
            'rachats_items' => $items,
            'generated_at' => new \DateTimeImmutable(),
            'shop' => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'pieces_identite_data' => $piecesIdentiteData,
            'seller_sign_data_uri' => $sellerSigData,
            'ids_string' => implode(', ', $ids),
        ], $filename);

        $pdfUrl = (string)($res['url'] ?? '');

        foreach ($rachats as $r) {
            $r->setPdfUrl($pdfUrl);
        }
        $this->em->flush();

        $this->addFlash('success', sprintf('Bon de cession multi généré pour les rachats %s.', implode(', ', $ids)));

        return $this->render('@SyliusAdmin/Rachat/after_sign_multi.html.twig', [
            'ids' => $ids,
            'pdf_url' => $pdfUrl,
            'list_url' => $this->generateUrl('admin_rachats_index'),
        ]);
    }

    /* ============================================================
     *  AUTRE (duplication vendeur)
     * ============================================================ */

    #[Route('/{id}/autre', name: 'autre', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function autre(int $id): Response
    {
        $repo = $this->em->getRepository(Rachat::class);
        $orig = $repo->find($id);
        if (!$orig) throw $this->createNotFoundException('Rachat introuvable');

        $nouveau = new Rachat();
        $nouveau
            ->setNom($orig->getNom())
            ->setPrenom($orig->getPrenom())
            ->setNumeroCi($orig->getNumeroCi())
            ->setTelephone($orig->getTelephone())
            ->setEmail($orig->getEmail())
            ->setAdresse($orig->getAdresse())
            ->setCodePostal($orig->getCodePostal())
            ->setHibSupplierId($orig->getHibSupplierId())
            ->setEnabled(true)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($nouveau);
        $this->em->flush();

        // dupliquer CI locale
        $oldBase = $this->getVarPrivateDir($orig->getId());
        $newBase = $this->getVarPrivateDir($nouveau->getId());
        @mkdir($newBase, 0775, true);

        $ciUrls = [];
        foreach (['recto', 'verso'] as $kind) {
            $oldPath = sprintf('%s/piece_identite_%s.jpg', $oldBase, $kind);
            if (is_file($oldPath)) {
                @copy($oldPath, sprintf('%s/piece_identite_%s.jpg', $newBase, $kind));
                $ciUrls[$kind] = $this->generateUrl('admin_rachats_ci', [
                    'id' => $nouveau->getId(),
                    'kind' => $kind,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }
        if ($ciUrls) {
            $nouveau->setPieceIdentiteUrl(json_encode($ciUrls, JSON_UNESCAPED_SLASHES));
            $this->em->flush();
        }

        $this->addFlash('success', sprintf(
            'Nouveau rachat créé pour le vendeur %s %s.',
            (string)$nouveau->getPrenom(),
            (string)$nouveau->getNom()
        ));

        return $this->redirectToRoute('admin_rachats_edit', ['id' => $nouveau->getId()]);
    }

    /* ============================================================
     *  SIGN (affichage + submit)
     * ============================================================ */

    #[Route('/{id}/sign', name: 'sign', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sign(int $id): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shop = [
            'name' => $store['store_name'] ?? '',
            'company' => $addr['company'] ?? '',
            'address' => $addr['address'] ?? '',
            'zip' => $addr['zippostal_code'] ?? '',
            'city' => $addr['city'] ?? '',
            'country' => $addr['country'] ?? '',
            'tax_number' => $addr['tax_number'] ?? '',
            'company_number' => $addr['company_number'] ?? '',
            'legal_status' => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf' => $addr['code_naf'] ?? '',
            'phone' => $addr['phone'] ?? '',
            'email' => $addr['email'] ?? '',
            'site' => 'https://shop.multimedia-services.fr',
        ];

        $photos = [];
        if ($r->getPhotosJson()) {
            $names = json_decode($r->getPhotosJson(), true) ?: [];
            foreach (array_slice($names, 0, 4) as $name) {
                $photos[] = $this->generateUrl('admin_rachats_photo', [
                    'id' => $r->getId(),
                    'file' => basename((string)$name),
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        $ciUrls = [];
        if ($r->getPieceIdentiteUrl()) {
            $raw = json_decode($r->getPieceIdentiteUrl(), true);
            if (is_array($raw)) {
                if (array_is_list($raw)) {
                    if (!empty($raw[0])) $ciUrls['recto'] = $raw[0];
                    if (!empty($raw[1])) $ciUrls['verso'] = $raw[1];
                } else {
                    foreach (['recto', 'verso'] as $k) {
                        if (!empty($raw[$k])) $ciUrls[$k] = $raw[$k];
                    }
                }
            }
        }

        $sellerSigData = null;
        if ($r->getSignatureUrl()) {
            $sigPath = $this->getParameter('kernel.project_dir') . '/public' . $r->getSignatureUrl();
            if (is_file($sigPath)) $sellerSigData = $this->fileToDataUri($sigPath);
        }

        return $this->render('@SyliusAdmin/Rachat/sign.html.twig', [
            'r' => $r,
            'shop' => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'seller_sig_data_uri' => $sellerSigData,
            'photos' => $photos,
            'ci_urls' => $ciUrls,
            'generated_at' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{id}/sign/submit', name: 'sign_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signSubmit(int $id, Request $req, RachatPdfGenerator $pdfGen): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $dataUrl = (string)$req->request->get('signature_dataurl', '');
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            $this->addFlash('error', 'Signature manquante.');
            return $this->redirectToRoute('admin_rachats_sign', ['id' => $id]);
        }

        $png = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));
        $pub = $this->getParameter('kernel.project_dir') . "/public/uploads/rachats/$id";
        @mkdir($pub, 0775, true);
        file_put_contents("$pub/signature.png", $png);
        $r->setSignatureUrl("/uploads/rachats/$id/signature.png");

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shop = [
            'name' => $store['store_name'] ?? '',
            'company' => $addr['company'] ?? '',
            'address' => $addr['address'] ?? '',
            'zip' => $addr['zippostal_code'] ?? '',
            'city' => $addr['city'] ?? '',
            'country' => $addr['country'] ?? '',
            'tax_number' => $addr['tax_number'] ?? '',
            'company_number' => $addr['company_number'] ?? '',
            'legal_status' => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf' => $addr['code_naf'] ?? '',
            'phone' => $addr['phone'] ?? '',
            'email' => $addr['email'] ?? '',
            'site' => 'https://shop.multimedia-services.fr',
        ];

        $base = $this->getVarPrivateDir($r->getId());

        $piecesIdentiteData = [];
        foreach (['recto', 'verso'] as $kind) {
            $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
            if (is_file($p)) {
                $u = $this->fileToDataUri($p);
                if ($u) $piecesIdentiteData[] = $u;
            }
        }

        $photosData = [];
        if ($r->getPhotosJson()) {
            foreach (array_slice(json_decode($r->getPhotosJson(), true) ?: [], 0, 4) as $name) {
                $p = "$base/photos/" . basename((string)$name);
                $u = $this->fileToDataUri($p);
                if ($u) $photosData[] = $u;
            }
        }

        $sellerSigPath = $this->getParameter('kernel.project_dir') . '/public' . $r->getSignatureUrl();
        $sellerSigData = is_file($sellerSigPath) ? $this->fileToDataUri($sellerSigPath) : null;

        $ts = (new \DateTimeImmutable())->format('Ymd_His');
        $filename = sprintf('rachat-%d-%s.pdf', $id, $ts);

        $res = $pdfGen->generate([
            'r' => $r,
            'generated_at' => new \DateTimeImmutable(),
            'shop' => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'pieces_identite_data' => $piecesIdentiteData,
            'photos_data_uris' => $photosData,
            'seller_sign_data_uri' => $sellerSigData,
        ], $filename);

        $r->setPdfUrl((string)($res['url'] ?? ''));
        $this->em->flush();

        return $this->render('@SyliusAdmin/Rachat/after_sign.html.twig', [
            'r' => $r,
            'pdf_url' => (string)($res['url'] ?? ''),
            'edit_url' => $this->generateUrl('admin_rachats_edit', ['id' => $id]),
            'list_url' => $this->generateUrl('admin_rachats_index'),
        ]);
    }

    /* ============================================================
     *  STREAM CI / PHOTO
     * ============================================================ */

    #[Route('/{id}/ci/{kind}', name: 'ci', requirements: ['id' => '\d+', 'kind' => 'recto|verso'], methods: ['GET'])]
    public function streamCi(int $id, string $kind): BinaryFileResponse
    {
        $path = sprintf('%s/piece_identite_%s.jpg', $this->getVarPrivateDir($id), $kind);
        if (!is_file($path)) throw $this->createNotFoundException("Pièce d’identité $kind introuvable");

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }

    #[Route('/{id}/photo/{file}', name: 'photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function streamPhoto(int $id, string $file): BinaryFileResponse
    {
        $dir = realpath($this->getVarPrivateDir($id) . '/photos');
        $path = $dir ? realpath($dir . '/' . $file) : null;

        if (!$path || !str_starts_with($path, $dir)) throw $this->createNotFoundException('Fichier invalide');
        if (!is_file($path)) throw $this->createNotFoundException('Photo introuvable');

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }

    /* ============================================================
     *  QUICK
     * ============================================================ */

    #[Route('/quick', name: 'quick', methods: ['GET', 'POST'])]
    public function quick(Request $req): Response
    {
        if ($req->isMethod('POST')) {
            $marqueModele = trim((string)$req->request->get('marque_modele', ''));
            $prixAchat = (float)$req->request->get('prix_achat', 0);

            if ($marqueModele === '' || $prixAchat <= 0) {
                $this->addFlash('error', 'Marque/Modèle et Prix achat sont obligatoires.');
                return $this->redirectToRoute('admin_rachats_quick');
            }

            $r = (new Rachat())
                ->setMarqueModele($marqueModele)
                ->setPrixAchat(number_format($prixAchat, 2, '.', ''))
                ->setImei((string)$req->request->get('imei', ''))
                ->setNom((string)$req->request->get('nom', ''))
                ->setPrenom((string)$req->request->get('prenom', ''))
                ->setTelephone((string)$req->request->get('telephone', ''))
                ->setEmail((string)$req->request->get('email', ''))
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($r);
            $this->em->flush();

            $this->addFlash('success', 'Rachat créé (Quick).');
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $r->getId()]);
        }

        return $this->render('@SyliusAdmin/Rachat/quick.html.twig');
    }

    /* ============================================================
     *  ENCAISSEMENT (annuler / batch / one)
     * ============================================================ */

    #[Route('/{id}/annuler-encaissement', name: 'annuler_encaissement_one', methods: ['POST'])]
    public function annulerEncaissementOne(int $id, HiboutikClient $hib): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rachat introuvable.');
            return $this->redirectToRoute('admin_rachats_index');
        }
        if (!$r->getPaidAt()) {
            $this->addFlash('error', 'Ce rachat n’est pas marqué comme payé.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $amount = (float)str_replace(',', '.', (string)$r->getPrixAchat());
        $meta = $this->hib->getDefaultStoreMeta();
        $storeId = (int)($meta['store_id'] ?? 1);
        $currency = (string)($meta['currency_code'] ?? 'EUR');

        $comment = sprintf(
            'ANNULATION RACHAT %d / %s / %s %s',
            $r->getId(),
            (string)$r->getMarqueModele(),
            (string)$r->getNom(),
            (string)$r->getPrenom()
        );

        $res = $hib->tillCashIn($storeId, $amount, $currency, $comment);
        $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

        if ($okHib) {
            $r->setPaidMethod(null);
            $r->setPaidAt(null);
            $this->em->flush();
            $this->addFlash('success', 'Paiement annulé pour le rachat n°' . $r->getId() . '.');
        } else {
            $this->addFlash('error', 'Erreur Hiboutik lors de l’annulation du paiement.');
        }

        return $this->redirectToRoute('admin_rachats_index');
    }

    #[Route('/encaissement', name: 'encaissement_batch', methods: ['POST'])]
    public function encaissementBatch(Request $request, HiboutikClient $hib): Response
    {
        $ids = $request->request->all('ids');
        $method = (string)$request->request->get('method');
        $labelAutre = (string)$request->request->get('other_label');
        $backEnabled = $request->request->getInt('_back_enabled', 1);

        if (!$ids) {
            $this->addFlash('error', 'Sélection requise');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $repo = $this->em->getRepository(Rachat::class);

        $rachats = [];
        $idsClean = [];
        $total = 0.0;
        $libProduits = [];
        $first = null;

        foreach ($ids as $id) {
            $r = $repo->find((int)$id);
            if (!$r) continue;

            $rachats[] = $r;
            $idsClean[] = $r->getId();

            $price = (float)str_replace(',', '.', (string)$r->getPrixAchat());
            $total += $price;

            $libProduits[] = sprintf('%s %s€', (string)$r->getMarqueModele(), number_format($price, 2, ',', ' '));

            if ($first === null) $first = $r;
        }

        if (!$rachats || !$first) {
            $this->addFlash('error', 'Rachats introuvables.');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $idsStr = implode(',', $idsClean);
        $libProduitsStr = implode(' / ', $libProduits);

        $comment = sprintf(
            'RACHATS MULTI (%s) total %s€ / %s / %s %s',
            $idsStr,
            number_format($total, 2, ',', ' '),
            $libProduitsStr,
            (string)$first->getNom(),
            (string)$first->getPrenom()
        );

        if ($method) {
            $suffix = ' — ' . $method;
            if (strtoupper($method) === 'AUTRE' && $labelAutre) $suffix .= ' (' . $labelAutre . ')';
            $comment .= $suffix;
        }

        $storeId = 1;
        $currency = 'EUR';

        $res = $hib->tillCashOut($storeId, $total, $currency, $comment);
        $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

        if (!$okHib) {
            $this->addFlash('error', 'Erreur Hiboutik lors de l’encaissement multiple.');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $now = new \DateTimeImmutable();
        foreach ($rachats as $r) {
            $r->setPaidMethod($method ?: 'ESP');
            $r->setPaidAt($now);
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Encaissement multiple effectué : %s€ pour les rachats (%s).',
            number_format($total, 2, ',', ' '),
            $idsStr
        ));

        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    #[Route('/{id}/encaissement', name: 'encaissement_one', methods: ['POST'])]
    public function encaissementOne(int $id, Request $request, HiboutikClient $hib): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        $method = (string)$request->request->get('method');
        $labelAutre = (string)$request->request->get('other_label');

        $storeId = 1;
        $currency = 'EUR';

        $amount = (float)str_replace(',', '.', (string)$r->getPrixAchat());

        $comment = sprintf(
            'RACHAT %d / %s / %s %s',
            $r->getId(),
            (string)$r->getMarqueModele(),
            (string)$r->getNom(),
            (string)$r->getPrenom()
        );

        if ($method) {
            $suffix = ' — ' . $method;
            if (strtoupper($method) === 'AUTRE' && $labelAutre) $suffix .= ' (' . $labelAutre . ')';
            $comment .= $suffix;
        }

        $res = $hib->tillCashOut($storeId, $amount, $currency, $comment);
        $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

        if ($okHib) {
            $r->setPaidMethod($method ?: 'ESP');
            $r->setPaidAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        $this->addFlash('success', 'Encaissement effectué pour rachat n°' . $r->getId() . '.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    /* ============================================================
     *  TOGGLE enabled
     * ============================================================ */

    #[Route('/toggle-enabled', name: 'toggle_enabled_batch', methods: ['POST'])]
    public function toggleEnabledBatch(Request $request): Response
    {
        $ids = $request->request->all('ids');
        $val = $request->request->getInt('val', 1);
        $backEnabled = $request->request->getInt('_back_enabled', 1);

        if (!$ids) {
            $this->addFlash('error', 'Sélection requise');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $repo = $this->em->getRepository(Rachat::class);
        foreach ($ids as $id) {
            if ($r = $repo->find((int)$id)) $r->setEnabled((bool)$val);
        }
        $this->em->flush();

        $this->addFlash('success', ($val ? 'Affichés' : 'Masqués') . ' : ' . count($ids));
        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    /* ============================================================
     *  UPLOAD CI / PHOTOS (AJAX)
     * ============================================================ */

    #[Route('/{id<\d+>}/upload-ci', name: 'upload_ci', methods: ['POST'])]
    public function uploadCi(int $id, Request $req): JsonResponse
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        $kind = (string)$req->request->get('kind', 'recto');
        /** @var UploadedFile|null $file */
        $file = $req->files->get('ci');
        if (!$file) return $this->json(['ok' => false, 'error' => 'Aucun fichier reçu'], 400);

        $base = $this->getVarPrivateDir($r->getId());
        @mkdir($base, 0775, true);

        $dst = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
        $this->shrinkToJpegUnder($file->getPathname(), $dst, 2000, 2000, 1_000_000);

        $urls = [];
        if ($r->getPieceIdentiteUrl()) {
            $old = json_decode($r->getPieceIdentiteUrl(), true);
            if (is_array($old)) $urls = $old;
        }

        $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
            'id' => $id,
            'kind' => $kind,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $r->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'kind' => $kind,
            'url' => $urls[$kind],
            'msg' => "Pièce $kind enregistrée",
        ]);
    }

    #[Route('/{id}/upload-photos', name: 'upload_photos', methods: ['POST'])]
    public function uploadPhotos(int $id, Request $req, CsrfTokenManagerInterface $csrf): Response
    {
        $token = new CsrfToken('rachat_edit_' . $id, (string)$req->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            return $this->json(['ok' => false, 'error' => 'CSRF'], 400);
        }

        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        /** @var UploadedFile[] $files */
        $files = $req->files->all('photos') ?? [];
        if (!$files) return $this->json(['ok' => false, 'error' => 'Aucun fichier'], 400);

        $base = $this->getVarPrivateDir($id);
        $pdir = $base . '/photos';
        @mkdir($pdir, 0775, true);

        $list = [];
        if ($r->getPhotosJson()) {
            $arr = json_decode($r->getPhotosJson(), true);
            if (is_array($arr)) $list = $arr;
        }

        $addedUrls = [];
        foreach ($files as $pf) {
            if (!$pf instanceof UploadedFile) continue;

            $name = 'photo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
            $dst = $pdir . '/' . $name;

            $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
            $list[] = $name;

            $addedUrls[] = $this->generateUrl('admin_rachats_photo', [
                'id' => $id,
                'file' => $name,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $r->setPhotosJson(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'count' => count($addedUrls),
            'added' => $addedUrls,
            'all' => array_map(fn($n) => $this->generateUrl('admin_rachats_photo', [
                'id' => $id,
                'file' => $n,
            ], UrlGeneratorInterface::ABSOLUTE_URL), $list),
        ]);
    }

    /* ============================================================
     *  SHOW + CI PAGE
     * ============================================================ */

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $photos = [];
        if ($r->getPhotosJson()) {
            $names = json_decode($r->getPhotosJson(), true) ?: [];
            foreach (array_slice($names, 0, 12) as $name) {
                $photos[] = $this->generateUrl('admin_rachats_photo', [
                    'id' => $r->getId(),
                    'file' => basename((string)$name),
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        $ciUrls = [];
        if ($r->getPieceIdentiteUrl()) {
            $raw = json_decode($r->getPieceIdentiteUrl(), true);
            if (is_array($raw)) {
                if (array_is_list($raw)) {
                    if (!empty($raw[0])) $ciUrls['recto'] = $raw[0];
                    if (!empty($raw[1])) $ciUrls['verso'] = $raw[1];
                } else {
                    foreach (['recto', 'verso'] as $k) {
                        if (!empty($raw[$k])) $ciUrls[$k] = $raw[$k];
                    }
                }
            }
        }

        return $this->render('@SyliusAdmin/Rachat/show.html.twig', [
            'r' => $r,
            'photos' => $photos,
            'ci_urls' => $ciUrls,
        ]);
    }

    #[Route('/{id}/ci-page', name: 'ci_page', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ciPage(int $id): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $ci = ['recto' => null, 'verso' => null];

        if ($r->getPieceIdentiteUrl()) {
            $raw = json_decode($r->getPieceIdentiteUrl(), true);
            if (is_array($raw)) {
                if (array_is_list($raw)) {
                    $ci['recto'] = $raw[0] ?? null;
                    $ci['verso'] = $raw[1] ?? null;
                } else {
                    $ci['recto'] = $raw['recto'] ?? null;
                    $ci['verso'] = $raw['verso'] ?? null;
                }
            }
        }

        $base = $this->getVarPrivateDir($id);
        foreach (['recto', 'verso'] as $k) {
            $p = sprintf('%s/piece_identite_%s.jpg', $base, $k);
            if (!$ci[$k] && is_file($p)) {
                $ci[$k] = $this->generateUrl('admin_rachats_ci', [
                    'id' => $id,
                    'kind' => $k,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        return $this->render('@SyliusAdmin/Rachat/ci_page.html.twig', [
            'r' => $r,
            'ci' => $ci,
        ]);
    }

    /* ============================================================
     *  SEND EMAIL
     * ============================================================ */

    #[Route('/{id}/send-email', name: 'send_email', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendEmail(int $id, Request $req, CsrfTokenManagerInterface $csrf): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rachat introuvable.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $token = new CsrfToken('send_email_' . $id, (string)$req->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $id]);
        }

        if (!$r->getEmail()) {
            $this->addFlash('error', 'Aucune adresse email client renseignée.');
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $id]);
        }

        $pdfUrl = (string)$r->getPdfUrl();
        if ($pdfUrl === '') {
            $this->addFlash('error', 'Aucun PDF généré pour ce rachat.');
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $id]);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $pathPart = parse_url($pdfUrl, PHP_URL_PATH) ?: $pdfUrl;
        $pdfPath = $projectDir . '/public' . $pathPart;

        if (!is_file($pdfPath)) {
            $this->addFlash('error', 'Le fichier PDF est introuvable sur le serveur.');
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $id]);
        }

        $store = $this->hib->getStores()[0] ?? [];
        $addr = $store['store_address'] ?? [];
        $shopName = $store['store_name'] ?? 'Votre boutique';

        $from = 'contact@multimedia-services.fr';

        $email = (new Email())
            ->from($from)
            ->to($r->getEmail())
            ->replyTo($addr['email'] ?? $from)
            ->subject(sprintf('Bon de cession n°%d', $r->getId()))
            ->text(sprintf(
                "Bonjour %s %s,\n\nVeuillez trouver en pièce jointe votre bon de cession pour le rachat de votre appareil %s.\n\nCordialement,\n%s",
                (string)$r->getPrenom(),
                (string)$r->getNom(),
                (string)$r->getMarqueModele(),
                (string)$shopName,
            ))
            ->attachFromPath($pdfPath, basename($pdfPath), 'application/pdf');

        try {
            $this->mailer->send($email);
            $this->addFlash('success', 'Email envoyé au client à l’adresse : ' . $r->getEmail());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors de l’envoi de l’email : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_rachats_index', ['enabled' => 1]);
    }

    /* ============================================================
     *  HIBOUTIK: create product + arrivage (1 ligne)
     *  -> fix: returnUrl défini AVANT usage
     * ============================================================ */

    #[Route('/{id}/hib-product-arrivage', name: 'create_hib_product_arrivage', methods: ['POST'])]
    public function createHibProductAndAddToArrivage(int $id): Response
    {
        $rachat = $this->em->getRepository(Rachat::class)->find($id);

        // ✅ URL retour (hash sur ligne)
        $returnUrl = $this->generateUrl('admin_rachats_index', ['enabled' => 1]) . '#rachat-' . $id;

        if (!$rachat) {
            $this->addFlash('error', 'Rachat introuvable.');
            return $this->redirect($returnUrl);
        }

        if ((int)$rachat->getHibSupplierId() <= 0 && !$rachat->getRevendeur()) {
            $this->addFlash('error', 'Impossible : aucun supplier Hiboutik lié à ce rachat (revendeur requis).');
            return $this->redirect($returnUrl);
        }

        if ($rachat->getHibInventoryInputId() > 0) {
            $this->addFlash('info', sprintf('Déjà ajouté à un arrivage Hiboutik (#%d).', $rachat->getHibInventoryInputId()));
            return $this->redirect($returnUrl);
        }

        try {
            $hibProductId = $this->ensureHiboutikProductForRachat($rachat, 3);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur création produit Hiboutik : ' . $e->getMessage());
            return $this->redirect($returnUrl);
        }

        $stockId = 1;

        $supplierId = (int)($rachat->getHibSupplierId() ?: 0);
        if ($supplierId <= 0) {
            if ($rachat->getRevendeur()) {
                $supplierId = (int)$this->sync->ensureSupplier($rachat->getRevendeur());
            } else {
                $supplierId = 3;
            }
            $rachat->setHibSupplierId($supplierId);
            $this->em->flush();
        }

        $nom = (string)($rachat->getRevendeur()?->getNom() ?? $rachat->getNom() ?? '');
        $prenom = (string)($rachat->getRevendeur()?->getPrenom() ?? $rachat->getPrenom() ?? '');

        $date = new \DateTimeImmutable('today');

        try {
            $input = $this->hib->getOrCreateDailyRachatInput(
                $stockId,
                $supplierId,
                $nom,
                $prenom,
                false,
                $date
            );

            if (!($input['ok'] ?? false) || empty($input['id'])) {
                $this->logger?->error('Hiboutik daily input FAIL', [
                    'stockId' => $stockId,
                    'supplierId' => $supplierId,
                    'input' => $input,
                    'hib_last' => $this->hib->getLastDebug(),
                ]);

                $this->addFlash('error', 'Impossible de créer ou récupérer l’arrivage Hiboutik.');
                return $this->redirect($returnUrl);
            }
        } catch (\Throwable $e) {
            $this->logger?->critical('Hiboutik daily input EXCEPTION', [
                'msg' => $e->getMessage(),
                'hib_last' => $this->hib->getLastDebug(),
            ]);

            $this->addFlash('error', 'Exception Hiboutik : ' . $e->getMessage());
            return $this->redirect($returnUrl);
        }

        $hibInputId = (int)$input['id'];

        $unitPrice = (float)str_replace(',', '.', (string)$rachat->getPrixAchat());

        $resLine = $this->hib->addProductToInventoryInput(
            $hibInputId,
            $hibProductId,
            1,
            $unitPrice
        );

        if (!($resLine['ok'] ?? false)) {
            $this->logger?->error('Hiboutik addProductToInventoryInput FAIL', [
                'hibInputId' => $hibInputId,
                'hibProductId' => $hibProductId,
                'resLine' => $resLine,
                'hib_last' => $this->hib->getLastDebug(),
            ]);

            $this->addFlash('error', sprintf(
                'Produit Hiboutik %d créé mais erreur ajout à l’arrivage #%d.',
                $hibProductId,
                $hibInputId
            ));

            return $this->redirect($returnUrl);
        }

        $rachat->setHibInventoryInputId($hibInputId);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Rachat #%d ➜ produit Hiboutik %d ajouté à l’arrivage du jour #%d (%s).',
            $rachat->getId(),
            $hibProductId,
            $hibInputId,
            ($input['created'] ?? false) ? 'créé' : 'existant'
        ));

        return $this->redirect($returnUrl);
    }

    /* ============================================================
     *  REVENDEUR: process / apply + copy CI
     * ============================================================ */

    #[Route('/{id}/process-revendeur', name: 'process_revendeur', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function processRevendeur(int $id, Request $req, CsrfTokenManagerInterface $csrf): Response
    {
        $backEnabled = (int)$req->get('_back_enabled', 1);

        $defaultReturn = $this->generateUrl('admin_rachats_index', [
            'enabled' => $backEnabled,
        ]) . '#rachat-' . $id;

        $return = (string)$req->get('_return', '');

        // sécurité retour interne
        if ($return !== '' && str_starts_with($return, '/admin/rachats')) {
            $base = explode('#', $return, 2)[0];
            $returnUrl = $base . '#rachat-' . $id;
        } else {
            $returnUrl = $defaultReturn;
        }

        $token = new CsrfToken('process_revendeur_' . $id, (string)$req->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirect($returnUrl);
        }

        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) {
            $this->addFlash('error', 'Rachat introuvable.');
            return $this->redirect($returnUrl);
        }

        if ($r->getRevendeur()) {
            $this->addFlash('success', 'Revendeur déjà lié à ce rachat.');
            return $this->redirect($returnUrl);
        }

        $revRepo = $this->em->getRepository(Revendeur::class);

        $nom = trim((string)$r->getNom());
        $prenom = trim((string)$r->getPrenom());
        $tel = trim((string)$r->getTelephone());
        $email = trim((string)$r->getEmail());

        $rev = null;
        if ($email !== '') $rev = $revRepo->findOneBy(['email' => $email]);
        if (!$rev && $tel !== '') $rev = $revRepo->findOneBy(['telephone' => $tel]);
        if (!$rev && ($nom !== '' || $prenom !== '')) {
            $rev = $revRepo->findOneBy(['nom' => $nom ?: null, 'prenom' => $prenom ?: null]);
        }
        if (!$rev) $rev = new Revendeur();

        $addr = trim((string)$r->getAdresse());
        $lines = $addr !== '' ? preg_split("/\R+/", $addr) : [];
        $a1 = trim((string)($lines[0] ?? ''));
        $a2 = trim((string)($lines[1] ?? ''));

        $rev->setNom($nom ?: null);
        $rev->setPrenom($prenom ?: null);
        $rev->setTelephone($tel ?: null);
        $rev->setEmail($email ?: null);

        $rev->setAdresse1($a1 ?: null);
        $rev->setAdresse2($a2 ?: null);
        $rev->setCodePostal($r->getCodePostal() ?: null);

        if (method_exists($rev, 'setVille')) $rev->setVille(null);
        $rev->setPays('France');

        $this->em->persist($rev);
        $this->em->flush();

        $supplierId = $this->sync->ensureSupplier($rev);

        $r->setRevendeur($rev);
        $r->setHibSupplierId($supplierId);

        if (method_exists($r, 'setVendorProcessedAt')) {
            $r->setVendorProcessedAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Revendeur OK : #%d → supplier Hiboutik #%d.',
            (int)$rev->getId(),
            (int)$supplierId
        ));

        return $this->redirect($returnUrl);
    }

    #[Route('/{id}/apply-revendeur', name: 'apply_revendeur', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function applyRevendeur(int $id, Request $req, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $token = new CsrfToken('apply_revendeur_' . $id, (string)$req->request->get('_token'));
        if (!$csrf->isTokenValid($token)) {
            return $this->json(['ok' => false, 'error' => 'CSRF'], 400);
        }

        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        $revId = (int)$req->request->get('revendeur_id', 0);
        $rev = $this->em->getRepository(Revendeur::class)->find($revId);
        if (!$rev) return $this->json(['ok' => false, 'error' => 'Revendeur introuvable'], 404);

        $r->setRevendeur($rev);

        $supplierId = $this->sync->ensureSupplier($rev);
        $r->setHibSupplierId($supplierId);

        if (method_exists($r, 'setVendorProcessedAt')) {
            $r->setVendorProcessedAt(new \DateTimeImmutable());
        }

        $r->setNom($rev->getNom());
        $r->setPrenom($rev->getPrenom());
        $r->setTelephone($rev->getTelephone());
        $r->setEmail($rev->getEmail());
        $r->setCodePostal($rev->getCodePostal());

        $addr = trim(implode("\n", array_filter([
            trim((string)$rev->getAdresse1()),
            trim((string)$rev->getAdresse2()),
        ])));
        $r->setAdresse($addr ?: null);

        $ci = ['recto' => null, 'verso' => null];
        $hasCi = (string)$r->getPieceIdentiteUrl() !== '';
        if (!$hasCi) {
            $ci = $this->copyCiFromLatestRachatOfRevendeur($rev, $r->getId());
            if ($ci['recto'] || $ci['verso']) {
                $r->setPieceIdentiteUrl(json_encode(array_filter($ci), JSON_UNESCAPED_SLASHES));
            }
        }

        $this->em->flush();

        return $this->json([
            'ok' => true,
            'revendeur_id' => $rev->getId(),
            'filled' => [
                'nom' => $r->getNom() ?? '',
                'prenom' => $r->getPrenom() ?? '',
                'telephone' => $r->getTelephone() ?? '',
                'email' => $r->getEmail() ?? '',
                'adresse' => $r->getAdresse() ?? '',
                'code_postal' => $r->getCodePostal() ?? '',
            ],
            'ci' => $ci,
        ]);
    }

    private function copyCiFromLatestRachatOfRevendeur(Revendeur $rev, int $currentRachatId): array
    {
        $repo = $this->em->getRepository(Rachat::class);

        $candidates = $repo->createQueryBuilder('r')
            ->andWhere('r.revendeur = :rev')->setParameter('rev', $rev)
            ->andWhere('r.id != :id')->setParameter('id', $currentRachatId)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()->getResult();

        $baseTarget = $this->getVarPrivateDir($currentRachatId);
        @mkdir($baseTarget, 0775, true);

        foreach ($candidates as $src) {
            /** @var Rachat $src */
            $baseSrc = $this->getVarPrivateDir((int)$src->getId());

            $out = ['recto' => null, 'verso' => null];
            foreach (['recto', 'verso'] as $k) {
                $pSrc = $baseSrc . "/piece_identite_{$k}.jpg";
                if (is_file($pSrc)) {
                    @copy($pSrc, $baseTarget . "/piece_identite_{$k}.jpg");
                    $out[$k] = $this->generateUrl('admin_rachats_ci', [
                        'id' => $currentRachatId,
                        'kind' => $k,
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                }
            }

            if ($out['recto'] || $out['verso']) return $out;
        }

        return ['recto' => null, 'verso' => null];
    }

    /* ============================================================
     *  ARRIVAGE BATCH (si tu l’utilises)
     * ============================================================ */

    #[Route('/arrivage/create', name: 'arrivage_create_batch', methods: ['POST'])]
    public function arrivageCreateBatch(Request $req): Response
    {
        $ids = $req->request->all('ids');
        $label = trim((string)$req->request->get('arrivage_label', 'Arrivage'));
        $backEnabled = (int)$req->request->get('_back_enabled', 1);

        if (!$ids) {
            $this->addFlash('error', 'Sélection requise pour créer un arrivage.');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $meta = $this->hib->getDefaultStoreMeta();
        $stockId = (int)($meta['stock_id'] ?? $meta['store_id'] ?? 1);

        $repo = $this->em->getRepository(Rachat::class);

        $groups = []; // supplierId => items

        foreach ($ids as $id) {
            $r = $repo->find((int)$id);
            if (!$r) continue;

            $supplierId = (int)($r->getHibSupplierId() ?: 0);
            if ($supplierId <= 0) {
                if ($r->getRevendeur()) {
                    $supplierId = (int)$this->sync->ensureSupplier($r->getRevendeur());
                    $r->setHibSupplierId($supplierId);
                } else {
                    $supplierId = 3;
                    $r->setHibSupplierId($supplierId);
                }
            }

            $hibProductId = (int)$this->ensureHiboutikProductForRachat($r, $supplierId);
            if ($hibProductId <= 0) continue;

            $groups[$supplierId][] = ['r' => $r, 'hibProductId' => $hibProductId];
        }

        if (!$groups) {
            $this->addFlash('error', 'Rachats introuvables / produits Hiboutik non créés.');
            return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
        }

        $created = [];

        foreach ($groups as $supplierId => $items) {
            $r0 = $items[0]['r'];

            $nom = $r0->getRevendeur()?->getNom() ?? $r0->getNom() ?? '';
            $prenom = $r0->getRevendeur()?->getPrenom() ?? $r0->getPrenom() ?? '';

            $isMulti = count($items) > 1;

            // ⚠️ cette méthode doit exister dans HiboutikClient
            $resDaily = $this->hib->ensureDailyRachatInput(
                $stockId,
                (int)$supplierId,
                (string)$nom,
                (string)$prenom,
                $isMulti
            );

            if (!($resDaily['ok'] ?? false) || empty($resDaily['id'])) {
                $this->addFlash('error', "Création/récup arrivage du jour impossible (supplier #$supplierId).");
                continue;
            }

            $hibInputId = (int)$resDaily['id'];
            $added = 0;

            foreach ($items as $it) {
                $res = $this->hib->addProductToInventoryInput($hibInputId, (int)$it['hibProductId'], 1);
                if (($res['ok'] ?? false)) $added++;
            }

            $created[] = sprintf(
                "#%d (%d ligne(s)) %s",
                $hibInputId,
                $added,
                ($resDaily['created'] ?? false) ? '(créé)' : '(existant)'
            );
        }

        $this->em->flush();

        if ($created) {
            $this->addFlash('success', 'Arrivage(s) créé(s) : ' . implode(' / ', $created));
        } else {
            $this->addFlash('error', 'Aucun arrivage n’a pu être créé.');
        }

        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    /* ============================================================
     *  HIBOUTIK helper: create product (safe IMEI -> barcode)
     * ============================================================ */

    private function ensureHiboutikProductForRachat(Rachat $r, int $defaultSupplierId = 3): int
    {
        if ($r->getHibProductId()) {
            $hibProductId = (int)$r->getHibProductId();

            $imei = trim((string)$r->getImei());
            if ($imei !== '') {
                $p = $this->hib->getProduct($hibProductId);
                $currentBarcode = trim((string)($p['product_barcode'] ?? ''));
                if ($currentBarcode === '') {
                    $resBarcode = $this->hib->trySetBarcodeSmart($hibProductId, $imei);
                    if (!($resBarcode['ok'] ?? true) && ($resBarcode['reason'] ?? '') === 'invalid_imei') {
                        $this->addFlash('warning', 'IMEI non envoyé à Hiboutik (invalide) : ' . ($resBarcode['error'] ?? ''));
                    }
                }
            }

            return $hibProductId;
        }

        $supplierId = $r->getHibSupplierId() ?: $defaultSupplierId;
        $supplyPrice = (float)str_replace(',', '.', (string)$r->getPrixAchat());

        $payload = [
            'product_model' => $r->getMarqueModele() ?: sprintf('Rachat #%d', $r->getId()),
            'product_supplier' => $supplierId,
            'product_supply_price' => $supplyPrice,
            'product_price' => $supplyPrice,
            'product_stock_management' => 1,
            'product_display_www' => 0,
            'product_arch' => 0,
            'products_ref_ext' => 'RACHAT-' . $r->getId(),
        ];

        $res = $this->hib->createProduct($payload);

        $data = is_array($res) ? ($res['data'] ?? $res) : null;

        $hibProductId = 0;
        if (is_array($data)) {
            if (isset($data['product_id'])) $hibProductId = (int)$data['product_id'];
            elseif (isset($data[0]['product_id'])) $hibProductId = (int)$data[0]['product_id'];
        }

        if ($hibProductId <= 0) {
            throw new \RuntimeException('Impossible de récupérer le product_id Hiboutik après création du produit.');
        }

        $r->setHibProductId($hibProductId);
        if (!$r->getHibSupplierId()) $r->setHibSupplierId($supplierId);
        $this->em->flush();

        $resBarcode = $this->hib->trySetBarcodeSmart($hibProductId, $r->getImei());
        if (!($resBarcode['ok'] ?? true) && ($resBarcode['reason'] ?? '') === 'invalid_imei') {
            $this->addFlash('warning', 'IMEI non envoyé à Hiboutik (invalide) : ' . ($resBarcode['error'] ?? ''));
        }

        return $hibProductId;
    }

    /* ============================================================
     *  HELPERS (files/images)
     * ============================================================ */

    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/$id";
    }

    private function companyLogoDataUri(): ?string
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/private/brand/logo-multimedia.png';
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'image/png';
        $bin = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }

    private function companySignDataUri(): ?string
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/private/brand/signature-multimedia.png';
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'image/png';
        $bin = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }

    private function gdLoad(string $path): array
    {
        $mime = strtolower((string)mime_content_type($path));
        if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) return [imagecreatefromjpeg($path), 'jpg'];
        if (str_contains($mime, 'png')) return [imagecreatefrompng($path), 'png'];
        if (str_contains($mime, 'webp') && function_exists('imagecreatefromwebp')) return [imagecreatefromwebp($path), 'webp'];
        throw new \RuntimeException('Type image non supporté (JPEG/PNG/WEBP).');
    }

    private function fixJpegOrientation(string $path, $gd): \GdImage
    {
        if (!function_exists('exif_read_data')) return $gd;

        $mime = strtolower((string)mime_content_type($path));
        if (!str_contains($mime, 'jpeg') && !str_contains($mime, 'jpg')) return $gd;

        $exif = @exif_read_data($path);
        $o = (int)($exif['Orientation'] ?? 1);

        return match ($o) {
            3 => imagerotate($gd, 180, 0),
            6 => imagerotate($gd, -90, 0),
            8 => imagerotate($gd, 90, 0),
            default => $gd,
        };
    }

    private function shrinkToJpegUnder(
        string $srcPath,
        string $dstPath,
        int $maxW = 2000,
        int $maxH = 2000,
        int $maxBytes = 1_000_000
    ): string {
        [$img, $kind] = $this->gdLoad($srcPath);
        $img = $this->fixJpegOrientation($srcPath, $img);

        $w = imagesx($img);
        $h = imagesy($img);

        $scale = min(1.0, $maxW / max(1, $w), $maxH / max(1, $h));

        if ($scale < 1.0) {
            $nw = max(1, (int)floor($w * $scale));
            $nh = max(1, (int)floor($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }

        $q = 85;
        do {
            imagejpeg($img, $dstPath, $q);
            $size = filesize($dstPath) ?: ($maxBytes + 1);
            $q -= 7;
            if ($q < 40) break;
        } while ($size > $maxBytes);

        imagedestroy($img);
        return $dstPath;
    }

    private function fileToDataUri(string $path): ?string
    {
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $bin = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }
}