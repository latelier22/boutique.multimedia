<?php

namespace App\Controller\Admin\Revendeur;

use App\Entity\Rachat;
use App\Entity\Revendeur;
use App\Service\RevendeurHiboutikSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    Request, Response, JsonResponse
};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/admin/revendeurs', name: 'admin_revendeurs_')]
final class RevendeurController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RevendeurHiboutikSync $sync,
        private CsrfTokenManagerInterface $csrf
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->em->getRepository(Revendeur::class)->findBy([], ['id' => 'DESC']);
        return $this->render('@SyliusAdmin/Revendeurs/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $req): Response
    {
        $v = new Revendeur();

        if ($req->isMethod('POST')) {
            $v->setNom($req->request->get('nom'));
            $v->setPrenom($req->request->get('prenom'));
            $v->setEmail($req->request->get('email'));
            $v->setTelephone($req->request->get('telephone'));
            $v->setAdresse1($req->request->get('adresse1'));
            $v->setAdresse2($req->request->get('adresse2'));
            $v->setCodePostal($req->request->get('code_postal'));
            $v->setVille($req->request->get('ville'));
            $v->setPays($req->request->get('pays') ?: 'France');

            $this->em->persist($v);
            $this->em->flush();

            $this->addFlash('success', 'Revendeur créé.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $v->getId()]);
        }

        return $this->render('@SyliusAdmin/Revendeurs/new.html.twig', ['v' => $v]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $req): Response
    {
        $v = $this->em->getRepository(Revendeur::class)->find($id);
        if (!$v) {
            throw $this->createNotFoundException('Revendeur introuvable');
        }

        if ($req->isMethod('POST')) {
            $v->setNom($req->request->get('nom'));
            $v->setPrenom($req->request->get('prenom'));
            $v->setEmail($req->request->get('email'));
            $v->setTelephone($req->request->get('telephone'));
            $v->setAdresse1($req->request->get('adresse1'));
            $v->setAdresse2($req->request->get('adresse2'));
            $v->setCodePostal($req->request->get('code_postal'));
            $v->setVille($req->request->get('ville'));
            $v->setPays($req->request->get('pays') ?: 'France');

            $this->em->flush();
            $this->addFlash('success', 'Revendeur mis à jour.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        // ✅ CI revendeur : fichiers stockés dans var/private/revendeurs/{id}/piece_identite_recto.jpg etc.
        $ci = ['recto' => null, 'verso' => null];
        $dir = $this->getPrivateRevendeurDir($id);

        if (is_file($dir . '/piece_identite_recto.jpg')) {
            $ci['recto'] = $this->generateUrl('admin_revendeurs_ci', [
                'id' => $id,
                'kind' => 'recto'
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        if (is_file($dir . '/piece_identite_verso.jpg')) {
            $ci['verso'] = $this->generateUrl('admin_revendeurs_ci', [
                'id' => $id,
                'kind' => 'verso'
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->render('@SyliusAdmin/Revendeurs/edit.html.twig', [
            'v' => $v,
            'ci' => $ci,
        ]);
    }

    #[Route('/{id}/sync-hiboutik', name: 'sync_hiboutik', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function syncHiboutik(int $id): Response
    {
        $v = $this->em->getRepository(Revendeur::class)->find($id);
        if (!$v) throw $this->createNotFoundException('Revendeur introuvable');

        $supplierId = $this->sync->ensureSupplier($v);
        $this->addFlash('success', 'Hiboutik OK : supplier_id=' . $supplierId);

        return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
    }

  #[Route('/search', name: 'search', methods: ['GET'])]
public function search(Request $req): JsonResponse
{
    $qRaw = trim((string)$req->query->get('q', ''));
    $qRaw = preg_replace('/\s+/', ' ', $qRaw);
    if ($qRaw === '') return $this->json([]);

    $qLower = mb_strtolower($qRaw);
    $digits = preg_replace('/\D+/', '', $qRaw);

    // ✅ garde-fous anti "résultats débiles"
    // - texte: min 3 caractères
    // - téléphone: min 8 chiffres
    $isPhoneSearch = ($digits !== '' && strlen($digits) >= 8);
    $isTextSearch  = (mb_strlen($qLower) >= 3);

    if (!$isPhoneSearch && !$isTextSearch) {
        return $this->json([]); // STOP : évite les résultats sans rapport
    }

    $terms = array_values(array_filter(preg_split('/\s+/', $qLower)));

    $qb = $this->em->createQueryBuilder()
        ->select('v')
        ->from(Revendeur::class, 'v');

    $or = $qb->expr()->orX();

    // ✅ TEL (seulement si recherche téléphone assez longue)
    if ($isPhoneSearch) {
        $telLike = '%' . implode('%', str_split($digits)) . '%';
        $or->add('v.telephone LIKE :telLike');
        $qb->setParameter('telLike', $telLike);
    }

    // ✅ EMAIL
    if ($isTextSearch) {
        $or->add('LOWER(v.email) LIKE :email');
        $qb->setParameter('email', '%'.$qLower.'%');
    }

    // ✅ NOM/PRENOM : split + match multi-mots
    if ($isTextSearch) {
        if (count($terms) === 1) {
            $or->add('(LOWER(v.nom) LIKE :t0 OR LOWER(v.prenom) LIKE :t0)');
            $qb->setParameter('t0', '%'.$terms[0].'%');
        } else {
            // cas Nom Prenom (ou Prenom Nom)
            $or->add('(LOWER(v.nom) LIKE :a AND LOWER(v.prenom) LIKE :b)');
            $or->add('(LOWER(v.nom) LIKE :b AND LOWER(v.prenom) LIKE :a)');
            $qb->setParameter('a', '%'.$terms[0].'%');
            $qb->setParameter('b', '%'.$terms[1].'%');

            // fallback : chaque mot doit exister dans nom OU prenom (AND)
            $and = $qb->expr()->andX();
            foreach ($terms as $i => $t) {
                $p = 'm'.$i;
                $and->add("(LOWER(v.nom) LIKE :$p OR LOWER(v.prenom) LIKE :$p)");
                $qb->setParameter($p, '%'.$t.'%');
            }
            $or->add($and);
        }
    }

    $qb->where($or)
       // ✅ bonus: résultat pertinent d’abord (si tu veux, sinon retire)
       ->orderBy('v.id', 'DESC')
       ->setMaxResults(20);

    $out = [];
    foreach ($qb->getQuery()->getResult() as $v) {
        /** @var Revendeur $v */
        $out[] = [
            'id' => $v->getId(),
            'label' => trim($v->getDisplayName().' — '.($v->getTelephone() ?: '').' — '.($v->getEmail() ?: '')),
        ];
    }

    return $this->json($out);
}



    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id'=>'\d+'])]
public function show(int $id): Response
{
    $v = $this->em->getRepository(Revendeur::class)->find($id);
    if (!$v) throw $this->createNotFoundException('Revendeur introuvable');

    // Tous les rachats de ce revendeur (tu gardes ça si tu veux les afficher)
    $rachats = $this->em->getRepository(Rachat::class)->createQueryBuilder('r')
        ->andWhere('r.revendeur = :v')->setParameter('v', $v)
        ->orderBy('r.id', 'DESC')
        ->getQuery()->getResult();

    // ✅ CI REVendeur uniquement (plus de lien avec le dernier rachat)
    $ci = ['recto' => null, 'verso' => null];

    $dir = $this->getParameter('kernel.project_dir') . "/var/private/revendeurs/" . $id;

    if (is_file($dir . '/piece_identite_recto.jpg')) {
        $ci['recto'] = $this->generateUrl('admin_revendeurs_ci', [
            'id' => $id,
            'kind' => 'recto'
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    if (is_file($dir . '/piece_identite_verso.jpg')) {
        $ci['verso'] = $this->generateUrl('admin_revendeurs_ci', [
            'id' => $id,
            'kind' => 'verso'
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    return $this->render('@SyliusAdmin/Revendeurs/show.html.twig', [
        'v' => $v,
        'ci' => $ci,
        'rachats' => $rachats,
    ]);
}


    // ─────────────────────────────────────────────────────────────
    // ✅ NOUVEAU : Afficher CI revendeur (stream)
    // ─────────────────────────────────────────────────────────────

    #[Route('/{id}/ci/{kind}', name: 'ci', methods: ['GET'], requirements: ['id' => '\d+', 'kind' => 'recto|verso'])]
    public function streamCi(int $id, string $kind): Response
    {
        $dir = $this->getPrivateRevendeurDir($id);
        $path = $dir . '/piece_identite_' . $kind . '.jpg';

        if (!is_file($path)) {
            throw $this->createNotFoundException('CI revendeur introuvable');
        }

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));
        return $resp;
    }

    // ─────────────────────────────────────────────────────────────
    // ✅ NOUVEAU : Upload / Remplacement CI revendeur
    // ─────────────────────────────────────────────────────────────

    #[Route('/{id}/upload-ci', name: 'upload_ci', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadCi(int $id, Request $req): Response
    {
        // CSRF
        $tokenValue = (string)$req->request->get('_token', '');
        $token = new CsrfToken('revendeur_ci_' . $id, $tokenValue);
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        $kind = (string)$req->request->get('kind', 'recto');
        if (!in_array($kind, ['recto', 'verso'], true)) {
            $this->addFlash('error', 'Type (kind) invalide.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        /** @var UploadedFile|null $file */
        $file = $req->files->get('ci');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        if (!$file->isValid()) {
            $this->addFlash('error', 'Upload invalide.');
            return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
        }

        $dir = $this->getPrivateRevendeurDir($id);
        @mkdir($dir, 0775, true);

        $dst = $dir . '/piece_identite_' . $kind . '.jpg';

        // Resize + JPEG sous 1 Mo
        $this->shrinkToJpegUnder(
            $file->getPathname(),
            $dst,
            maxW: 2000,
            maxH: 2000,
            maxBytes: 1_000_000
        );

        $this->addFlash('success', 'Pièce d’identité ' . $kind . ' remplacée.');
        return $this->redirectToRoute('admin_revendeurs_edit', ['id' => $id]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function getPrivateRevendeurDir(int $id): string
    {
        return $this->getParameter('kernel.project_dir') . "/var/private/revendeurs/" . $id;
    }

    /**
     * Convertit une image (png/jpg/webp/...) vers JPG, resize si besoin,
     * et baisse la qualité pour être sous maxBytes.
     */
    private function shrinkToJpegUnder(
        string $srcPath,
        string $dstJpgPath,
        int $maxW,
        int $maxH,
        int $maxBytes
    ): void {
        $data = @file_get_contents($srcPath);
        if ($data === false) {
            throw new \RuntimeException('Impossible de lire le fichier source');
        }

        $im = @imagecreatefromstring($data);
        if (!$im) {
            throw new \RuntimeException('Image illisible (format non supporté par GD)');
        }

        $w = imagesx($im);
        $h = imagesy($im);

        // fond blanc (si alpha)
        $scale = min($maxW / $w, $maxH / $h, 1.0);
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);

        $out = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);

        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);

        imagedestroy($im);

        // write temp, puis boucle qualité
        $tmp = $dstJpgPath . '.tmp';
        $q = 90;

        do {
            imagejpeg($out, $tmp, $q);
            clearstatcache(true, $tmp);
            $size = @filesize($tmp) ?: PHP_INT_MAX;
            $q -= 5;
        } while ($size > $maxBytes && $q >= 40);

        imagedestroy($out);

        @rename($tmp, $dstJpgPath);
        @chmod($dstJpgPath, 0664);
    }
}
