<?php

namespace App\Controller\Admin;

use App\Entity\Upload;
use App\Form\UploadType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UploadController extends AbstractController
{
     #[Route('/admin/upload', name: 'admin_upload')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $upload = new Upload();
        $form = $this->createForm(UploadType::class, $upload);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $file = $upload->getFile();

            if ($file) {

                $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads';
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }

                // Checkbox pour suppression fond blanc
                $removeWhite = (bool) $form->get('removeWhiteBg')->getData();

$tmpName = uniqid('up_', true);
$inputExt = $file->guessExtension() ?: 'jpg';
$inputPath = $targetDir . '/' . $tmpName . '.' . $inputExt;

$file->move($targetDir, basename($inputPath));

if ($removeWhite) {
    $newFilename = $tmpName . '.png';
    $outputPath = $targetDir . '/' . $newFilename;

    // Ajuste threshold : 30..70 (plus grand = plus tolérant)
    $this->removeBorderWhiteToTransparent($inputPath, $outputPath, 25);

    @unlink($inputPath);
} else {
    $newFilename = basename($inputPath);
}

                $upload->setFilename($newFilename);

                $em->persist($upload);
                $em->flush();

                $this->addFlash('success', 'Fichier enregistré : /uploads/' . $newFilename);

                return $this->redirectToRoute('admin_upload');
            }

            $this->addFlash('error', 'Aucun fichier reçu.');
        }

        return $this->render('@SyliusAdmin/Upload/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

private function removeBorderWhiteToTransparent(string $inputPath, string $outputPath, int $threshold = 45): void
{
    // Charge image (jpg/png/webp si dispo)
    $info = @getimagesize($inputPath);
    if (!$info) {
        throw new \RuntimeException("Image illisible");
    }

    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg':
            $im = imagecreatefromjpeg($inputPath);
            break;
        case 'image/png':
            $im = imagecreatefrompng($inputPath);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) {
                throw new \RuntimeException("WebP non supporté par GD");
            }
            $im = imagecreatefromwebp($inputPath);
            break;
        default:
            throw new \RuntimeException("Format non supporté: $mime");
    }

    if (!$im) {
        throw new \RuntimeException("Impossible de charger l'image");
    }

    $w = imagesx($im);
    $h = imagesy($im);

    // Convertit en truecolor + alpha
    if (!imageistruecolor($im)) {
        $tmp = imagecreatetruecolor($w, $h);
        imagecopy($tmp, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        $im = $tmp;
    }

    imagealphablending($im, false);
    imagesavealpha($im, true);

    $thr2 = $threshold * $threshold;

    $visited = array_fill(0, $w * $h, 0);

    // file FIFO (sans recursion)
    $qx = new \SplQueue();
    $qy = new \SplQueue();

    $isBg = function(int $x, int $y) use ($im, $w, $thr2): bool {
        $rgb = imagecolorat($im, $x, $y);
        $a = ($rgb & 0x7F000000) >> 24; // 0 opaque .. 127 transparent (GD)
        // si déjà transparent -> considérer fond
        if ($a >= 127) return true;

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        $dr = 255 - $r;
        $dg = 255 - $g;
        $db = 255 - $b;

        $d2 = $dr*$dr + $dg*$dg + $db*$db;
        return $d2 <= $thr2;
    };

    $push = function(int $x, int $y) use (&$visited, $w, $qx, $qy) {
        $idx = $y * $w + $x;
        if ($visited[$idx]) return;
        $visited[$idx] = 1;
        $qx->enqueue($x);
        $qy->enqueue($y);
    };

    // Enfile uniquement les pixels de bord "presque blancs"
    for ($x = 0; $x < $w; $x++) {
        if ($isBg($x, 0)) $push($x, 0);
        if ($isBg($x, $h-1)) $push($x, $h-1);
    }
    for ($y = 0; $y < $h; $y++) {
        if ($isBg(0, $y)) $push(0, $y);
        if ($isBg($w-1, $y)) $push($w-1, $y);
    }

    // Couleur transparente (alpha 127)
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);

    // BFS 4-connexe : ne rend transparent que ce qui est connecté aux bords
    while (!$qx->isEmpty()) {
        $x = $qx->dequeue();
        $y = $qy->dequeue();

        // rendre transparent
        imagesetpixel($im, $x, $y, $transparent);

        // voisins
        if ($x > 0 && $isBg($x-1, $y)) $push($x-1, $y);
        if ($x < $w-1 && $isBg($x+1, $y)) $push($x+1, $y);
        if ($y > 0 && $isBg($x, $y-1)) $push($x, $y-1);
        if ($y < $h-1 && $isBg($x, $y+1)) $push($x, $y+1);
    }

    // Export PNG transparent
    imagepng($im, $outputPath);
    imagedestroy($im);
}
}