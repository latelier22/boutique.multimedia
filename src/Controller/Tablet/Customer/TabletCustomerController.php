<?php

namespace App\Controller\Tablet\Customer;

use App\Service\HiboutikClient;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TabletCustomerController extends AbstractController
{
    private const COOKIE_NAME = 'kiosk_ok';

    public function __construct(
        private HiboutikClient $hib,
        private CacheItemPoolInterface $cache,
    ) {}

    private function isUnlocked(Request $request): bool
    {
        return $request->cookies->get(self::COOKIE_NAME) === '1';
    }

    // =========================================================
    // LISTE CLIENTS (10 derniers)
    // =========================================================
    #[Route('/tablet/customers', name: 'tablet_customer_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isUnlocked($request)) {
            return $this->redirectToRoute('tablet_index');
        }

        $q = trim((string)$request->query->get('q', ''));

        $customers = $this->hib->getCustomers();

        usort($customers, fn($a, $b) =>
            (int)($b['customers_id'] ?? 0) <=> (int)($a['customers_id'] ?? 0)
        );

        $customers = array_slice($customers, 0, 10);

        if ($q !== '') {
            $needle = mb_strtolower($q);

            $customers = array_values(array_filter($customers, function ($c) use ($needle) {
                return str_contains(
                    mb_strtolower(
                        ($c['first_name'] ?? '') . ' ' .
                        ($c['last_name'] ?? '') . ' ' .
                        ($c['phone'] ?? '') . ' ' .
                        ($c['email'] ?? '')
                    ),
                    $needle
                );
            }));
        }

        return $this->render('tablet/customer/index.html.twig', [
            'customers' => $customers,
            'q' => $q,
        ]);
    }

    // =========================================================
    // EDIT CLIENT (appel Hiboutik)
    // =========================================================
    #[Route('/tablet/customers/{id}/edit', name: 'tablet_customer_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        if (!$this->isUnlocked($request)) {
            return $this->redirectToRoute('tablet_index');
        }

        // 🔥 client complet (IMPORTANT)
        $customer = $this->hib->getCustomer($id);

        if (!$customer) {
            return new Response('Client introuvable', 404);
        }

        // 🔥 adresse
        $address = null;
        $addressId = null;

        if (!empty($customer['addresses'][0]['address_id'])) {
            $addressId = (int)$customer['addresses'][0]['address_id'];
            $address = $this->hib->getCustomerAddress($addressId);
        }

        // =====================================================
        // SAVE
        // =====================================================
        if ($request->isMethod('POST')) {

            // CLIENT
            $customerFields = array_filter([
                'last_name'  => trim($request->get('last_name')),
                'first_name' => trim($request->get('first_name')),
                'company'    => trim($request->get('company')),
                'email'      => trim($request->get('email')),
                'phone'      => trim($request->get('phone')),
                'country'    => trim($request->get('country')),
            ], fn($v) => $v !== '');

            if ($customerFields) {
                $this->hib->updateCustomerAttributes($id, $customerFields);
            }

            // ADRESSE
            $addressFields = [
                'address'  => trim($request->get('address_address')),
                'zip_code' => trim($request->get('address_zip_code')),
                'city'     => trim($request->get('address_city')),
                'state'    => trim($request->get('address_state')),
                'country'  => trim($request->get('address_country')),
            ];

            $hasAddress = array_filter($addressFields);

            if ($addressId) {
                if ($hasAddress) {
                    $this->hib->updateCustomerAddressAttributes($addressId, $addressFields);
                }
            } else {
                if ($hasAddress) {
                    $this->hib->createCustomerAddress(array_merge([
                        'customers_id' => $id,
                        'gender'       => '0',
                        'first_name'   => $customer['first_name'] ?? '',
                        'last_name'    => $customer['last_name'] ?? '',
                        'email'        => $customer['email'] ?? '',
                        'phone'        => $customer['phone'] ?? '',
                        'company'      => $customer['company'] ?? '',
                    ], $addressFields));
                }
            }

            return $this->redirectToRoute('tablet_customer_edit', ['id' => $id]);
        }

        return $this->render('tablet/customer/edit.html.twig', [
            'customer' => $customer,
            'address'  => $address,
        ]);
    }
}