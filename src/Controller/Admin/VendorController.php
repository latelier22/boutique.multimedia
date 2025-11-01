<?php
namespace App\Controller\Admin;

use App\Form\VendorType;
use App\Service\HiboutikClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/vendors', name: 'admin_vendors_')]
final class VendorController extends AbstractController
{
    public function __construct(private HiboutikClient $hib) {}

   #[Route('', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
    $res = $this->hib->listSuppliers();
    return $this->render('@SyliusAdmin/Vendors/index.html.twig', [
        'suppliers' => $res['data'] ?? [],
        'debug'     => $res['debug'] ?? $this->hib->getLastDebug(),
    ]);
}

#[Route('/{id}/toggle/{val}', name: 'toggle', methods: ['POST'], requirements: ['id'=>'\d+','val'=>'0|1'])]
public function toggle(int $id, int $val): Response
{
    $this->hib->toggleSupplier($id, $val);
    $this->addFlash('success', $val ? 'Vendeur activé.' : 'Vendeur désactivé.');
    return $this->redirectToRoute('admin_vendors_index');
}



    #[Route('/create', name: 'create', methods: ['GET','POST'])]
    public function create(Request $request): Response
    {
        $form = $this->createForm(VendorType::class, [
    'supplier_enabled'  => 1,
    'supplier_position' => 1,
    'supplier_ref_ext'  => 'RACHAT-',
], [
    'is_edit' => false, // <— important
]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $payload = $form->getData();
            $created = $this->hib->createSupplier($payload);

            if (!empty($created['supplier_id'])) {
                $this->addFlash('success', 'Vendeur créé (#'.$created['supplier_id'].').');
                return $this->redirectToRoute('admin_vendors_index');
            }
            $this->addFlash('error', 'Erreur création vendeur.');
        }

        return $this->render('@SyliusAdmin/Vendors/create.html.twig', [
            'form'  => $form->createView(),
            'debug' => $this->hib->getLastDebug(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET','POST'], requirements: ['id'=>'\d+'])]
public function edit(int $id, Request $request): Response
{
    $res = $this->hib->listSuppliers();          // ['ok'=>..., 'status'=>..., 'data'=>[...]]
    $list = $res['data'] ?? [];                  // <— PRENDRE data
    $current = null;
    foreach ($list as $s) {
        if ((int)($s['supplier_id'] ?? 0) === $id) { $current = $s; break; }
    }
    if (!$current) {
        $this->addFlash('error', 'Vendeur introuvable.');
        return $this->redirectToRoute('admin_vendors_index');
    }

    $data = [
        'supplier_name'     => $current['supplier_name']     ?? '',
        'supplier_email'    => $current['supplier_email']    ?? '',
        'supplier_contact'  => $current['supplier_contact']  ?? '',
        'supplier_address'  => $current['supplier_address']  ?? '',
        'supplier_url'      => $current['supplier_url']      ?? '',
        'supplier_enabled'  => (int)($current['supplier_enabled'] ?? 1),
        'supplier_position' => (int)($current['supplier_position'] ?? 1),
        'supplier_ref_ext'  => $current['supplier_ref_ext']  ?? '',
    ];

    $form = $this->createForm(VendorType::class, $data, [
        'is_edit' => true, // <— fige supplier_ref_ext
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $new = $form->getData();

        // MAJ attribut par attribut (exigence Hiboutik)
        $updatable = [
            'supplier_name','supplier_email','supplier_contact','supplier_address',
            'supplier_url','supplier_enabled','supplier_position',
        ];

        foreach ($updatable as $attr) {
            $old = $data[$attr] ?? null;
            $val = $new[$attr] ?? null;
            if ($val !== $old) {
                $this->hib->updateSupplierAttribute($id, $attr, $val);
            }
        }

        $this->addFlash('success', 'Vendeur mis à jour.');
        return $this->redirectToRoute('admin_vendors_index');
    }

    return $this->render('@SyliusAdmin/Vendors/edit.html.twig', [
        'id'     => $id,
        'form'   => $form->createView(),
        'vendor' => $current,
    ]);
}


    #[Route('/{id}/deactivate', name: 'deactivate', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function deactivate(int $id): Response
    {
        $this->hib->deactivateSupplier($id);
        $this->addFlash('success', 'Vendeur désactivé.');
        return $this->redirectToRoute('admin_vendors_index');
    }

    #[Route('/{id}/activate', name: 'activate', methods: ['POST'], requirements: ['id'=>'\d+'])]
    public function activate(int $id): Response
    {
        $this->hib->activateSupplier($id);
        $this->addFlash('success', 'Vendeur activé.');
        return $this->redirectToRoute('admin_vendors_index');
    }

    #[Route('/{id}/products', name: 'products', methods: ['GET'], requirements: ['id'=>'\d+'])]
public function products(int $id): Response
{
    $res = $this->hib->listProductsBySupplier($id);
    // On ne renvoie QUE la liste (tableau) pour que Array.isArray(...) soit true côté JS
    return $this->json($res['data'] ?? []);
}
}
