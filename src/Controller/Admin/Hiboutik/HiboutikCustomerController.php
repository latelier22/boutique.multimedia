<?php

namespace App\Controller\Admin\Hiboutik;

use App\Service\HiboutikClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Intervention\Intervention;

#[Route('/admin/hiboutik/customers', name: 'admin_hiboutik_customer_')]
final class HiboutikCustomerController extends AbstractController
{
    public function __construct(
        private HiboutikClient $hib,
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        $customers = $this->hib->getCustomers();

        if ($q !== '') {
            $needle = mb_strtolower($q);

            $customers = array_values(array_filter($customers, function ($c) use ($needle) {
                return str_contains(
                    mb_strtolower(implode(' ', [
                        $c['customers_id'] ?? '',
                        $c['last_name'] ?? '',
                        $c['first_name'] ?? '',
                        $c['company'] ?? '',
                        $c['email'] ?? '',
                        $c['phone'] ?? ''
                    ])),
                    $needle
                );
            }));
        }

        return $this->render('@SyliusAdmin/Hiboutik/Customers/index.html.twig', [
            'customers' => $customers,
            'q' => $q,
            'tablet_socket_token' => (string) $this->getParameter('tablet_socket_token'),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
public function edit(int $id, Request $request): Response
{
    $customer = $this->hib->getCustomer($id);

    $address = null;
    $addressId = null;

    if (!empty($customer['addresses'][0]['address_id'])) {
        $addressId = (int) $customer['addresses'][0]['address_id'];
        $address = $this->hib->getCustomerAddress($addressId);
    }

    if ($request->isMethod('POST')) {
        // ---------------- CLIENT ----------------
        $customerFields = [
            'last_name'  => trim((string) $request->request->get('last_name', '')),
            'first_name' => trim((string) $request->request->get('first_name', '')),
            'company'    => trim((string) $request->request->get('company', '')),
            'email'      => trim((string) $request->request->get('email', '')),
            'phone'      => trim((string) $request->request->get('phone', '')),
            'country'    => trim((string) $request->request->get('country', 'FRA')),
        ];

        $customerFields = array_filter(
            $customerFields,
            static fn($v) => $v !== ''
        );

        if ($customerFields) {
            $this->hib->updateCustomerAttributes($id, $customerFields);
        }

        // ---------------- ADRESSE ----------------
        $addressFields = [
            'address'  => trim((string) $request->request->get('address_address', '')),
            'zip_code' => trim((string) $request->request->get('address_zip_code', '')),
            'city'     => trim((string) $request->request->get('address_city', '')),
            'state'    => trim((string) $request->request->get('address_state', '')),
            'country'  => trim((string) $request->request->get('address_country', 'FRA')),
            'default'  => $request->request->getBoolean('address_default') ? '1' : '0',
        ];

        $hasAddressData =
            ($addressFields['address'] ?? '') !== '' ||
            ($addressFields['zip_code'] ?? '') !== '' ||
            ($addressFields['city'] ?? '') !== '' ||
            ($addressFields['state'] ?? '') !== '';

        if ($addressId) {
            if ($hasAddressData) {
                $payload = array_filter(
                    $addressFields,
                    static fn($v) => $v !== ''
                );

                $this->hib->updateCustomerAddressAttributes($addressId, $payload);
            }
        } else {
            if ($hasAddressData) {
                $payload = array_merge([
                    'customers_id' => $id,
                    'gender'       => '0',
                    'first_name'   => trim((string) ($request->request->get('first_name', $customer['first_name'] ?? ''))),
                    'last_name'    => trim((string) ($request->request->get('last_name', $customer['last_name'] ?? ''))),
                    'email'        => trim((string) ($request->request->get('email', $customer['email'] ?? ''))),
                    'phone'        => trim((string) ($request->request->get('phone', $customer['phone'] ?? ''))),
                    'company'      => trim((string) ($request->request->get('company', $customer['company'] ?? ''))),
                ], $addressFields);

                $this->hib->createCustomerAddress($payload);
            }
        }

        $this->addFlash('success', 'Client mis à jour.');

        return $this->redirectToRoute('admin_hiboutik_customer_edit', ['id' => $id]);
    }


    $interventions = $this->em
    ->getRepository(Intervention::class)
    ->createQueryBuilder('i')
    ->andWhere('i.hiboutikCustomerId = :cid')
    ->setParameter('cid', $id)
    ->orderBy('i.interventionNumber', 'DESC')
    ->addOrderBy('i.id', 'DESC')
    ->getQuery()
    ->getResult();

    return $this->render('@SyliusAdmin/Hiboutik/Customers/edit.html.twig', [
        'customer' => $customer,
        'address'  => $address,
        'interventions' => $interventions,
    ]);
}

#[Route('/create', name: 'create', methods: ['POST'])]
public function create(Request $request): Response
{
    $firstName = trim((string)$request->request->get('first_name', ''));
    $lastName  = trim((string)$request->request->get('last_name', ''));

    // if ($firstName === '' && $lastName === '') {
    //     $this->addFlash('error', 'Nom ou prénom requis.');
    //     return $this->redirectToRoute('admin_hiboutik_customer_index');
    // }

    $result = $this->hib->createCustomer([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'country'    => 'FRA',
    ]);

    if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
        $this->addFlash('error', 'Impossible de créer le client Hiboutik.');
        return $this->redirectToRoute('admin_hiboutik_customer_index');
    }

    $customerId = (int)$result['customer_id'];

    // Appel du endpoint Node qui push la tablette
    try {
        $nodeBase = (string)$this->getParameter('app.node_base_url'); // ex: https://api.multimedia-services.fr
        $secret   = (string)$this->getParameter('app.webhook_secret');

        $this->httpClient->request('GET', rtrim($nodeBase, '/') . '/hiboutik/actionlink/customer', [
            'query' => [
                'customer_id' => $customerId,
                'secret'      => $secret,
                'lang'        => 'fr',
            ],
        ]);
    } catch (\Throwable $e) {
        // on ne bloque pas la création si le push échoue
        $this->addFlash('error', 'Client créé, mais push tablette échoué : ' . $e->getMessage());
        return $this->redirectToRoute('admin_hiboutik_customer_edit', ['id' => $customerId]);
    }

    $this->addFlash('success', 'Client créé et envoyé sur la tablette.');

    return $this->redirectToRoute('admin_hiboutik_customer_edit', ['id' => $customerId]);
}


#[Route('/create-tablet', name: 'create_tablet', methods: ['POST'])]
public function createTablet(Request $request): JsonResponse
{
    $firstName = trim((string)$request->request->get('first_name', ''));
    $lastName  = trim((string)$request->request->get('last_name', ''));

    // if ($firstName === '' && $lastName === '') {
    //     return new JsonResponse([
    //         'ok' => false,
    //         'error' => 'Nom ou prénom requis.',
    //     ], 400);
    // }

    $result = $this->hib->createCustomer([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'country'    => 'FRA',
    ]);

    if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
        return new JsonResponse([
            'ok'    => false,
            'error' => 'Création Hiboutik impossible.',
            'debug' => $this->hib->getLastDebug(),
        ], 500);
    }

    $customerId = (int)$result['customer_id'];

    $url = $this->generateUrl('tablet_customer_edit', [
        'id' => $customerId,
    ], UrlGeneratorInterface::ABSOLUTE_URL);

    return new JsonResponse([
        'ok'             => true,
        'customer_id'    => $customerId,
        'url'            => $url,
        'edit_admin_url' => $this->generateUrl('admin_hiboutik_customer_edit', [
            'id' => $customerId,
        ]),
    ]);
}
    
}