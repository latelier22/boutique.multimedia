<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat\Rachat;
use App\Service\HiboutikClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    BinaryFileResponse, JsonResponse, Request, Response
};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

#[Route('/admin/rachats/multi-wizard', name: 'admin_rachats_multi_wizard_')]
final class RachatMultiWizardController extends AbstractController
{
    private const SESSION_TOKEN_KEY = 'multi_wizard_upload_token';

    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hib,
    ) {}

    #[Route('', name: 'form', methods: ['GET', 'POST'])]
    public function form(Request $req): Response
    {
        // token stable dans la session (upload AJAX + submit final)
        $token = $req->getSession()->get(self::SESSION_TOKEN_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $req->getSession()->set(self::SESSION_TOKEN_KEY, $token);
        }

        if ($req->isMethod('GET')) {
            return $this->render('@SyliusAdmin/Rachat/multi_wizard.html.twig', [
                'upload_token' => $token,
                'upload_csrf'  => $this->container->get('security.csrf.token_manager')->getToken('multi_wizard_upload')->getValue(),
            ]);
        }

        // POST (final) : création des rachats + déplacement des fichiers tmp
        $postedToken = (string)$req->request->get('upload_token', '');
        if ($postedToken !== $token) {
            $this->addFlash('error', "Session d’upload expirée. Recharge la page et recommence.");
            return $this->redirectToRoute('admin_rachats_multi_wizard_form');
        }

        $tmpDir = $this->getTmpDir($token);
        $tmpCiRecto = $tmpDir . '/ci/recto.jpg';
        $tmpCiVerso = $tmpDir . '/ci/verso.jpg';

        // Champs vendeur
        $nom        = trim((string)$req->request->get('nom', ''));
        $prenom     = trim((string)$req->request->get('prenom', ''));
        $numeroCi   = trim((string)$req->request->get('numero_ci', ''));
        $telephone  = trim((string)$req->request->get('telephone', ''));
        $email      = trim((string)$req->request->get('email', ''));
        $adresse    = trim((string)$req->request->get('adresse', ''));
        $codePostal = trim((string)$req->request->get('code_postal', ''));
        $dateStr    = trim((string)$req->request->get('date_cession', ''));

        $dateCession = null;
        if ($dateStr !== '') {
            try { $dateCession = new \DateTimeImmutable($dateStr); } catch (\Throwable) {}
        }

        // Produits (avec mapping robuste via row_key[])
        $labels  = $req->request->all('marque_modele');
        $imeis   = $req->request->all('imei');
        $prices  = $req->request->all('prix_achat');
        $rowKeys = $req->request->all('row_key');

        if (!is_array($labels))  $labels = [];
        if (!is_array($imeis))   $imeis  = [];
        if (!is_array($prices))  $prices = [];
        if (!is_array($rowKeys)) $rowKeys = [];

        // Liste exacte des photos uploadées (évite les orphelins si on supprime des lignes)
        $photosUploaded = $req->request->all('photos_uploaded');
        if (!is_array($photosUploaded)) $photosUploaded = [];

        $createdIds = [];

        foreach ($labels as $i => $lib) {
            $lib = trim((string)$lib);
            $prix = (float) str_replace(',', '.', (string)($prices[$i] ?? 0));

            if ($lib === '' || $prix <= 0) continue;

            $imei   = trim((string)($imeis[$i] ?? ''));
            $rowKey = (string)($rowKeys[$i] ?? '');
            if ($rowKey === '') {
                $this->addFlash('error', "Erreur interne: row_key manquant (ligne " . ($i+1) . ").");
                return $this->redirectToRoute('admin_rachats_multi_wizard_form');
            }

            $r = (new Rachat())
                ->setMarqueModele($lib)
                ->setImei($imei)
                ->setPrixAchat(number_format($prix, 2, '.', ''))
                ->setNom($nom)
                ->setPrenom($prenom)
                ->setNumeroCi($numeroCi)
                ->setTelephone($telephone)
                ->setEmail($email)
                ->setAdresse($adresse)
                ->setCodePostal($codePostal)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setEnabled(true);

            if ($dateCession) $r->setDateCession($dateCession);

            $this->em->persist($r);
            $this->em->flush(); // ID

            $base = $this->getVarPrivateDir($r->getId());
            @mkdir($base, 0775, true);

            // --- CI : on copie la CI tmp vers ce rachat ---
            $urls = [];
            @mkdir($base, 0775, true);

            if (is_file($tmpCiRecto)) {
                copy($tmpCiRecto, $base . '/piece_identite_recto.jpg');
            }
            if (is_file($tmpCiVerso)) {
                copy($tmpCiVerso, $base . '/piece_identite_verso.jpg');
            }

            foreach (['recto', 'verso'] as $kind) {
                $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
                if (is_file($p)) {
                    $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
                        'id' => $r->getId(),
                        'kind' => $kind,
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                }
            }
            if ($urls) {
                $r->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));
            }

            // --- PHOTOS produit (par rowKey) ---
            $list = [];
            $names = $photosUploaded[$rowKey] ?? [];
            if (!is_array($names)) $names = [];

            if ($names) {
                $pdir = $base . '/photos';
                @mkdir($pdir, 0775, true);

                foreach ($names as $name) {
                    $name = (string)$name;
                    if ($name === '' || str_contains($name, '..') || str_contains($name, '/')) continue;

                    $src = $tmpDir . '/photos/' . $rowKey . '/' . $name;
                    if (!is_file($src)) continue;

                    $dst = $pdir . '/' . $name;
                    copy($src, $dst);
                    $list[] = $name;
                }
            }

            if ($list) {
                $r->setPhotosJson(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $this->em->flush();
            $createdIds[] = $r->getId();
        }

        if (!$createdIds) {
            $this->addFlash('error', 'Aucun produit valide saisi.');
            return $this->redirectToRoute('admin_rachats_multi_wizard_form');
        }

        // Nettoyage tmp + nouveau token pour prochaine saisie
        $this->rmDir($tmpDir);
        $req->getSession()->remove(self::SESSION_TOKEN_KEY);

        return $this->redirectToRoute('admin_rachats_multi_sign', [
            'ids' => implode(',', $createdIds),
        ]);
    }

    // =======================
    // AJAX Upload CI (recto/verso)
    // =======================
    #[Route('/upload-ci', name: 'upload_ci', methods: ['POST'])]
    public function uploadCi(Request $req): JsonResponse
    {
        $this->assertUploadAuthorized($req);

        $token = (string)$req->request->get('upload_token', '');
        $this->assertTokenMatchesSession($req, $token);

        $kind = (string)$req->request->get('kind', '');
        if (!in_array($kind, ['recto', 'verso'], true)) {
            return $this->json(['ok' => false, 'error' => 'Paramètre kind invalide.'], 400);
        }

        /** @var UploadedFile|null $file */
        $file = $req->files->get('ci');
        if (!$file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'Fichier manquant (ci).'], 400);
        }

        try {
            $this->assertAllowedImageFile($file);

            $dir = $this->getTmpDir($token) . '/ci';
            @mkdir($dir, 0775, true);

            $dst = $dir . '/' . $kind . '.jpg';
            $this->shrinkToJpegUnder($file->getPathname(), $dst, 2000, 2000, 1_000_000);

            $url = $this->generateUrl('admin_rachats_multi_wizard_tmp', [
                'token' => $token,
                'type'  => 'ci',
                'key'   => $kind,
                'file'  => $kind . '.jpg',
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            return $this->json(['ok' => true, 'url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // =======================
    // AJAX Upload Photo produit (par rowKey)
    // =======================
    #[Route('/upload-photo', name: 'upload_photo', methods: ['POST'])]
    public function uploadPhoto(Request $req): JsonResponse
    {
        $this->assertUploadAuthorized($req);

        $token = (string)$req->request->get('upload_token', '');
        $this->assertTokenMatchesSession($req, $token);

        $rowKey = (string)$req->request->get('row_key', '');
        if ($rowKey === '' || preg_match('/[^a-zA-Z0-9_-]/', $rowKey)) {
            return $this->json(['ok' => false, 'error' => 'row_key invalide.'], 400);
        }

        /** @var UploadedFile|null $file */
        $file = $req->files->get('photo');
        if (!$file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'Fichier manquant (photo).'], 400);
        }

        try {
            $this->assertAllowedImageFile($file);

            $dir = $this->getTmpDir($token) . '/photos/' . $rowKey;
            @mkdir($dir, 0775, true);

            $name = 'photo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
            $dst  = $dir . '/' . $name;

            $this->shrinkToJpegUnder($file->getPathname(), $dst, 2000, 2000, 1_000_000);

            $url = $this->generateUrl('admin_rachats_multi_wizard_tmp', [
                'token' => $token,
                'type'  => 'photos',
                'key'   => $rowKey,
                'file'  => $name,
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            return $this->json(['ok' => true, 'name' => $name, 'url' => $url]);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // =======================
    // Serveur de fichiers tmp (preview)
    // =======================
    #[Route('/tmp/{token}/{type}/{key}/{file}', name: 'tmp', methods: ['GET'])]
    public function tmp(Request $req, string $token, string $type, string $key, string $file): Response
    {
        $this->assertTokenMatchesSession($req, $token);

        if ($file === '' || str_contains($file, '..') || str_contains($file, '/')) {
            return new Response('Bad request', 400);
        }

        $base = $this->getTmpDir($token);

        if ($type === 'ci') {
            if (!in_array($key, ['recto','verso'], true)) return new Response('Not found', 404);
            $path = $base . '/ci/' . $file;
        } elseif ($type === 'photos') {
            if ($key === '' || preg_match('/[^a-zA-Z0-9_-]/', $key)) return new Response('Not found', 404);
            $path = $base . '/photos/' . $key . '/' . $file;
        } else {
            return new Response('Not found', 404);
        }

        if (!is_file($path)) return new Response('Not found', 404);

        return new BinaryFileResponse($path);
    }

    // =======================
    // Helpers
    // =======================

    private function assertUploadAuthorized(Request $req): void
    {
        $csrf = (string)$req->request->get('_token', '');
        $tm = $this->container->get('security.csrf.token_manager');
        if (!$tm->isTokenValid(new CsrfToken('multi_wizard_upload', $csrf))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function assertTokenMatchesSession(Request $req, string $token): void
    {
        $sessionToken = (string)$req->getSession()->get(self::SESSION_TOKEN_KEY, '');
        if ($token === '' || $token !== $sessionToken) {
            throw $this->createAccessDeniedException("Session d’upload expirée.");
        }
    }

    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/$id";
    }

    private function getTmpDir(string $token): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/_tmp/$token";
    }

    private function assertAllowedImageFile(UploadedFile $file): void
    {
        $path = $file->getPathname();
        $type = @exif_imagetype($path);

        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

        if (!$type || !in_array($type, $allowed, true)) {
            $name = $file->getClientOriginalName() ?: 'fichier';
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($ext, ['heic','heif'], true)) {
                throw new \RuntimeException("Format HEIC/HEIF refusé : $name. Merci d’envoyer JPG/PNG/WEBP.");
            }
            if ($ext === 'pdf') {
                throw new \RuntimeException("PDF refusé : $name. Merci d’envoyer JPG/PNG/WEBP.");
            }
            throw new \RuntimeException("Format refusé : $name. Formats acceptés : JPG / PNG / WEBP.");
        }
    }

    private function gdLoad(string $path): \GdImage
    {
        $type = @exif_imagetype($path);
        if (!$type) {
            $info = @getimagesize($path);
            $type = $info[2] ?? null;
        }

        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
                ? imagecreatefromwebp($path)
                : throw new \RuntimeException('WEBP non supporté par GD.'),
            default => throw new \RuntimeException('Type image non supporté.'),
        };
    }

    private function fixJpegOrientation(string $path, \GdImage $gd): \GdImage
    {
        if (!function_exists('exif_read_data')) return $gd;

        $type = @exif_imagetype($path);
        if ($type !== IMAGETYPE_JPEG) return $gd;

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
        $img = $this->gdLoad($srcPath);
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
            $w = $nw; $h = $nh;
        }

        // aplatit transparence sur blanc
        $flatten = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flatten, 255, 255, 255);
        imagefilledrectangle($flatten, 0, 0, $w, $h, $white);
        imagecopy($flatten, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $flatten;

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

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
