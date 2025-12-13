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
        private HiboutikClient $hib, // on ne l’utilise pas encore, mais il est là si besoin
    ) {}

    #[Route('', name: 'form', methods: ['GET', 'POST'])]
    public function form(Request $req): Response
    {
        // GET → afficher le formulaire vide
        if ($req->isMethod('GET')) {
            return $this->render('@SyliusAdmin/Rachat/multi_wizard.html.twig');
        }

        // POST → créer les rachats + rediriger vers la signature multi
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
            } catch (\Throwable $e) {
                $dateCession = null;
            }
        }

        // Tableaux des produits
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

        $createdIds = [];

        foreach ($labels as $i => $lib) {
            $lib = trim((string)$lib);
            $prix = (float) str_replace(',', '.', (string)($prices[$i] ?? 0));

            if ($lib === '' || $prix <= 0) {
                continue; // on ignore les lignes vides
            }

            $imei = trim((string)($imeis[$i] ?? ''));

            $r = new Rachat();
            $r
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
            $this->em->flush(); // pour avoir l’ID

            $base = $this->getVarPrivateDir($r->getId());
            @mkdir($base, 0775, true);

            // --- CI pour ce rachat ---
            $urls = [];

            if ($ciRecto instanceof UploadedFile) {
                $dst = $base . '/piece_identite_recto.jpg';
                $this->shrinkToJpegUnder($ciRecto->getPathname(), $dst, 2000, 2000, 1_000_000);
            }
            if ($ciVerso instanceof UploadedFile) {
                $dst = $base . '/piece_identite_verso.jpg';
                $this->shrinkToJpegUnder($ciVerso->getPathname(), $dst, 2000, 2000, 1_000_000);
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

            // --- PHOTOS pour ce produit ---
            /** @var UploadedFile[] $photos */
            $photos = $req->files->all('photos_' . $i) ?? [];
            if (!is_array($photos)) $photos = [];

            if ($photos) {
                $pdir = $base . '/photos';
                @mkdir($pdir, 0775, true);

                $list = [];
                foreach ($photos as $pf) {
                    if (!$pf instanceof UploadedFile) continue;
                    $name = 'photo_' . $i . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.jpg';
                    $dst  = $pdir . '/' . $name;
                    $this->shrinkToJpegUnder($pf->getPathname(), $dst, 2000, 2000, 1_000_000);
                    $list[] = $name;
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

        // 👉 On réutilise ta route de signature multi que tu as déjà
        return $this->redirectToRoute('admin_rachats_multi_sign', [
            'ids' => implode(',', $createdIds),
        ]);
    }

    // === helpers (copie allégée de ton RachatController) ===

    private function getVarPrivateDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/rachats/$id";
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
        imagerotate($img, 0, 0);
        imagedestroy($img);
        return $dstPath;
    }
}
