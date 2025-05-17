<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product\Product;
use App\Entity\Product\ProductImage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class SearchImageController extends AbstractController
{
    private string $googleApiKey;
    private string $googleCseId;
    private ProductRepositoryInterface $productRepository;
    private EntityManagerInterface $entityManager;
    private ImageUploaderInterface $uploader;

    public function __construct(
        string $googleApiKey,
        string $googleCseId,
        ProductRepositoryInterface $productRepository,
        EntityManagerInterface $entityManager,
        ImageUploaderInterface $uploader
    ) {
        $this->googleApiKey       = $googleApiKey;
        $this->googleCseId        = $googleCseId;
        $this->productRepository  = $productRepository;
        $this->entityManager      = $entityManager;
        $this->uploader           = $uploader;
    }

    /**
     * Affiche la page complète (layout Admin) avec formulaire + résultats.
     *
     * @Route("/admin/image-search", name="admin_image_search", methods={"GET"})
     */
    public function imageSearch(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page  = max(1, (int) $request->query->get('page', 1));
        $num   = 10;
        $images = [];
        $pages  = 1;
        $start  = ($page - 1) * $num + 1;

        $url = sprintf(
            'https://www.googleapis.com/customsearch/v1?key=%s&cx=%s&searchType=image'
           .'&q=%s&num=%d&start=%d&fields=items(link,title,snippet),searchInformation(totalResults)',
            $this->googleApiKey,
            $this->googleCseId,
            urlencode($query),
            $num,
            $start
        );

        if (false !== $response = @file_get_contents($url)) {
            $data = json_decode($response, true);
            $images = array_map(fn(array $item) => [
                'url'     => $item['link'],
                'title'   => $item['title']   ?? '',
                'snippet' => $item['snippet'] ?? '',
            ], $data['items'] ?? []);
            $total = (int) ($data['searchInformation']['totalResults'] ?? 0);
            $pages = max(1, (int) ceil($total / $num));
        }

        return $this->render('@SyliusAdmin/Images/image_search.html.twig', [
            'query'  => $query,
            'images' => $images,
            'page'   => $page,
            'pages'  => $pages,
        ]);
    }

    /**
     * AJAX : renvoie **uniquement** les cards (fragment sans layout).
     *
     * @Route("/admin/image-search-ajax", name="admin_image_search_ajax", methods={"GET"})
     */
    public function imageSearchAjax(Request $request, ManagerRegistry $doctrine): Response
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException('Requête AJAX attendue');
        }

        $productId = (int) $request->query->get('productId', 0);
        $query     = trim((string) $request->query->get('q', ''));

        $repo    = $doctrine->getRepository(Product::class);
        $product = $repo->find($productId);

        if (null === $product) {
            throw $this->createNotFoundException('Produit introuvable');
        }

        $images = [];
        $num    = 10;
        $url = sprintf(
            'https://www.googleapis.com/customsearch/v1?key=%s&cx=%s&searchType=image'
           .'&q=%s&num=%d&fields=items(link,title,snippet)',
            $this->googleApiKey,
            $this->googleCseId,
            urlencode($query),
            $num
        );
        if (false !== $json = @file_get_contents($url)) {
            $data = json_decode($json, true);
            foreach ($data['items'] ?? [] as $item) {
                $images[] = [
                    'url'     => $item['link'],
                    'title'   => $item['title']   ?? '',
                    'snippet' => $item['snippet'] ?? '',
                ];
            }
        }

        return $this->render('@SyliusAdmin/Images/image_search_results.html.twig', [
            'images'  => $images,
            'product' => $product,
        ]);
    }

    // /**
    //  * AJAX : ajoute une image externe à un produit.
    //  *
    //  * @Route("/admin/ajax/product/{id}/add-image", name="admin_ajax_add_product_image", methods={"POST"})
    //  */
    // public function ajaxAddProductImage(Request $request, int $id): JsonResponse
    // {
    //     $product = $this->productRepository->find($id);
    //     if (null === $product) {
    //         return $this->json(['error' => 'Produit introuvable'], 404);
    //     }

    //     $data = json_decode((string) $request->getContent(), true);
    //     $url  = $data['url'] ?? '';
    //     if (!filter_var($url, FILTER_VALIDATE_URL)) {
    //         return $this->json(['error' => 'URL invalide'], 400);
    //     }

    //     $tmpPath = sys_get_temp_dir() . '/' . uniqid() . '.' . pathinfo($url, PATHINFO_EXTENSION);
    //     file_put_contents($tmpPath, file_get_contents($url));
    //     $uploadedFile = new UploadedFile($tmpPath, basename($tmpPath), null, null, true);

    //     $productImage = new ProductImage();
    //     $productImage->setFile($uploadedFile);
    //     $product->addImage($productImage);

    //     $this->uploader->upload($productImage);
    //     $this->entityManager->persist($productImage);
    //     $this->entityManager->flush();

    //     return $this->json(['success' => true, 'imageId' => $productImage->getId()]);
    // }

/**
 * @Route("/admin/ajax/product/{id}/add-image", name="admin_ajax_add_product_image", methods={"POST"})
 */
public function ajaxAddProductImage(Request $request, ProductRepositoryInterface $productRepository, EntityManagerInterface $em, ImageUploaderInterface $uploader, int $id): JsonResponse
{
    $product = $productRepository->find($id);
    if (!$product) {
        return $this->json(['error'=>'Produit introuvable'], 404);
    }

    // Cas 1: on reçoit un fichier “file” (collé depuis le presse-papier)
    if ($request->files->has('file')) {
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');
        if (0 !== strpos($uploadedFile->getMimeType(), 'image/')) {
            return $this->json(['error'=>'Ce n’est pas une image'], 400);
        }
    }
    // Cas 2: on reçoit un JSON { url: "https://…" } (recherche Google)
    else {
        $data = json_decode((string) $request->getContent(), true);
        if (!isset($data['url']) || !filter_var($data['url'], FILTER_VALIDATE_URL)) {
            return $this->json(['error'=>'Donnée invalide'], 400);
        }
        // on télécharge l’URL dans un tmp file
        $tmpPath = sys_get_temp_dir().'/'.uniqid().'.'.pathinfo($data['url'], PATHINFO_EXTENSION);
        file_put_contents($tmpPath, file_get_contents($data['url']));
        $uploadedFile = new UploadedFile($tmpPath, basename($tmpPath), null, null, true);
    }

    // Crée l’entité et upload physiquement
    $productImage = new ProductImage();
    $productImage->setFile($uploadedFile);
    $product->addImage($productImage);

    $uploader->upload($productImage);
    $em->persist($productImage);
    $em->flush();

    return $this->json(['success'=>true,'imageId'=>$productImage->getId()]);
}


    /**
     * Télécharge l’image passée en paramètre.
     *
     * @Route("/admin/download-image", name="admin_download_image", methods={"GET"})
     */
    public function downloadImage(Request $request): StreamedResponse
    {
        $url = (string) $request->query->get('url', '');
        if (!\in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw $this->createNotFoundException('URL invalide');
        }

        $filename  = basename(parse_url($url, PHP_URL_PATH)) ?: 'image';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mimeMap   = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
        $mime      = $mimeMap[strtolower($extension)] ?? 'application/octet-stream';

        return new StreamedResponse(function() use($url) {
            $handle = @fopen($url, 'rb');
            if (false === $handle) {
                throw new \RuntimeException('Impossible d’ouvrir le flux');
            }
            while (!feof($handle)) {
                echo fread($handle, 1024);
            }
            fclose($handle);
        }, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
        ]);
    }
}
