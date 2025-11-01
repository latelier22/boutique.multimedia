<?php

namespace App\Controller\Admin\Product;

use App\Message\DescribeProductMessage;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;


class ProductDescriptionController extends AbstractController
{
    #[Route('/admin/product/queue-descriptions', name: 'admin_product_queue_descriptions')]
    public function queueDescriptions(
        Request $request,
        ProductRepositoryInterface $productRepository,
        MessageBusInterface $bus
    ): RedirectResponse {
        $code = $request->query->get('from');

        $product = $productRepository->findOneBy(['code' => $code]);

        if (!$product) {
            $this->addFlash('error', "Produit introuvable.");
        } else {
            $bus->dispatch(new DescribeProductMessage($code));
            $this->addFlash('success', "La demande de description a été envoyée. Rafraîchissez la page pour voir les modifications.");
        }

        return $this->redirect($request->headers->get('referer') ?? '/admin/products/');
    }



    #[Route('/admin/taxons/describe/{code}', name: 'admin_taxon_describe')]
public function describeAllFromTaxon(
    string $code,
    TaxonRepositoryInterface $taxonRepository,
    ProductRepositoryInterface $productRepository,
    MessageBusInterface $messageBus
): Response {
    $taxon = $taxonRepository->findOneBy(['code' => $code]);

    if (!$taxon) {
        throw $this->createNotFoundException("Taxon $code not found.");
    }

    $products = $productRepository->findByTaxon($taxon);

    $queued = 0;
    foreach ($products as $product) {
        /** @var ProductInterface $product */
        if (!$product->getCode()) {
            continue;
        }

        $messageBus->dispatch(new DescribeProductMessage($product->getCode()));
        $queued++;
    }

    $this->addFlash('success', "$queued descriptions mises en file pour le taxon : $code");

    return $this->redirectToRoute('sylius_admin_product_index');
}

#[Route('/admin/products/taxon/describe/{id}', name: 'admin_taxon_describe_by_id')]
public function describeAllFromTaxonById(
    int $id,
    TaxonRepositoryInterface $taxonRepository,
    ProductRepositoryInterface $productRepository,
    MessageBusInterface $messageBus
): Response {
    $taxon = $taxonRepository->find($id);

    if (!$taxon) {
        throw $this->createNotFoundException("Taxon #$id introuvable.");
    }

    $products = $productRepository->findByTaxon($taxon); // tu dois avoir cette méthode dans ton repo

    $queued = 0;
    foreach ($products as $product) {
        if (!$product->getCode()) {
            continue;
        }

        $messageBus->dispatch(new DescribeProductMessage($product->getCode()));
        $queued++;
    }

    $this->addFlash('success', "$queued descriptions mises en file pour le taxon #$id");

    return $this->redirectToRoute('sylius_admin_product_per_taxon_index', [
        'taxonId' => $id,
    ]);
}


}
