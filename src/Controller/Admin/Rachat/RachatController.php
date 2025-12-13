<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat;
use App\Form\RachatType;
use App\Service\HiboutikClient;
use App\Service\RachatPdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
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
use App\Service\HiboutikInventoryClient;


#[Route('/admin/rachats', name: 'admin_rachats_')]
final class RachatController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hib,
        private MailerInterface $mailer, // 👈 AJOUT
        private HiboutikInventoryClient $inventory, // 👈 AJOUT
    ) {}

    // ========== LISTE ==========
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('admin_rachats_index', $request->request->all());
        }

        $columns = [
            'id',
            'enabled',
            'date_cession',
            'pdf_url',
            'marque_modele',
            'imei',
            'prix_achat',
            'nom',
            'prenom',
            'numero_ci',
            'piece_identite_url',
            'telephone',
            'email',
            'adresse',
            'code_postal',
            'hib_supplier_id',
            'hib_product_id',
            'paidMethod',
            'paidAt',
            'photos_json',
            'created_at',
        ];
        $enabled = $request->query->getInt('enabled', 1);

        $rows = $this->em->getRepository(Rachat::class)
            ->createQueryBuilder('r')
            ->andWhere('r.enabled = :e')->setParameter('e', (bool)$enabled)
            ->orderBy('r.id', 'DESC')->getQuery()->getArrayResult();

        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('d-m-Y');
                } elseif (is_array($v)) {
                    $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($v === null) {
                    $row[$k] = '';
                } else {
                    $row[$k] = (string)$v;
                }
            }
            $row['__photos_first'] = null;
$row['__photos_count'] = 0;
if (!empty($row['photos_json'])) {
    $arr = json_decode($row['photos_json'], true);
    if (is_array($arr) && $arr) {
        $row['__photos_count'] = count($arr);
        $row['__photos_first'] = $this->generateUrl('admin_rachats_photo', [
            'id'   => (int)($row['id'] ?? 0),
            'file' => basename((string)$arr[0]),
        ]);
    }
}

$row['__edit_url']  = $this->generateUrl('admin_rachats_edit',  ['id' => $row['id'] ?? '']);
$row['__autre_url'] = $this->generateUrl('admin_rachats_autre', ['id' => $row['id'] ?? '']);


            $reshaped = [];
            foreach ($columns as $c) {
                $reshaped[$c] = $row[$c] ?? '';
            }
            $reshaped['__photos_first'] = $row['__photos_first'];
$reshaped['__photos_count'] = $row['__photos_count'];
$reshaped['__edit_url']     = $row['__edit_url'];
$reshaped['__autre_url']    = $row['__autre_url'];
$row = $reshaped;

        }
        unset($row);

        // dump($rows,$columns);

        return $this->render('@SyliusAdmin/Rachat/index.html.twig', [
            'columns' => $columns,
            'rows'    => $rows,
            'enabled' => $enabled,
        ]);
    }

    // ========== CREATE ==========
    #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        // On crée d'abord pour avoir un ID utilisable tout de suite (uploads Ajax)
        if ($request->isMethod('GET')) {
            $rachat = new Rachat();
            $rachat->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($rachat);
            $this->em->flush();

            return $this->redirectToRoute('admin_rachats_edit', ['id' => $rachat->getId()]);
        }

        // (Post classique non utilisé ici, on redirige vers edit)
        return $this->redirectToRoute('admin_rachats_index');
    }

    // ========== EDIT ==========
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $form = $this->createForm(RachatType::class, $r);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $baseDir = $this->getVarPrivateDir($r->getId());
            @mkdir($baseDir, 0775, true);
            @mkdir($baseDir . '/photos', 0775, true);

            $baseDir = $this->getVarPrivateDir($r->getId());
            @mkdir($baseDir, 0775, true);

            // --- RECTO ---
            /** @var UploadedFile|null $recto */
            $recto = $form->get('pieceIdentiteRectoFile')->getData();
            if ($recto instanceof UploadedFile) {
                $dst = $baseDir . '/piece_identite_recto.jpg';
                $this->shrinkToJpegUnder($recto->getPathname(), $dst, 2000, 2000, 1_000_000);
            }

            // --- VERSO ---
            /** @var UploadedFile|null $verso */
            $verso = $form->get('pieceIdentiteVersoFile')->getData();
            if ($verso instanceof UploadedFile) {
                $dst = $baseDir . '/piece_identite_verso.jpg';
                $this->shrinkToJpegUnder($verso->getPathname(), $dst, 2000, 2000, 1_000_000);
            }

            // URL (recto ou verso disponibles)
            // Stocker un petit JSON listant les 2 fichiers présents
            $files = [];
            if (is_file("$baseDir/piece_identite_recto.jpg")) {
                $files[] = $this->generateUrl('admin_rachats_ci', ['id' => $r->getId(), 'kind' => 'recto']);
            }
            if (is_file("$baseDir/piece_identite_verso.jpg")) {
                $files[] = $this->generateUrl('admin_rachats_ci', ['id' => $r->getId(), 'kind' => 'verso']);
            }
            $r->setPieceIdentiteUrl(json_encode($files, JSON_UNESCAPED_SLASHES));

            $this->em->flush();



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
                    $dst  = $baseDir . '/photos/' . $name;
                    $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
                    $list[] = $name;
                }
                $r->setPhotosJson(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }



            $this->em->flush();
            $goSign = (bool) $request->request->get('_go_sign', false);

            $this->addFlash('success', 'Rachat mis à jour.');
            if ($goSign) {
                return $this->redirectToRoute('admin_rachats_sign', ['id' => $r->getId()]);
            }
            return $this->redirectToRoute('admin_rachats_edit', ['id' => $r->getId()]);
        }

        return $this->render('@SyliusAdmin/Rachat/edit.html.twig', [
            'rachat' => $r,
            'form'   => $form->createView(),
        ]);
    }

    // // ========== DELETE ==========
    // #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    // public function delete(int $id, Request $request): Response
    // {
    //     $r = $this->em->getRepository(Rachat::class)->find($id);
    //     if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        

    //     return $this->render('@SyliusAdmin/Rachat/index.html.twig', []);
    // }


// --- Procédure multi-achat ---
// dans RachatController

// src/Controller/Admin/Rachat/RachatController.php



#[Route('/multi', name: 'multi', methods: ['GET', 'POST'])]
public function multi(Request $req, RachatPdfGenerator $pdfGen, HiboutikClient $hib): Response
{
    // 1) Récup des IDs (venant de la liste : checkboxes ids[])
    $ids = $req->request->all('ids');
    if (!is_array($ids) || !$ids) {
        $this->addFlash('error', 'Aucun rachat sélectionné pour la procédure multi.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    $repo = $this->em->getRepository(Rachat::class);
    $rachats = [];
    foreach ($ids as $id) {
        if ($r = $repo->find((int)$id)) {
            $rachats[] = $r;
        }
    }

    if (!$rachats) {
        $this->addFlash('error', 'Rachats introuvables.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // Rachat de référence pour la pièce d’identité + adresse vendeur
    /** @var Rachat $main */
    $main = $rachats[0];

    // ------------- ÉTAPE 1 : affichage du formulaire multi (avec CI) -------------
    // On affiche le formulaire tant qu’on n’a pas reçu "do_multi"
    if (!$req->isMethod('POST') || !$req->request->get('do_multi')) {
        // On regarde si une CI existe déjà pour préafficher
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

        // Total pour info
        $total = 0.0;
        foreach ($rachats as $r) {
            $total += (float) str_replace(',', '.', (string)$r->getPrixAchat());
        }

        return $this->render('@SyliusAdmin/Rachat/multi.html.twig', [
            'rachats' => $rachats,
            'main'    => $main,
            'ids'     => array_map('intval', $ids),
            'ci'      => $ci,
            'total'   => $total,
        ]);
    }

    // ------------- ÉTAPE 2 : traitement CI + paiement + PDF multi -------------

    // 2.a) Gestion de la pièce d’identité recto/verso envoyée en multi
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

    // Reconstruction des URLs recto/verso (comme dans uploadCi / ciPage)
    foreach (['recto', 'verso'] as $kind) {
        $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
        if (is_file($p)) {
            $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
                'id'   => $main->getId(),
                'kind' => $kind,
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }
    if ($urls) {
        $main->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));
    }

    // 2.b) Paiement multi (un seul mouvement de caisse avec tous les IDs)
    $method     = (string)$req->request->get('method', 'ESP');
    $otherLabel = (string)$req->request->get('other_label', '');

    $meta     = $this->hib->getDefaultStoreMeta();
    $storeId  = (int)($meta['store_id']      ?? 1);
    $currency = (string)($meta['currency_code'] ?? 'EUR');

    $total = 0.0;
    $libProduits = [];
foreach ($rachats as $r) {
    $price = (float) str_replace(',', '.', (string)$r->getPrixAchat());
    $total += $price;

    $libProduits[] = sprintf(
        '%s %s€',
        (string)$r->getMarqueModele(),
        number_format($price, 2, ',', ' ')
    );
}

$idsStr         = implode(',', array_map(fn($r) => $r->getId(), $rachats));
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
        if (strtoupper($method) === 'AUTRE' && $otherLabel) {
            $suffix .= ' (' . $otherLabel . ')';
        }
        $comment .= $suffix;
    }

    $res = $hib->tillCashOut($storeId, $total, $currency, $comment);
    $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

    if (!$okHib) {
        $this->addFlash('error', 'Erreur Hiboutik lors de l’encaissement multi.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // On marque chaque rachat comme payé
    $now = new \DateTimeImmutable();
    foreach ($rachats as $r) {
        $r->setPaidMethod($method ?: 'ESP');
        $r->setPaidAt($now);
    }
    $this->em->flush();

    // 2.c) PDF multi (tu appelles ta méthode generateMulti)
    $store = $this->hib->getStores()[0] ?? [];
    $addr  = $store['store_address'] ?? [];
    $shop = [
        'name'    => $store['store_name'] ?? '',
        'address' => $addr['address'] ?? '',
        'zip'     => $addr['zippostal_code'] ?? '',
        'city'    => $addr['city'] ?? '',
        'phone'   => $addr['phone'] ?? '',
        'email'   => $addr['email'] ?? '',
        'site'    => 'https://shop.multimedia-services.fr',
    ];

    // Photos par rachat → data URIs pour le pdf_multi
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

    // CI convertie en data URIs pour le PDF
    $piecesIdentiteData = [];
    foreach (['recto','verso'] as $kind) {
        $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
        $u = $this->fileToDataUri($p);
        if ($u) $piecesIdentiteData[] = $u;
    }

    $ts       = (new \DateTimeImmutable())->format('Ymd_His');
    $filename = sprintf('rachat-multi-%s.pdf', $ts);

    $resPdf = $pdfGen->generateMulti([
        'rachats'              => $rachats,
        'main'                 => $main,
        'shop'                 => $shop,
        'generated_at'         => new \DateTimeImmutable(),
        'pieces_identite_data' => $piecesIdentiteData,
        'photos_by_rachat'     => $photosByRachat,
        'total'                => $total,
    ], $filename);

    $this->addFlash('success', 'Procédure multi effectuée. Total encaissé : '.number_format($total,2,',',' ').' €');

    // 👉 Lien vers la page de signature multi (si tu en as une) ou vers le PDF
    return $this->redirect($resPdf['url']);
}


// ========== SIGN MULTI (affichage) ==========
#[Route('/multi/sign', name: 'multi_sign', methods: ['GET'])]
public function multiSign(Request $req): Response
{
    $idsParam = (string) $req->query->get('ids', '');
    $ids = array_filter(array_map('intval', explode(',', $idsParam)));

    if (!$ids) {
        $this->addFlash('error', 'Aucun rachat sélectionné pour le bon multi.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    $repo = $this->em->getRepository(Rachat::class);
    /** @var Rachat[] $rachats */
    $rachats = [];
    foreach ($ids as $id) {
        if ($r = $repo->find($id)) {
            $rachats[] = $r;
        }
    }

    if (!$rachats) {
        $this->addFlash('error', 'Rachats introuvables pour le bon multi.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // Vérifier que c’est bien le même vendeur pour tous
    $first = $rachats[0];
    $nomRef    = (string) $first->getNom();
    $prenomRef = (string) $first->getPrenom();

    foreach ($rachats as $r) {
        if ((string)$r->getNom() !== $nomRef || (string)$r->getPrenom() !== $prenomRef) {
            $this->addFlash('error', 'Bon multi impossible : tous les rachats doivent avoir le même vendeur.');
            return $this->redirectToRoute('admin_rachats_index');
        }
    }

    // Infos boutique
    $store = $this->hib->getStores()[0] ?? [];
    $addr  = $store['store_address'] ?? [];
    $shop = [
        'name'              => $store['store_name'] ?? '',
        'company'           => $addr['company'] ?? '',
        'address'           => $addr['address'] ?? '',
        'zip'               => $addr['zippostal_code'] ?? '',
        'city'              => $addr['city'] ?? '',
        'country'           => $addr['country'] ?? '',
        'tax_number'        => $addr['tax_number'] ?? '',
        'company_number'    => $addr['company_number'] ?? '',
        'legal_status'      => $addr['legal_status'] ?? '',
        'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
        'code_naf'          => $addr['code_naf'] ?? '',
        'phone'             => $addr['phone'] ?? '',
        'email'             => $addr['email'] ?? '',
        'site'              => 'https://shop.multimedia-services.fr',
    ];

    // On utilise la même page de signature que le simple, mais adaptée au multi
    return $this->render('@SyliusAdmin/Rachat/sign_multi.html.twig', [
        'rachats'               => $rachats,
        'ids'                   => $ids,
        'ids_string'            => implode(',', $ids),
        'shop'                  => $shop,
        'company_logo_data_uri' => $this->companyLogoDataUri(),
        'company_sign_data_uri' => $this->companySignDataUri(),
        'generated_at'          => new \DateTimeImmutable(),
    ]);
}

// ========== SIGN MULTI SUBMIT ==========
#[Route('/multi/sign/submit', name: 'multi_sign_submit', methods: ['POST'])]
public function multiSignSubmit(Request $req, RachatPdfGenerator $pdfGen): Response
{
    $idsParam = (string) $req->request->get('ids', '');
    $ids = array_filter(array_map('intval', explode(',', $idsParam)));

    if (!$ids) {
        $this->addFlash('error', 'Aucun rachat pour le bon multi.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    $repo = $this->em->getRepository(Rachat::class);
    /** @var Rachat[] $rachats */
    $rachats = [];
    foreach ($ids as $id) {
        if ($r = $repo->find($id)) {
            $rachats[] = $r;
        }
    }

    if (!$rachats) {
        $this->addFlash('error', 'Rachats introuvables.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // Vérif même vendeur
    $first = $rachats[0];
    $nomRef    = (string) $first->getNom();
    $prenomRef = (string) $first->getPrenom();
    foreach ($rachats as $r) {
        if ((string)$r->getNom() !== $nomRef || (string)$r->getPrenom() !== $prenomRef) {
            $this->addFlash('error', 'Bon multi : vendeurs différents.');
            return $this->redirectToRoute('admin_rachats_index');
        }
    }

    // Récup DataURL de la signature
    $dataUrl = (string)$req->request->get('signature_dataurl', '');
    if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
        $this->addFlash('error', 'Signature manquante.');
        return $this->redirectToRoute('admin_rachats_multi_sign', [
            'ids' => implode(',', $ids),
        ]);
    }
    $png = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));

    $projectDir = $this->getParameter('kernel.project_dir');

    // On enregistre la même signature pour tous les rachats (ou seulement le 1er, au choix)
    foreach ($rachats as $r) {
        $pub = $projectDir . "/public/uploads/rachats/" . $r->getId();
        @mkdir($pub, 0775, true);
        file_put_contents("$pub/signature.png", $png);
        $r->setSignatureUrl("/uploads/rachats/" . $r->getId() . "/signature.png");
    }

    // Infos boutique
    $store = $this->hib->getStores()[0] ?? [];
    $addr  = $store['store_address'] ?? [];
    $shop = [
        'name'              => $store['store_name'] ?? '',
        'company'           => $addr['company'] ?? '',
        'address'           => $addr['address'] ?? '',
        'zip'               => $addr['zippostal_code'] ?? '',
        'city'              => $addr['city'] ?? '',
        'country'           => $addr['country'] ?? '',
        'tax_number'        => $addr['tax_number'] ?? '',
        'company_number'    => $addr['company_number'] ?? '',
        'legal_status'      => $addr['legal_status'] ?? '',
        'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
        'code_naf'          => $addr['code_naf'] ?? '',
        'phone'             => $addr['phone'] ?? '',
        'email'             => $addr['email'] ?? '',
        'site'              => 'https://shop.multimedia-services.fr',
    ];

    // CI : on prend la CI du premier rachat
    $baseFirst = $this->getVarPrivateDir($first->getId());
    $piecesIdentiteData = [];
    foreach (['recto', 'verso'] as $kind) {
        $p = sprintf('%s/piece_identite_%s.jpg', $baseFirst, $kind);
        if (is_file($p)) {
            $u = $this->fileToDataUri($p);
            if ($u) $piecesIdentiteData[] = $u;
        }
    }

    // Signature vendeur en data URI (on prend celle du premier)
    $sellerSigPath = $projectDir . '/public' . $first->getSignatureUrl();
    $sellerSigData = is_file($sellerSigPath) ? $this->fileToDataUri($sellerSigPath) : null;

    // 📷 Photos par produit → on construit une liste [ [r, photos[]], ... ]
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

        $items[] = [
            'r'      => $r,
            'photos' => $photosData,
        ];
    }

    $idsStr = implode('-', $ids);
    $ts = (new \DateTimeImmutable())->format('Ymd_His');
    $filename = sprintf('rachat-multi-%s-%s.pdf', $idsStr, $ts);

    // 🔥 Appel au nouveau template PDF multi
    $res = $pdfGen->generateMulti([
        'rachats_items'         => $items,
        'generated_at'          => new \DateTimeImmutable(),
        'shop'                  => $shop,
        'company_logo_data_uri' => $this->companyLogoDataUri(),
        'company_sign_data_uri' => $this->companySignDataUri(),
        'pieces_identite_data'  => $piecesIdentiteData,
        'seller_sign_data_uri'  => $sellerSigData,
        'ids_string'            => implode(', ', $ids),
    ], $filename);

    $pdfUrl = (string)($res['url'] ?? '');

    // On enregistre la même URL de PDF pour tous les rachats concernés
    foreach ($rachats as $r) {
        $r->setPdfUrl($pdfUrl);
    }
    $this->em->flush();

    $this->addFlash('success', sprintf(
        'Bon de cession multi généré pour les rachats %s.',
        implode(', ', $ids)
    ));

    return $this->render('@SyliusAdmin/Rachat/after_sign_multi.html.twig', [
        'ids'      => $ids,
        'pdf_url'  => $pdfUrl,
        'list_url' => $this->generateUrl('admin_rachats_index'),
    ]);
}


    // ========== AUTRE RACHAT (DUPLICATION VENDEUR SEUL) ==========
#[Route('/{id}/autre', name: 'autre', requirements: ['id' => '\d+'], methods: ['GET'])]
public function autre(int $id): Response
{
    $repo = $this->em->getRepository(Rachat::class);
    /** @var Rachat|null $orig */
    $orig = $repo->find($id);
    if (!$orig) {
        throw $this->createNotFoundException('Rachat introuvable');
    }

    $nouveau = new Rachat();

    // 🔁 INFOS VENDEUR UNIQUEMENT
    $nouveau
        ->setNom($orig->getNom())
        ->setPrenom($orig->getPrenom())
        ->setNumeroCi($orig->getNumeroCi())
        ->setTelephone($orig->getTelephone())
        ->setEmail($orig->getEmail())
        ->setAdresse($orig->getAdresse())
        ->setCodePostal($orig->getCodePostal())
        ->setHibSupplierId($orig->getHibSupplierId())
        // si tu as une ville ou autre champ vendeur, rajoute ici
        ->setEnabled(true)
        ->setCreatedAt(new \DateTimeImmutable());

    // ❌ ON NE COPIE PAS :
    // - marqueModele
    // - imei
    // - prixAchat
    // - hibProductId
    // - date_cession
    // - pdfUrl
    // - signatureUrl
    // - photosJson
    // - paidMethod / paidAt
    // etc.

    $this->em->persist($nouveau);
    $this->em->flush();

    // ✅ DUPLIQUER ÉVENTUELLEMENT LES FICHIERS DE PIÈCE D’IDENTITÉ
    $oldBase = $this->getVarPrivateDir($orig->getId());
    $newBase = $this->getVarPrivateDir($nouveau->getId());
    @mkdir($newBase, 0775, true);

    $ciUrls = [];
    foreach (['recto', 'verso'] as $kind) {
        $oldPath = sprintf('%s/piece_identite_%s.jpg', $oldBase, $kind);
        if (is_file($oldPath)) {
            @copy($oldPath, sprintf('%s/piece_identite_%s.jpg', $newBase, $kind));
            $ciUrls[$kind] = $this->generateUrl('admin_rachats_ci', [
                'id'   => $nouveau->getId(),
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

    // 👉 On envoie directement sur l’édition du nouveau rachat
    return $this->redirectToRoute('admin_rachats_edit', ['id' => $nouveau->getId()]);
}





    // ========== SIGN (affichage) ==========
    #[Route('/{id}/sign', name: 'sign', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sign(int $id): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        $store = $this->hib->getStores()[0] ?? [];
        $addr  = $store['store_address'] ?? [];
        $shop = [
            'name'              => $store['store_name'] ?? '',
            'company'           => $addr['company'] ?? '',
            'address'           => $addr['address'] ?? '',
            'zip'               => $addr['zippostal_code'] ?? '',
            'city'              => $addr['city'] ?? '',
            'country'           => $addr['country'] ?? '',
            'tax_number'        => $addr['tax_number'] ?? '',
            'company_number'    => $addr['company_number'] ?? '',
            'legal_status'      => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf'          => $addr['code_naf'] ?? '',
            'phone'             => $addr['phone'] ?? '',
            'email'             => $addr['email'] ?? '',
            'site'              => 'https://shop.multimedia-services.fr',
        ];

        // Photos produit (URLs absolues)
        $photos = [];
        if ($r->getPhotosJson()) {
            $names = json_decode($r->getPhotosJson(), true) ?: [];
            foreach (array_slice($names, 0, 4) as $name) {
                $photos[] = $this->generateUrl('admin_rachats_photo', [
                    'id'   => $r->getId(),
                    'file' => basename((string)$name),
                ], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        // ✅ CI (recto/verso) — accepte objet {recto,verso} ET ancien tableau [0,1]
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

        // dump($r);

        return $this->render('@SyliusAdmin/Rachat/sign.html.twig', [
            'r'                     => $r,
            'shop'                  => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'seller_sig_data_uri'   => $sellerSigData,
            'photos'                => $photos,
            'ci_urls'               => $ciUrls, // 👈 utilisé dans ton Twig
            'generated_at'          => new \DateTimeImmutable(),
        ]);
    }


    // ========== SIGN SUBMIT ==========
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
        $addr  = $store['store_address'] ?? [];
        $shop = [
            'name'              => $store['store_name'] ?? '',
            'company'           => $addr['company'] ?? '',
            'address'           => $addr['address'] ?? '',
            'zip'               => $addr['zippostal_code'] ?? '',
            'city'              => $addr['city'] ?? '',
            'country'           => $addr['country'] ?? '',
            'tax_number'        => $addr['tax_number'] ?? '',
            'company_number'    => $addr['company_number'] ?? '',
            'legal_status'      => $addr['legal_status'] ?? '',
            'non_assujetti_tva' => (string)($addr['non_assujetti_tva'] ?? ''),
            'code_naf'          => $addr['code_naf'] ?? '',
            'phone'             => $addr['phone'] ?? '',
            'email'             => $addr['email'] ?? '',
            'site'              => 'https://shop.multimedia-services.fr',
        ];

        $base = $this->getVarPrivateDir($r->getId());

        // CI en data URIs (recto/verso)
        $piecesIdentiteData = [];
        foreach (['recto', 'verso'] as $kind) {
            $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
            if (is_file($p)) {
                $u = $this->fileToDataUri($p);
                if ($u) $piecesIdentiteData[] = $u;
            }
        }

        // Photos produit -> data URIs
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
            'r'                     => $r,
            'generated_at'          => new \DateTimeImmutable(),
            'shop'                  => $shop,
            'company_logo_data_uri' => $this->companyLogoDataUri(),
            'company_sign_data_uri' => $this->companySignDataUri(),
            'pieces_identite_data'  => $piecesIdentiteData,   // <— ✅ recto + verso
            'photos_data_uris'      => $photosData,
            'seller_sign_data_uri'  => $sellerSigData,
        ], $filename);

        $r->setPdfUrl((string)($res['url'] ?? ''));
        $this->em->flush();

        return $this->render('@SyliusAdmin/Rachat/after_sign.html.twig', [
            'r'        => $r,                               // 👈 AJOUT
            'pdf_url'  => (string)($res['url'] ?? ''),
            'edit_url' => $this->generateUrl('admin_rachats_edit', ['id' => $id]),
            'list_url' => $this->generateUrl('admin_rachats_index'),
        ]);
    }


    // ========== STREAM CI ==========
    // ========== STREAM CI ==========
    #[Route('/{id}/ci/{kind}', name: 'ci', requirements: ['id' => '\d+', 'kind' => 'recto|verso'], methods: ['GET'])]
    public function streamCi(int $id, string $kind): BinaryFileResponse
    {
        $path = sprintf('%s/piece_identite_%s.jpg', $this->getVarPrivateDir($id), $kind);
        if (!is_file($path)) {
            throw $this->createNotFoundException("Pièce d’identité $kind introuvable");
        }
        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }



    // ========== STREAM PHOTO ==========
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

    // ========== HELPERS ==========
    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/$id";
    }

    private function companyLogoDataUri(): ?string
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/private/brand/logo-multimedia.png';
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'image/png';
        $bin  = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }

    private function companySignDataUri(): ?string
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/private/brand/signature-multimedia.png';
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'image/png';
        $bin  = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }

    private function gdLoad(string $path): array
    {
        $mime = strtolower((string) mime_content_type($path));
        if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) return [imagecreatefromjpeg($path), 'jpg'];
        if (str_contains($mime, 'png'))  return [imagecreatefrompng($path), 'png'];
        if (str_contains($mime, 'webp') && function_exists('imagecreatefromwebp'))
            return [imagecreatefromwebp($path), 'webp'];
        throw new \RuntimeException('Type image non supporté (JPEG/PNG/WEBP).');
    }

    private function fixJpegOrientation(string $path, $gd): \GdImage
    {
        if (!function_exists('exif_read_data')) return $gd;
        $mime = strtolower((string) mime_content_type($path));
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
            $size = filesize($dstPath) ?: $maxBytes + 1;
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
        $bin  = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }

    // --- QUICK (option) ---
    #[Route('/quick', name: 'quick', methods: ['GET', 'POST'])]
    public function quick(Request $req): Response
    {
        if ($req->isMethod('POST')) {
            $marqueModele = trim((string)$req->request->get('marque_modele', ''));
            $prixAchat    = (float)$req->request->get('prix_achat', 0);
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


// --- Annuler encaissement unitaire ---
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

    // Montant à rentrer en caisse (IN)
    $amount = (float) str_replace(',', '.', (string) $r->getPrixAchat());

    // Store + devise à partir de Hiboutik
    $meta    = $this->hib->getDefaultStoreMeta();
    $storeId = (int)($meta['store_id']      ?? 1);
    $currency= (string)($meta['currency_code'] ?? 'EUR');

    $comment = sprintf(
        'ANNULATION RACHAT %d / %s / %s %s',
        $r->getId(),
        (string)$r->getMarqueModele(),
        (string)$r->getNom(),
        (string)$r->getPrenom()
    );

    $res   = $hib->tillCashIn($storeId, $amount, $currency, $comment);
    $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

    if ($okHib) {
        $r->setPaidMethod(null);
        $r->setPaidAt(null);
        $this->em->flush();
        $this->addFlash('success', 'Paiement annulé pour le rachat n°' . $r->getId() . '.');
    } else {
        // pour debug rapide si ça coince encore :
        // dump($res); die;
        $this->addFlash('error', 'Erreur Hiboutik lors de l’annulation du paiement.');
    }

    return $this->redirectToRoute('admin_rachats_index');
}


#[Route('/{id}/add-to-monthly-arrivage', name: 'add_to_monthly_arrivage', methods: ['POST'])]
    public function addToMonthlyArrivage(int $id): Response
    {
        /** @var Rachat|null $rachat */
        $rachat = $this->em->getRepository(Rachat::class)->find($id);
        if (!$rachat) {
            $this->addFlash('error', 'Rachat introuvable.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $hibProductId = (int) $rachat->getHibProductId();
        if (!$hibProductId) {
            $this->addFlash('error', sprintf(
                'Rachat #%d : aucun produit Hiboutik lié.',
                $rachat->getId()
            ));
            return $this->redirectToRoute('admin_rachats_index');
        }

        $storeMeta = $this->hib->getDefaultStoreMeta();
        $storeId   = (int)($storeMeta['store_id'] ?? 1);

        // On récupère ou on crée l’arrivage mensuel côté Hiboutik
        $input = $this->inventory->getOrCreateMonthlyRachatInput($storeId, 3);
        if (!($input['ok'] ?? false) || empty($input['id'])) {
            $this->addFlash('error', 'Impossible de récupérer/créer l’arrivage mensuel dans Hiboutik.');
            return $this->redirectToRoute('admin_rachats_index');
        }

        $inventoryInputId = (int) $input['id'];
        $unitPrice = (float) str_replace(',', '.', (string)$rachat->getPrixAchat());

        $res = $this->hib->addProductToInventoryInput(
    $inventoryInputId,
    $hibProductId,
    1
);


        if (!($res['ok'] ?? false)) {
            $this->addFlash('error', sprintf(
                'Erreur Hiboutik en ajoutant le produit à l’arrivage mensuel (input #%d).',
                $inventoryInputId
            ));
        } else {
            $this->addFlash('success', sprintf(
                'Rachat #%d ajouté à l’arrivage mensuel #%d.',
                $rachat->getId(),
                $inventoryInputId
            ));
        }

        return $this->redirectToRoute('admin_rachats_index', ['enabled' => 1]);
    }




    
    // --- Encaissement en lot ---
   #[Route('/encaissement', name: 'encaissement_batch', methods: ['POST'])]
public function encaissementBatch(Request $request, HiboutikClient $hib): Response
{
    $ids         = $request->request->all('ids');
    $method      = (string) $request->request->get('method');
    $labelAutre  = (string) $request->request->get('other_label');
    $backEnabled = $request->request->getInt('_back_enabled', 1);

    if (!$ids) {
        $this->addFlash('error', 'Sélection requise');
        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    $repo = $this->em->getRepository(Rachat::class);

    $rachats      = [];
    $idsClean     = [];
    $total        = 0.0;
    $libProduits  = [];
    $first        = null;

    foreach ($ids as $id) {
        /** @var Rachat|null $r */
        $r = $repo->find((int) $id);
        if (!$r) {
            continue;
        }

        $rachats[]  = $r;
        $idsClean[] = $r->getId();

        $price = (float) str_replace(',', '.', (string) $r->getPrixAchat());
        $total += $price;

        // libelle + prix pour le commentaire
        $libProduits[] = sprintf(
            '%s %s€',
            (string) $r->getMarqueModele(),
            number_format($price, 2, ',', ' ')
        );

        if ($first === null) {
            $first = $r;
        }
    }

    if (!$rachats || !$first) {
        $this->addFlash('error', 'Rachats introuvables.');
        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    // Construire le commentaire comme AVANT :
    // RACHATS (276,277) total 2,00€ / a 1,00€ / b 1,00€ / le corre cyrille
    $idsStr         = implode(',', $idsClean);
    $libProduitsStr = implode(' / ', $libProduits);

    $comment = sprintf(
        'RACHATS MULTI (%s) total %s€ / %s / %s %s',
        $idsStr,
        number_format($total, 2, ',', ' '),
        $libProduitsStr,
        (string) $first->getNom(),
        (string) $first->getPrenom()
    );

    if ($method) {
        $suffix = ' — ' . $method;
        if (strtoupper($method) === 'AUTRE' && $labelAutre) {
            $suffix .= ' (' . $labelAutre . ')';
        }
        $comment .= $suffix;
    }

    // UN SEUL mouvement de caisse pour le total
    $storeId  = 1;
    $currency = 'EUR';

    $res   = $hib->tillCashOut($storeId, $total, $currency, $comment);
    $okHib = ($res['ok'] ?? false) || !empty($res['data']['till_id'] ?? null);

    if (!$okHib) {
        $this->addFlash('error', 'Erreur Hiboutik lors de l’encaissement multiple.');
        return $this->redirectToRoute('admin_rachats_index', ['enabled' => $backEnabled]);
    }

    // On marque TOUS les rachats comme payés
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


    // --- Encaissement unitaire ---
    #[Route('/{id}/encaissement', name: 'encaissement_one', methods: ['POST'])]
    public function encaissementOne(int $id, Request $request, HiboutikClient $hib): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        $method = (string)$request->request->get('method');
        $labelAutre = (string)$request->request->get('other_label');

        $storeId = 1;
        $currency = 'EUR';
        $amount = (float) str_replace(',', '.', (string)$r->getPrixAchat());
        $comment = sprintf('RACHAT %d / %s / %s %s', $r->getId(), (string)$r->getMarqueModele(), (string)$r->getNom(), (string)$r->getPrenom());
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

    // --- Toggle enabled ---
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

    // --- Upload CI (AJAX) ---
    #[Route('/{id}/upload-ci', name: 'upload_ci', methods: ['POST'])]
    public function uploadCi(int $id, Request $req): JsonResponse
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return $this->json(['ok' => false, 'error' => 'Rachat introuvable'], 404);

        $kind = (string)$req->request->get('kind', 'recto'); // 'recto' ou 'verso'
        /** @var UploadedFile|null $file */
        $file = $req->files->get('ci');
        if (!$file) return $this->json(['ok' => false, 'error' => 'Aucun fichier reçu'], 400);

        $base = $this->getVarPrivateDir($r->getId());
        @mkdir($base, 0775, true);

        // Compression + orientation
        $dst = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
        $this->shrinkToJpegUnder($file->getPathname(), $dst, 2000, 2000, 1_000_000);

        // MAJ champs DB (JSON {recto: url, verso: url})
        $urls = [];
        if ($r->getPieceIdentiteUrl()) {
            $old = json_decode($r->getPieceIdentiteUrl(), true);
            if (is_array($old)) $urls = $old;
        }
        $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
            'id'   => $id,
            'kind' => $kind,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $r->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));
        $this->em->flush();

        return $this->json([
            'ok'   => true,
            'kind' => $kind,
            'url'  => $urls[$kind],
            'msg'  => "Pièce $kind enregistrée",
        ]);
    }




    // --- Upload PHOTOS (AJAX) ---
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
            $dst  = $pdir . '/' . $name;
            $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
            $list[] = $name;
            $addedUrls[] = $this->generateUrl('admin_rachats_photo', [
                'id' => $id,
                'file' => $name
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
                'file' => $n
            ], UrlGeneratorInterface::ABSOLUTE_URL), $list),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) throw $this->createNotFoundException('Rachat introuvable');

        // Photos (absolues, limité à 12)
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

        // CI : accepte ancien format [recto, verso] et nouveau {recto, verso}
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
            'r'       => $r,
            'photos'  => $photos,
            'ci_urls' => $ciUrls,
        ]);
    }

    // ========== PAGE CI (recto/verso) ==========
#[Route('/{id}/ci-page', name: 'ci_page', requirements: ['id' => '\d+'], methods: ['GET'])]
public function ciPage(int $id): Response
{
    $r = $this->em->getRepository(Rachat::class)->find($id);
    if (!$r) throw $this->createNotFoundException('Rachat introuvable');

    // On reconstruit les URLs recto/verso à partir du stockage local si besoin
    $ci = ['recto' => null, 'verso' => null];

    // 1) si DB contient déjà des URLs absolues → on les utilise
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

    // 2) fallback : si fichiers présents en local
    $base = $this->getVarPrivateDir($id);
    foreach (['recto','verso'] as $k) {
        $p = sprintf('%s/piece_identite_%s.jpg', $base, $k);
        if (!$ci[$k] && is_file($p)) {
            $ci[$k] = $this->generateUrl('admin_rachats_ci', [
                'id' => $id,
                'kind' => $k
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }
    }

    return $this->render('@SyliusAdmin/Rachat/ci_page.html.twig', [
        'r'  => $r,
        'ci' => $ci,
    ]);
}


// ========== ENVOI EMAIL APRES SIGNATURE ==========
// ========== ENVOI EMAIL APRES SIGNATURE ==========
#[Route('/{id}/send-email', name: 'send_email', requirements: ['id' => '\d+'], methods: ['POST'])]
public function sendEmail(int $id, Request $req, CsrfTokenManagerInterface $csrf): Response
{
    $r = $this->em->getRepository(Rachat::class)->find($id);
    if (!$r) {
        $this->addFlash('error', 'Rachat introuvable.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    // ✅ CSRF
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

    // ✅ Chemin local du PDF à partir de l’URL ou du chemin stocké
    $projectDir = $this->getParameter('kernel.project_dir');
    $pathPart   = parse_url($pdfUrl, PHP_URL_PATH) ?: $pdfUrl;
    $pdfPath    = $projectDir . '/public' . $pathPart;

    if (!is_file($pdfPath)) {
        $this->addFlash('error', 'Le fichier PDF est introuvable sur le serveur.');
        return $this->redirectToRoute('admin_rachats_edit', ['id' => $id]);
    }

    // ✅ Infos boutique
    $store    = $this->hib->getStores()[0] ?? [];
    $addr     = $store['store_address'] ?? [];
    $shopName = $store['store_name'] ?? 'Votre boutique';

    // ✅ From FIXE : ton compte OVH (qui correspond au MAILER_DSN)
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
        // Si tu veux loguer :
        // $this->get('logger')->error('Erreur envoi mail rachat', ['exception' => $e]);
    }

    // Retour sur la page après signature
    return $this->redirectToRoute('admin_rachats_index', ['id' => $id]);
}

/**
 * Crée le produit Hiboutik pour un rachat (si pas encore fait)
 * et retourne son product_id Hiboutik.
 */
private function ensureHiboutikProductForRachat(Rachat $r, int $defaultSupplierId = 3): int
{
    // Si déjà lié à un produit Hiboutik, on ne recrée pas
    if ($r->getHibProductId()) {
        return (int) $r->getHibProductId();
    }

    // Fournisseur : on force 3 si rien sur le rachat
    $supplierId = $r->getHibSupplierId() ?: $defaultSupplierId;

    // Prix d’achat (format float propre)
    $supplyPrice = (float) str_replace(',', '.', (string) $r->getPrixAchat());

    // Payload minimaliste pour Hiboutik
    $payload = [
        'product_model'            => $r->getMarqueModele() ?: sprintf('Rachat #%d', $r->getId()),
        'product_supplier'         => $supplierId,
        'product_supply_price'     => $supplyPrice,
        'product_price'            => $supplyPrice,     // tu ajusteras si tu veux un prix de vente différent
        'product_stock_management' => 1,
        'product_display_www'      => 0,
        'product_arch'             => 0,
        'products_ref_ext'         => 'RACHAT-'.$r->getId(),
    ];

    $res = $this->hib->createProduct($payload);

    // APRÈS (remplacer le bloc "récup ID" par ceci)
$data = is_array($res) ? ($res['data'] ?? $res) : null;

$hibProductId = 0;
if (is_array($data)) {
    if (isset($data['product_id'])) {
        $hibProductId = (int) $data['product_id'];
    } elseif (isset($data[0]['product_id'])) {
        $hibProductId = (int) $data[0]['product_id'];
    }
}

    if ($hibProductId <= 0) {
        throw new \RuntimeException('Impossible de récupérer le product_id Hiboutik après création du produit.');
    }

    // On mémorise côté base locale
    $r->setHibProductId($hibProductId);
    if (!$r->getHibSupplierId()) {
        $r->setHibSupplierId($supplierId);
    }
    $this->em->flush();

    return $hibProductId;
}


#[Route('/{id}/hib-product-arrivage', name: 'create_hib_product_arrivage', methods: ['POST'])]
public function createHibProductAndAddToArrivage(int $id): Response
{
    /** @var Rachat|null $rachat */
    $rachat = $this->em->getRepository(Rachat::class)->find($id);
    if (!$rachat) {
        $this->addFlash('error', 'Rachat introuvable.');
        return $this->redirectToRoute('admin_rachats_index');
    }

    try {
        // 1️⃣ Créer (ou récupérer) le produit Hiboutik
        $hibProductId = $this->ensureHiboutikProductForRachat($rachat, 3);
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur lors de la création du produit Hiboutik : '.$e->getMessage());
        return $this->redirectToRoute('admin_rachats_edit', ['id' => $rachat->getId()]);
    }

    // 2️⃣ Récupérer / créer l’arrivage mensuel dans Hiboutik
    $meta    = $this->hib->getDefaultStoreMeta();
    $storeId = (int)($meta['store_id'] ?? 1);

    $input = $this->inventory->getOrCreateMonthlyRachatInput($storeId, 3);
    if (!($input['ok'] ?? false) || empty($input['id'])) {
        $this->addFlash('error', 'Impossible de récupérer/créer l’arrivage mensuel Hiboutik.');
        return $this->redirectToRoute('admin_rachats_edit', ['id' => $rachat->getId()]);
    }

    $inventoryInputId = (int) $input['id'];
    $unitPrice        = (float) str_replace(',', '.', (string)$rachat->getPrixAchat());

    // 3️⃣ Ajouter la ligne produit dans l’arrivage (👉 via HiboutikClient)
    $resLine = $this->hib->addProductToInventoryInput(
        $inventoryInputId,
        $hibProductId,
        1,
    );

    if (!($resLine['ok'] ?? false)) {
        dd($resLine);
        $this->addFlash('error', sprintf(
            'Produit Hiboutik %d créé mais erreur lors de l’ajout à l’arrivage #%d.',
            $hibProductId,
            $inventoryInputId
        ));
    } else {
        $this->addFlash('success', sprintf(
            'Rachat #%d ➜ produit Hiboutik %d, ajouté à l’arrivage mensuel #%d.',
            $rachat->getId(),
            $hibProductId,
            $inventoryInputId
        ));
    }

    return $this->redirectToRoute('admin_rachats_edit', ['id' => $rachat->getId()]);
}


}
