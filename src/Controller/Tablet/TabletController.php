<?php

namespace App\Controller\Tablet;

use App\Entity\Rachat;
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


final class TabletController extends AbstractController
{
    private const COOKIE_NAME = 'kiosk_ok';

    public function __construct(
        private EntityManagerInterface $em,
        private CacheItemPoolInterface $cache,
        private RachatPdfGenerator $pdfGen,
        private HiboutikClient $hib,
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

    $cacheKey = 'tablet_products_v1';
    $item = $this->cache->getItem($cacheKey);

    if ($item->isHit()) {
        return new JsonResponse($item->get());
    }

    $url = 'https://api.multimedia-services.fr/api/products';
    $json = @file_get_contents($url);
    if (!$json) {
        return new JsonResponse(['ok' => false, 'error' => 'fetch_failed'], 502);
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return new JsonResponse(['ok' => false, 'error' => 'bad_format'], 502);
    }

    // filtre: uniquement dispo (optionnel)
    $data['data'] = array_values(array_filter($data['data'], fn($p) => (int)($p['stock_available'] ?? 0) === 1));

    $item->set($data);
    $item->expiresAfter(30); // 30s
    $this->cache->save($item);

    return new JsonResponse($data);
}
}