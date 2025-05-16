<?php
// src/Controller/Admin/SearchImageController.php
namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SearchImageController extends AbstractController
{
    private string $googleApiKey;
    private string $googleCseId;

    public function __construct(string $googleApiKey, string $googleCseId)
    {
        $this->googleApiKey = $googleApiKey;
        $this->googleCseId  = $googleCseId;
    }

    /**
     * Affiche le formulaire + les résultats d’images.
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
        $start = ($page - 1) * $num + 1;

        // Demande title et snippet dans fields
        $url = sprintf(
            'https://www.googleapis.com/customsearch/v1?key=%s&cx=%s&searchType=image'
                . '&q=%s&num=%d&start=%d&fields=items(link,title,snippet),searchInformation(totalResults)',
            $this->googleApiKey,
            $this->googleCseId,
            urlencode($query),
            $num,
            $start
        );

        $response = @file_get_contents($url);
        if (false !== $response) {
            $data = json_decode($response, true);

            // On remonte maintenant un tableau d’objets { url, title, snippet }
            $images = array_map(fn(array $item) => [
                'url'     => $item['link'],
                'title'   => $item['title']   ?? '',
                'snippet' => $item['snippet'] ?? '',
            ], $data['items'] ?? []);

            // Calcul du nombre total de pages
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
     * Télécharge l’image passée en paramètre.
     *
     * @Route("/admin/download-image", name="admin_download_image", methods={"GET"})
     */
    public function downloadImage(Request $request): StreamedResponse
    {
        $url = $request->query->get('url', '');
        // Vous pouvez ajouter ici un filtrage de domaines autorisés
        if (!\in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])) {
            throw $this->createNotFoundException('URL invalide');
        }

        $filename = basename(parse_url($url, PHP_URL_PATH)) ?: 'image';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mimeMap = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];
        $mime = $mimeMap[strtolower($extension)] ?? 'application/octet-stream';

        $response = new StreamedResponse(function () use ($url) {
            $handle = @fopen($url, 'rb');
            if (false === $handle) {
                throw new \RuntimeException('Impossible d’ouvrir le flux');
            }
            while (!feof($handle)) {
                echo fread($handle, 1024);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");
        // optionnel : cache control
        $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');

        return $response;
    }

    /**
 * @Route("/admin/ajax/product/{id}/add-image", name="admin_ajax_add_product_image", methods={"POST"})
 */
public function ajaxAddProductImage(Request $request, ProductRepository $products, EntityManagerInterface $em, ImageUploaderInterface $uploader, int $id): JsonResponse
{
    $product = $products->find($id);
    if (!$product) {
        return $this->json(['error'=>'Produit introuvable'], 404);
    }

    $data = json_decode($request->getContent(), true);
    $url  = $data['url'] ?? '';
    if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'])) {
        return $this->json(['error'=>'URL invalide'], 400);
    }

    // Télécharge le flux et crée un ProductImage
    $temp = tmpfile();
    $stream = stream_get_meta_data($temp)['uri'];
    file_put_contents($stream, file_get_contents($url));

    $productImage = new ProductImage();
    $productImage->setFile(new UploadedFile($stream, basename($url)));
    $product->addImage($productImage);

    $em->persist($productImage);
    $em->flush();

    return $this->json(['success'=>true, 'imageId'=>$productImage->getId()]);
}

}
