<?php

namespace App\Controller\Tablet;

use App\Entity\Rachat\Rachat;
use App\Service\HiboutikClient;
use App\Service\RachatPdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

use App\Entity\Rachat\RachatDossier;
use App\Service\Rachat\RachatDossierPdfGenerator;
use App\Service\Rachat\RachatDossierSignatureManager;


final class TabletController extends AbstractController
{
    private const COOKIE_NAME = 'kiosk_ok';

    public function __construct(
         private EntityManagerInterface $em,
    private CacheItemPoolInterface $cache,
    private RachatPdfGenerator $pdfGen,
    private HiboutikClient $hib,
    private RachatDossierPdfGenerator $dossierPdfGen,
    private RachatDossierSignatureManager $dossierSignatureManager,
    ) {}

    // ======================
    //  PIN / KIOSK
    // ======================

    #[Route('/tablet', name: 'tablet_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isUnlocked($request)) {
            return $this->redirectToRoute('tablet_wait', ['device' => 'TAB1']);
        }

        return $this->render('tablet/pin.html.twig', ['error' => null]);
    }

    #[Route('/tablet/unlock', name: 'tablet_unlock', methods: ['POST'])]
    public function unlock(Request $request): Response
    {
        $pin = trim((string) $request->request->get('pin', ''));
        $expected = (string) $this->getParameter('tablet_pin');

        if ($pin === '' || $pin !== $expected) {
            return $this->render('tablet/pin.html.twig', ['error' => 'PIN incorrect']);
        }

        $resp = $this->redirectToRoute('tablet_wait', ['device' => 'TAB1']);

        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue('1')
            ->withExpires(strtotime('+30 days'))
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('Lax');

        $resp->headers->setCookie($cookie);
        return $resp;
    }

    #[Route('/tablet/wait', name: 'tablet_wait', methods: ['GET'])]
    public function wait(Request $request): Response
    {
        if (!$this->isUnlocked($request)) {
            return $this->redirectToRoute('tablet_index');
        }

        $device = (string) $request->query->get('device', 'TAB1');

        return $this->render('tablet/wait.html.twig', [
            'device' => $device,
            'token'  => (string) $this->getParameter('tablet_socket_token'),
        ]);
    }

    #[Route('/tablet/logout', name: 'tablet_logout', methods: ['POST'])]
    public function logout(): Response
    {
        $resp = $this->redirectToRoute('tablet_index');

        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('Lax');

        $resp->headers->setCookie($cookie);
        return $resp;
    }

    private function isUnlocked(Request $request): bool
    {
        return $request->cookies->get(self::COOKIE_NAME) === '1';
    }

    // ======================
    //  SIGN : token one-shot
    // ======================

    private function assertTokenValid(int $id, string $token): void
    {
        if ($token === '') {
            throw $this->createAccessDeniedException('bad token');
        }

        $key  = 'tablet_sign_' . $id . '_' . $token;
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            throw $this->createAccessDeniedException('bad token');
        }
    }

    private function consumeToken(int $id, string $token): void
    {
        $key  = 'tablet_sign_' . $id . '_' . $token;
        $this->cache->deleteItem($key);
    }

    #[Route('/tablet/sign/{id}', name: 'tablet_sign', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sign(int $id, Request $req): Response
    {
        if (!$this->isUnlocked($req)) {
            return $this->redirectToRoute('tablet_index');
        }

        $token = (string) $req->query->get('token', '');
        $this->assertTokenValid($id, $token); // ✅ on NE consomme PAS ici (tu peux recharger la page)

        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return new Response('Rachat introuvable', 404);

        return $this->render('tablet/sign.html.twig', [
            'r'     => $r,
            'token' => $token,
        ]);
    }

    #[Route('/tablet/sign/{id}/submit', name: 'tablet_sign_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function signSubmit(int $id, Request $req): Response
    {
        if (!$this->isUnlocked($req)) {
            return $this->redirectToRoute('tablet_index');
        }

        $token = (string) $req->request->get('token', '');
        $this->assertTokenValid($id, $token);
        $this->consumeToken($id, $token); // ✅ one-shot = on consomme AU SUBMIT

        $r = $this->em->getRepository(Rachat::class)->find($id);
        if (!$r) return new Response('Rachat introuvable', 404);

        $dataUrl = (string) $req->request->get('signature_dataurl', '');
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return new Response('SIGNATURE MANQUENTE', 400);
        }

        $png = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1));

        // 1) Sauver signature.png
        $projectDir = $this->getParameter('kernel.project_dir');
        $pub = $projectDir . "/public/uploads/rachats/$id";
        @mkdir($pub, 0775, true);
        file_put_contents("$pub/signature.png", $png);
        $r->setSignatureUrl("/uploads/rachats/$id/signature.png");

        // 2) Construire les data URIs comme TON signSubmit admin (pour que le PDF intègre signature + CI + photos)
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

        $base = $projectDir . "/var/private/rachats/$id";

        // CI data
        $piecesIdentiteData = [];
        foreach (['recto','verso'] as $kind) {
            $p = "$base/piece_identite_$kind.jpg";
            if (is_file($p)) {
                $u = $this->fileToDataUri($p);
                if ($u) $piecesIdentiteData[] = $u;
            }
        }

        // Photos data
        $photosData = [];
        if ($r->getPhotosJson()) {
            foreach (array_slice(json_decode($r->getPhotosJson(), true) ?: [], 0, 4) as $name) {
                $p = "$base/photos/" . basename((string)$name);
                $u = $this->fileToDataUri($p);
                if ($u) $photosData[] = $u;
            }
        }

        // Seller sign data
        $sellerSigPath = $projectDir . '/public' . $r->getSignatureUrl();
        $sellerSigData = is_file($sellerSigPath) ? $this->fileToDataUri($sellerSigPath) : null;

        // Company logo/sign
        $companyLogo = $this->fileToDataUri($projectDir.'/var/private/brand/logo-multimedia.png');
        $companySign = $this->fileToDataUri($projectDir.'/var/private/brand/signature-multimedia.png');

        // 3) Générer PDF (avec les mêmes clés que ton admin)
        $ts = (new \DateTimeImmutable())->format('Ymd_His');
        $filename = sprintf('rachat-%d-%s.pdf', $id, $ts);

        $res = $this->pdfGen->generate([
            'r'                     => $r,
            'generated_at'          => new \DateTimeImmutable(),
            'shop'                  => $shop,
            'company_logo_data_uri' => $companyLogo,
            'company_sign_data_uri' => $companySign,
            'pieces_identite_data'  => $piecesIdentiteData,
            'photos_data_uris'      => $photosData,
            'seller_sign_data_uri'  => $sellerSigData,
        ], $filename);

        $r->setPdfUrl((string)($res['url'] ?? ''));
        $this->em->flush();

        return $this->render('tablet/after_sign.html.twig', [
            'r'        => $r,
            'pdf_url'  => (string)($res['url'] ?? ''),
            'wait_url' => $this->generateUrl('tablet_wait', ['device' => 'TAB1'], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    private function fileToDataUri(string $path): ?string
    {
        if (!is_file($path)) return null;
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $bin  = @file_get_contents($path);
        return $bin ? 'data:' . $mime . ';base64,' . base64_encode($bin) : null;
    }


#[Route('/tablet/products', name: 'tablet_products', methods: ['GET'])]
public function products(Request $request): JsonResponse
{
    if (!$this->isUnlocked($request)) {
        return new JsonResponse(['ok' => false, 'error' => 'locked'], 403);
    }

    $cacheKey = 'tablet_products_tablette_v3';
    $item = $this->cache->getItem($cacheKey);

    if ($item->isHit()) {
        return new JsonResponse($item->get());
    }

    $list = $this->fetchApiJson('https://api.multimedia-services.fr/api/productsByTag?tag=tablette');
    if (!is_array($list) || !isset($list['data']) || !is_array($list['data'])) {
        return new JsonResponse(['ok' => false, 'error' => 'fetch_failed'], 502);
    }

    $products = array_values(array_filter(
        $list['data'],
        static fn(array $p) => (int)($p['stock_available'] ?? 0) > 0
    ));

    $products = array_map(fn(array $p) => $this->enrichTabletProduct($p), $products);

    $data = [
        'ok' => true,
        'data' => $products,
    ];

    $item->set($data);
    $item->expiresAfter(30);
    $this->cache->save($item);

    return new JsonResponse($data);
}

private function fetchApiJson(string $url): ?array
{
    $json = @file_get_contents($url);
    if (!$json) {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

private function parseMiscRows(mixed $miscText): array
{
    if (!is_string($miscText) || trim($miscText) === '') {
        return [];
    }

    $rows = json_decode($miscText, true);
    return is_array($rows) ? $rows : [];
}

private function miscValue(array $rows, string $codeOrLabel): ?string
{
    $needle = mb_strtolower(trim($codeOrLabel));

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = mb_strtolower(trim((string)($row['code'] ?? '')));
        $label = mb_strtolower(trim((string)($row['label'] ?? '')));
        $value = trim((string)($row['value'] ?? ''));

        if (($code === $needle || $label === $needle) && $value !== '') {
            return $value;
        }
    }

    return null;
}

private function detectStateFromTags(array $tags): ?array
{
    $tags = array_map(static fn($v) => mb_strtolower((string)$v), $tags);

    if (in_array('neuf', $tags, true)) {
        return ['label' => 'NEUF', 'color' => '#2563eb'];
    }

    if (in_array('tres-bon-etat-reconditionne', $tags, true) || in_array('tres-bon-etat', $tags, true)) {
        return ['label' => 'TRÈS BON', 'color' => '#2e7d32'];
    }

    if (in_array('bon-etat', $tags, true)) {
        return ['label' => 'BON', 'color' => '#d97706'];
    }

    if (in_array('etat-correct', $tags, true) || in_array('defaut', $tags, true) || in_array('defauts', $tags, true)) {
        return ['label' => 'OK', 'color' => '#b91c1c'];
    }

    return null;
}


private function normalizeAttrCodeOrLabel(array $row): string
{
    $code = mb_strtolower(trim((string)($row['code'] ?? '')));
    $label = mb_strtolower(trim((string)($row['label'] ?? '')));

    return $code !== '' ? $code : $label;
}

private function buildLabelAttributes(array $miscRows): array
{
    $out = [];

    foreach ($miscRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string)($row['label'] ?? ''));
        $value = trim((string)($row['value'] ?? ''));
        $key   = $this->normalizeAttrCodeOrLabel($row);

        if ($label === '' || $value === '') {
            continue;
        }

        // on n'affiche pas ces champs ici
        if (in_array($key, ['garantie', 'stockage'], true)) {
            continue;
        }

        $out[] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    return array_values($out);
}


private function buildLabelBadges(array $tags, array $miscRows): array
{
    $badges = [];

    $garantie = $this->miscValue($miscRows, 'garantie');
    if ($garantie) {
        $badges[] = $garantie;
    }

    $couleur = $this->miscValue($miscRows, 'couleur');
    if ($couleur) {
        $badges[] = $couleur;
    }

    $os = $this->miscValue($miscRows, 'os');
    if ($os) {
        $badges[] = $os;
    }

    foreach ($tags as $tag) {
        $tag = trim((string)$tag);
        if ($tag === '') {
            continue;
        }

        $slug = mb_strtolower($tag);
        if (in_array($slug, [
            'tablette',
            'vitrine-droite',
            'vitrine-gauche',
            'vitrine-centre',
            'neuf',
            'tres-bon-etat',
            'tres-bon-etat-reconditionne',
            'bon-etat',
            'etat-correct',
        ], true)) {
            continue;
        }

        $badges[] = mb_convert_case(str_replace('-', ' ', $tag), MB_CASE_TITLE, 'UTF-8');
    }

    return array_values(array_slice(array_unique(array_filter($badges)), 0, 4));
}

private function enrichTabletProduct(array $product): array
{
    $id = (int)($product['product_id'] ?? 0);
    if ($id <= 0) {
        return $product;
    }

    $detailCacheKey = 'tablet_product_detail_' . $id;
    $detailItem = $this->cache->getItem($detailCacheKey);

    if ($detailItem->isHit()) {
        $detail = $detailItem->get();
    } else {
        $detail = $this->fetchApiJson('https://api.multimedia-services.fr/api/products/' . $id);
        if (is_array($detail)) {
            $detailItem->set($detail);
            $detailItem->expiresAfter(300);
            $this->cache->save($detailItem);
        }
    }

    $raw = is_array($detail['raw'] ?? null) ? $detail['raw'] : [];

    $tags = is_array($raw['tags_slug'] ?? null)
        ? $raw['tags_slug']
        : (is_array($product['tags_slug'] ?? null) ? $product['tags_slug'] : []);

    $miscRows = $this->parseMiscRows($raw['misc_text'] ?? null);
    $state = $this->detectStateFromTags($tags);

    $product['label_brand'] = trim((string)($raw['product_brand_name'] ?? $product['product_brand_name'] ?? ''));
    $product['label_storage'] = $this->miscValue($miscRows, 'stockage') ?: '';
    $product['label_state'] = $state['label'] ?? null;
    $product['label_state_color'] = $state['color'] ?? null;
    $product['label_footer'] = 'Multimédia Services';

    $product['label_guarantee'] = $this->miscValue($miscRows, 'garantie') ?: '1 AN';
    $product['label_attributes'] = $this->buildLabelAttributes($miscRows);

    return $product;
}

private function assertDossierTokenValid(int $id, string $token): void
{
    if ($token === '') {
        throw $this->createAccessDeniedException('bad token');
    }

    $key  = 'tablet_sign_dossier_' . $id . '_' . $token;
    $item = $this->cache->getItem($key);

    if (!$item->isHit()) {
        throw $this->createAccessDeniedException('bad token');
    }
}

private function consumeDossierToken(int $id, string $token): void
{
    $key = 'tablet_sign_dossier_' . $id . '_' . $token;
    $this->cache->deleteItem($key);
}


#[Route('/tablet/sign-dossier/{id}', name: 'tablet_sign_dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
public function signDossier(int $id, Request $req): Response
{
    if (!$this->isUnlocked($req)) {
        return $this->redirectToRoute('tablet_index');
    }

    $token = (string) $req->query->get('token', '');
    $this->assertDossierTokenValid($id, $token);

    $dossier = $this->em->getRepository(RachatDossier::class)->find($id);
    if (!$dossier) {
        return new Response('Dossier introuvable', 404);
    }

    return $this->render('tablet/sign_dossier.html.twig', [
        'dossier' => $dossier,
        'token' => $token,
        'generated_at' => new \DateTimeImmutable(),
    ]);
}

#[Route('/tablet/sign-dossier/{id}/submit', name: 'tablet_sign_dossier_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
public function signDossierSubmit(int $id, Request $req): Response
{
    if (!$this->isUnlocked($req)) {
        return $this->redirectToRoute('tablet_index');
    }

    $token = (string) $req->request->get('token', '');
    $this->assertDossierTokenValid($id, $token);
    $this->consumeDossierToken($id, $token);

    $dossier = $this->em->getRepository(RachatDossier::class)->find($id);
    if (!$dossier) {
        return new Response('Dossier introuvable', 404);
    }

    $dataUrl = (string) $req->request->get('signature_dataurl', '');
    if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
        return new Response('SIGNATURE MANQUANTE', 400);
    }

    $this->dossierSignatureManager->storeSignatureDataUrl($dossier, $dataUrl);

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

    $res = $this->dossierPdfGen->generate($dossier, [
        'shop' => $shop,
        'generated_at' => new \DateTimeImmutable(),
    ]);

    $dossier->setPdfUrl((string) ($res['url'] ?? ''));
    $this->em->flush();

    return $this->render('tablet/after_sign.html.twig', [
        'pdf_url'  => (string) ($res['url'] ?? ''),
        'wait_url' => $this->generateUrl('tablet_wait', ['device' => 'TAB1'], UrlGeneratorInterface::ABSOLUTE_URL),
        'r' => null,
        'dossier' => $dossier,
    ]);
}

}