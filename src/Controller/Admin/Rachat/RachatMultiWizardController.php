<?php

namespace App\Controller\Admin\Rachat;

use App\Entity\Rachat;
use App\Service\HiboutikClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Route('/admin/rachats/multi-wizard', name: 'admin_rachats_multi_wizard_')]
final class RachatMultiWizardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private HiboutikClient $hib,
    ) {}

    #[Route('', name: 'form', methods: ['GET', 'POST'])]
    public function form(Request $req): Response
    {
        if ($req->isMethod('GET')) {
            return $this->render('@SyliusAdmin/Rachat/multi_wizard.html.twig');
        }

        // --- Champs client ---
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
            try {
                $dateCession = new \DateTimeImmutable($dateStr);
            } catch (\Throwable) {
                $dateCession = null;
            }
        }

        // --- Tableaux produits ---
        $labels = $req->request->all('marque_modele');
        $imeis  = $req->request->all('imei');
        $prices = $req->request->all('prix_achat');

        if (!is_array($labels)) $labels = [];
        if (!is_array($imeis))  $imeis  = [];
        if (!is_array($prices)) $prices = [];

        /** @var UploadedFile|null $ciRecto */
        $ciRecto = $req->files->get('ci_recto');
        /** @var UploadedFile|null $ciVerso */
        $ciVerso = $req->files->get('ci_verso');

        // --- Validation format CI (on refuse HEIC etc) ---
        try {
            if ($ciRecto instanceof UploadedFile) $this->assertAllowedImage($ciRecto);
            if ($ciVerso instanceof UploadedFile) $this->assertAllowedImage($ciVerso);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_rachats_multi_wizard_form');
        }

        $createdIds = [];

        foreach ($labels as $i => $lib) {
            $lib = trim((string)$lib);
            $prix = (float) str_replace(',', '.', (string)($prices[$i] ?? 0));

            if ($lib === '' || $prix <= 0) {
                continue;
            }

            $imei = trim((string)($imeis[$i] ?? ''));

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

            if ($dateCession) {
                $r->setDateCession($dateCession);
            }

            $this->em->persist($r);
            $this->em->flush(); // ID

            $base = $this->getVarPrivateDir($r->getId());
            @mkdir($base, 0775, true);

            // --- CI pour ce rachat ---
            $urls = [];

            try {
                if ($ciRecto instanceof UploadedFile) {
                    $dst = $base . '/piece_identite_recto.jpg';
                    $this->shrinkToJpegUnder($ciRecto->getPathname(), $dst, 2000, 2000, 1_000_000);
                }
                if ($ciVerso instanceof UploadedFile) {
                    $dst = $base . '/piece_identite_verso.jpg';
                    $this->shrinkToJpegUnder($ciVerso->getPathname(), $dst, 2000, 2000, 1_000_000);
                }
            } catch (\RuntimeException $e) {
                // On supprime l’entité créée si on veut éviter les rachats “vides”
                $this->em->remove($r);
                $this->em->flush();

                $this->addFlash('error', 'Pièce d’identité : ' . $e->getMessage());
                return $this->redirectToRoute('admin_rachats_multi_wizard_form');
            }

            foreach (['recto', 'verso'] as $kind) {
                $p = sprintf('%s/piece_identite_%s.jpg', $base, $kind);
                if (is_file($p)) {
                    $urls[$kind] = $this->generateUrl('admin_rachats_ci', [
                        'id'   => $r->getId(),
                        'kind' => $kind,
                    ], UrlGeneratorInterface::ABSOLUTE_URL);
                }
            }
            if ($urls) {
                $r->setPieceIdentiteUrl(json_encode($urls, JSON_UNESCAPED_SLASHES));
            }

            // --- PHOTOS produit ---
            /** @var UploadedFile[] $photos */
            $photos = $req->files->all('photos_' . $i) ?? [];
            if (!is_array($photos)) $photos = [];

            if ($photos) {
                $pdir = $base . '/photos';
                @mkdir($pdir, 0775, true);

                $list = [];

                foreach ($photos as $pf) {
                    if (!$pf instanceof UploadedFile) continue;

                    try {
                        $this->assertAllowedImage($pf);

                        $name = 'photo_' . $i . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.jpg';
                        $dst  = $pdir . '/' . $name;
                        $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
                        $list[] = $name;
                    } catch (\RuntimeException $e) {
                        // On n’annule pas tout : on prévient et on continue
                        $this->addFlash(
                            'warning',
                            sprintf('Photo produit ligne %d ignorée : %s', $i + 1, $e->getMessage())
                        );
                        continue;
                    }
                }

                if ($list) {
                    $r->setPhotosJson(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            $this->em->flush();
            $createdIds[] = $r->getId();
        }

        if (!$createdIds) {
            $this->addFlash('error', 'Aucun produit valide saisi.');
            return $this->redirectToRoute('admin_rachats_multi_wizard_form');
        }

        return $this->redirectToRoute('admin_rachats_multi_sign', [
            'ids' => implode(',', $createdIds),
        ]);
    }

    // =======================
    // Helpers
    // =======================

    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/$id";
    }

    /**
     * Autorise uniquement : JPG/JPEG, PNG, WEBP.
     * Refuse explicitement HEIC/HEIF/PDF et tout le reste.
     */
    private function assertAllowedImage(UploadedFile $file): void
    {
        $path = $file->getPathname();
        $type = @exif_imagetype($path);

        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

        if (!$type || !in_array($type, $allowedTypes, true)) {
            $name = $file->getClientOriginalName() ?: 'fichier';
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($ext, ['heic', 'heif'], true)) {
                throw new \RuntimeException("Format HEIC/HEIF refusé : $name. Merci d’envoyer une image JPG/PNG/WEBP.");
            }
            if ($ext === 'pdf') {
                throw new \RuntimeException("PDF refusé : $name. Merci d’envoyer une image JPG/PNG/WEBP.");
            }

            throw new \RuntimeException("Format refusé : $name. Formats acceptés : JPG / PNG / WEBP.");
        }
    }

    private function gdLoad(string $path): array
    {
        $type = @exif_imagetype($path);
        if (!$type) {
            $info = @getimagesize($path);
            $type = $info[2] ?? null;
        }

        return match ($type) {
            IMAGETYPE_JPEG => [imagecreatefromjpeg($path), 'jpg'],
            IMAGETYPE_PNG  => [imagecreatefrompng($path), 'png'],
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
                ? [imagecreatefromwebp($path), 'webp']
                : throw new \RuntimeException('WEBP non supporté par GD sur ce serveur.'),
            default => throw new \RuntimeException('Type image non supporté. Formats acceptés : JPG / PNG / WEBP.'),
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
        [$img] = $this->gdLoad($srcPath);
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

            $w = $nw;
            $h = $nh;
        }

        // Aplatissement (PNG/WebP transparents) sur fond blanc avant JPEG
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
}
