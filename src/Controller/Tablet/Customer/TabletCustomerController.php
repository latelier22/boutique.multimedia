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

    $customer = $this->hib->getCustomer($id);

    if (!$customer) {
        return new Response('Client introuvable', 404);
    }

    $address = null;
    $addressId = null;

    if (!empty($customer['addresses'][0]['address_id'])) {
        $addressId = (int) $customer['addresses'][0]['address_id'];
        $address = $this->hib->getCustomerAddress($addressId);
    }

  if ($request->isMethod('POST')) {
    try {
        $lastName  = trim((string) $request->request->get('last_name', ''));
        $firstName = trim((string) $request->request->get('first_name', ''));
        $company   = trim((string) $request->request->get('company', ''));
        $email     = trim((string) $request->request->get('email', ''));
        $phone     = trim((string) $request->request->get('phone', ''));
        $country   = trim((string) $request->request->get('country', ''));

        $addressLine = trim((string) $request->request->get('address_address', ''));
        $zipCode     = trim((string) $request->request->get('address_zip_code', ''));
        $city        = trim((string) $request->request->get('address_city', ''));
        $state       = trim((string) $request->request->get('address_state', ''));
        $addrCountry = trim((string) $request->request->get('address_country', ''));

        // Vérification email déjà utilisé par un autre client
        if ($email !== '') {
            $allCustomers = $this->hib->getCustomers();

            foreach ($allCustomers as $c) {
                $otherId = (int) ($c['customers_id'] ?? 0);
                $otherEmail = trim((string) ($c['email'] ?? ''));

                if (
                    $otherId !== $id &&
                    $otherEmail !== '' &&
                    mb_strtolower($otherEmail) === mb_strtolower($email)
                ) {
                    throw new \RuntimeException('Cette adresse e-mail est déjà utilisée par un autre client.');
                }
            }
        }

        $customerFields = [
            'last_name'  => $lastName,
            'first_name' => $firstName,
            'company'    => $company,
            'email'      => $email,
            'phone'      => $phone,
            'country'    => $country,
        ];

        $this->hib->updateCustomerAttributes($id, $customerFields);

        $addressFields = [
            'address'  => $addressLine,
            'zip_code' => $zipCode,
            'city'     => $city,
            'state'    => $state,
            'country'  => $addrCountry !== '' ? $addrCountry : 'FRA',
        ];

        $hasAddress = false;
        foreach ($addressFields as $v) {
            if ($v !== '') {
                $hasAddress = true;
                break;
            }
        }

        if ($addressId) {
            if ($hasAddress) {
                $this->hib->updateCustomerAddressAttributes($addressId, $addressFields);
            }
        } else {
            if ($hasAddress) {
                $this->hib->createCustomerAddress([
                    'customers_id' => $id,
                    'gender'       => '0',
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'email'        => $email,
                    'phone'        => $phone,
                    'company'      => $company,
                    'address'      => $addressFields['address'],
                    'zip_code'     => $addressFields['zip_code'],
                    'city'         => $addressFields['city'],
                    'state'        => $addressFields['state'],
                    'country'      => $addressFields['country'],
                ]);
            }
        }

        // Vérification réelle après sauvegarde
        $reloadedCustomer = $this->hib->getCustomer($id);

        $savedEmail = trim((string) ($reloadedCustomer['email'] ?? ''));
        $savedPhone = trim((string) ($reloadedCustomer['phone'] ?? ''));

        if ($email !== '' && mb_strtolower($savedEmail) !== mb_strtolower($email)) {
            throw new \RuntimeException('L’adresse e-mail n’a pas pu être enregistrée. Elle existe peut-être déjà.');
        }

        if ($phone !== '' && $savedPhone !== $phone) {
            throw new \RuntimeException('Le téléphone n’a pas pu être enregistré.');
        }

        $this->addFlash('success', 'Informations enregistrées.');

        return $this->redirectToRoute('tablet_customer_edit', [
            'id' => $id,
            'saved' => 1,
        ]);
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur enregistrement : ' . $e->getMessage());
        return $this->redirectToRoute('tablet_customer_edit', [
            'id' => $id,
        ]);
    }
}

    // Rechargement après sauvegarde pour avoir les vraies données Hiboutik
    $customer = $this->hib->getCustomer($id);

    $address = null;
    if (!empty($customer['addresses'][0]['address_id'])) {
        $addressId = (int) $customer['addresses'][0]['address_id'];
        $address = $this->hib->getCustomerAddress($addressId);
    }

    return $this->render('tablet/customer/edit.html.twig', [
        'customer' => $customer,
        'address'  => $address,
        'saved'    => (bool) $request->query->get('saved', false),
    ]);
}
}