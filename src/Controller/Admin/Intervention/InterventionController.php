<?php

namespace App\Controller\Admin\Intervention;

use App\Entity\Intervention\Intervention;
use App\Service\BoiteManager;
use App\Service\HiboutikClient;
use App\Service\SensitiveDataCipher;
use App\Service\InterventionNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin/interventions', name: 'app_admin_intervention_')]
class InterventionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private InterventionNumberGenerator $numberGenerator,
        private SensitiveDataCipher $cipher,
        private HiboutikClient $hib,
        private BoiteManager $boiteManager,
    ) {}

#[Route('', name: 'index', methods: ['GET'])]
public function index(Request $request): Response
{
    $q = trim((string) $request->query->get('q', ''));
    $status = trim((string) $request->query->get('status', ''));

   $qb = $this->em->getRepository(Intervention::class)
    ->createQueryBuilder('i')
    ->leftJoin('i.boite', 'b')
    ->addSelect('b');

    if ($status !== '' && in_array($status, ['Ouverte', 'En attente', 'Terminée', 'Annulée'], true)) {
        $qb->andWhere('i.status = :status')
           ->setParameter('status', $status);
    }

    $qb
    ->addSelect("
        CASE
            WHEN i.status = 'Ouverte' THEN 1
            WHEN i.status = 'En attente' THEN 2
            WHEN i.status = 'Terminée' THEN 3
            WHEN i.status = 'Annulée' THEN 4
            ELSE 99
        END AS HIDDEN statusOrder
    ")
    ->orderBy('statusOrder', 'ASC')
    ->addOrderBy('i.interventionNumber', 'DESC')
    ->addOrderBy('i.id', 'DESC');

    $items = $qb->getQuery()->getResult();

    $hibCustomers = [];

    foreach ($items as $item) {
        $cid = $item->getHiboutikCustomerId();
        if (!$cid || isset($hibCustomers[$cid])) {
            continue;
        }

        try {
            $hibCustomers[$cid] = $this->hib->getCustomer($cid);
        } catch (\Throwable) {
            $hibCustomers[$cid] = null;
        }
    }

    if ($q !== '') {
        $qNorm = $this->normalizeText($q);

        $items = array_values(array_filter($items, function (Intervention $item) use ($qNorm, $hibCustomers, $q) {
            $haystacks = [
                $item->getCustomerLastName(),
                $item->getCustomerFirstName(),
                $item->getCustomerPhone(),
                $item->getDeviceLabel(),
                $item->getWorkToDo(),
                $item->getUnlockSummary(),
                $item->getBoite()?->getCode(),
            ];

            foreach ($haystacks as $value) {
                if ($value !== null && str_contains($this->normalizeText((string) $value), $qNorm)) {
                    return true;
                }
            }

            if (ctype_digit($q) && (int) $q === $item->getInterventionNumber()) {
                return true;
            }

            $cid = $item->getHiboutikCustomerId();
            $hc = $cid && isset($hibCustomers[$cid]) ? $hibCustomers[$cid] : null;

            if (is_array($hc)) {
                $company = (string) ($hc['company'] ?? '');
                if ($company !== '' && str_contains($this->normalizeText($company), $qNorm)) {
                    return true;
                }
            }

            return false;
        }));
    }

    return $this->render('@SyliusAdmin/Intervention/index.html.twig', [
        'items' => $items,
        'hibCustomers' => $hibCustomers,
        'filters' => [
            'q' => $q,
            'status' => $status,
        ],
        'tablet_socket_token' => (string) $this->getParameter('tablet_socket_token'),
    ]);
}


#[Route('/quick-create', name: 'quick_create', methods: ['POST'])]
public function quickCreate(): Response
{
    try {
        $result = $this->hib->createCustomer([
            'first_name' => '',
            'last_name'  => '',
            'phone'      => '',
            'country'    => 'FRA',
        ]);

        if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
            throw new \RuntimeException('Impossible de créer le client Hiboutik vide.');
        }

        $customerId = (int) $result['customer_id'];

        $hibCustomer = $this->hib->getCustomer($customerId);
        if (!$hibCustomer) {
            throw new \RuntimeException('Client Hiboutik créé mais introuvable.');
        }

        $number = $this->numberGenerator->next();

        $intervention = new Intervention();
        $intervention
            ->setInterventionNumber($number)
            ->setHiboutikCustomerId($customerId)
            ->setCustomerLastName((string) ($hibCustomer['last_name'] ?? ''))
            ->setCustomerFirstName((string) ($hibCustomer['first_name'] ?? ''))
            ->setCustomerPhone(($hibCustomer['phone'] ?? '') !== '' ? (string) $hibCustomer['phone'] : null)
            ->setStatus('Ouverte');

        $this->em->persist($intervention);
        $this->em->flush();

        $this->addFlash('success', 'Intervention rapide créée.');

        return $this->redirectToRoute('app_admin_intervention_edit', [
            'id' => $intervention->getId(),
        ]);
    } catch (\Throwable $e) {
        $this->addFlash('error', $e->getMessage());
        return $this->redirectToRoute('app_admin_intervention_new');
    }
}


#[Route('/{id}/customer-box', name: 'customer_box', methods: ['GET'])]
public function customerBox(int $id): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    $hibCustomer = null;
    if ($intervention->getHiboutikCustomerId()) {
        try {
            $hibCustomer = $this->hib->getCustomer($intervention->getHiboutikCustomerId());
        } catch (\Throwable) {
            $hibCustomer = null;
        }
    }

    return $this->render('@SyliusAdmin/Intervention/_hiboutik_customer_box.html.twig', [
        'intervention' => $intervention,
        'hibCustomer' => $hibCustomer,
    ]);
}



// #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
// public function new(Request $request): Response
// {
//     if ($request->isMethod('POST')) {
//         $manualNumber = (int) $request->request->get('intervention_number', 0);
//         $hiboutikCustomerId = (int) $request->request->get('hiboutik_customer_id', 0);

//         $deviceLabel = trim((string) $request->request->get('device_label', ''));
//         $workToDo = trim((string) $request->request->get('work_to_do', ''));

//         $amountTotal = trim((string) $request->request->get('amount_total', ''));
//         $amountPaid = trim((string) $request->request->get('amount_paid', ''));
//         $paymentMode = trim((string) $request->request->get('payment_mode', ''));

//         if ($hiboutikCustomerId <= 0) {
//             $this->addFlash('error', 'Vous devez d’abord lier ou créer un client Hiboutik.');
//             return $this->redirectToRoute('app_admin_intervention_new');
//         }

//         try {
//             $hibCustomer = $this->hib->getCustomer($hiboutikCustomerId);
//             if (!$hibCustomer) {
//                 throw new \RuntimeException('Client Hiboutik introuvable.');
//             }

//             if ($manualNumber > 0) {
//                 $this->numberGenerator->validateManualNumber($manualNumber);
//                 $number = $manualNumber;
//             } else {
//                 $number = $this->numberGenerator->next();
//             }

//             $intervention = new Intervention();
//             $intervention
//                 ->setInterventionNumber($number)
//                 ->setHiboutikCustomerId($hiboutikCustomerId)

//                 // on garde temporairement un miroir local pour compatibilité,
//                 // mais la source de vérité reste Hiboutik
//                 ->setCustomerLastName((string) ($hibCustomer['last_name'] ?? ''))
//                 ->setCustomerFirstName((string) ($hibCustomer['first_name'] ?? ''))
//                 ->setCustomerPhone(($hibCustomer['phone'] ?? '') !== '' ? (string)$hibCustomer['phone'] : null)

//                 ->setDeviceLabel($deviceLabel !== '' ? $deviceLabel : null)
//                 ->setWorkToDo($workToDo !== '' ? $workToDo : null)
//                 ->setStatus('Ouverte')
//                 ->setAmountTotal($amountTotal !== '' ? number_format((float) $amountTotal, 2, '.', '') : null)
//                 ->setAmountPaid($amountPaid !== '' ? number_format((float) $amountPaid, 2, '.', '') : null)
//                 ->setPaymentMode($paymentMode !== '' ? $paymentMode : null);

//             $this->em->persist($intervention);
//             $this->em->flush();

//             if ($manualNumber > 0) {
//                 $this->numberGenerator->bumpCounterIfNeeded($manualNumber);
//             }

//             $this->addFlash('success', 'Intervention créée.');

//             return $this->redirectToRoute('app_admin_intervention_edit', [
//                 'id' => $intervention->getId(),
//             ]);
//         } catch (\Throwable $e) {
//             $this->addFlash('error', $e->getMessage());
//             return $this->redirectToRoute('app_admin_intervention_new');
//         }
//     }

//     return $this->render('@SyliusAdmin/Intervention/new.html.twig', [
//         'tablet_socket_token' => $this->getParameter('tablet_socket_token'),
//     ]);
// }
#[Route('/new', name: 'new', methods: ['GET', 'POST'])]
public function new(Request $request): Response
{
    if ($request->isMethod('POST')) {
        $manualNumber = (int) $request->request->get('intervention_number', 0);
        $hiboutikCustomerId = (int) $request->request->get('hiboutik_customer_id', 0);

        $searchPhone = trim((string) $request->request->get('search_phone', ''));
        $searchLastName = trim((string) $request->request->get('search_last_name', ''));
        $searchFirstName = trim((string) $request->request->get('search_first_name', ''));

        $deviceLabel = trim((string) $request->request->get('device_label', ''));
        $workToDo = trim((string) $request->request->get('work_to_do', ''));

        try {
            if ($hiboutikCustomerId <= 0) {
                $result = $this->hib->createCustomer([
                    'first_name' => $searchFirstName,
                    'last_name'  => $searchLastName,
                    'phone'      => $searchPhone,
                    'country'    => 'FRA',
                ]);

                if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
                    throw new \RuntimeException('Impossible de créer automatiquement le client Hiboutik.');
                }

                $hiboutikCustomerId = (int) $result['customer_id'];
            }

            $hibCustomer = $this->hib->getCustomer($hiboutikCustomerId);
            if (!$hibCustomer) {
                throw new \RuntimeException('Client Hiboutik introuvable.');
            }

            if ($manualNumber > 0) {
                $this->numberGenerator->validateManualNumber($manualNumber);
                $number = $manualNumber;
            } else {
                $number = $this->numberGenerator->next();
            }

            $intervention = new Intervention();
            $intervention
                ->setInterventionNumber($number)
                ->setHiboutikCustomerId($hiboutikCustomerId)
                ->setCustomerLastName((string) ($hibCustomer['last_name'] ?? $searchLastName))
                ->setCustomerFirstName((string) ($hibCustomer['first_name'] ?? $searchFirstName))
                ->setCustomerPhone(($hibCustomer['phone'] ?? '') !== '' ? (string) $hibCustomer['phone'] : ($searchPhone !== '' ? $searchPhone : null))
                ->setDeviceLabel($deviceLabel !== '' ? $deviceLabel : null)
                ->setWorkToDo($workToDo !== '' ? $workToDo : null)
                ->setStatus('Ouverte');

            $this->em->persist($intervention);
            $this->em->flush();

            if ($manualNumber > 0) {
                $this->numberGenerator->bumpCounterIfNeeded($manualNumber);
            }

            $this->addFlash('success', 'Intervention créée.');

            return $this->redirectToRoute('app_admin_intervention_edit', [
                'id' => $intervention->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_admin_intervention_new');
        }
    }

    return $this->render('@SyliusAdmin/Intervention/new.html.twig', [
        'tablet_socket_token' => $this->getParameter('tablet_socket_token'),
    ]);
}



  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
public function edit(int $id, Request $request): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

   $availableBoxes = $this->boiteManager->getAvailableOrCurrent($intervention->getBoite());
   $nextBoxCode = $this->em->getRepository(\App\Entity\Stock\Boite::class)->nextCode();



   if ($request->isMethod('POST')) {
    $manualNumber = (int) $request->request->get('intervention_number', 0);

    $amountTotal = trim((string) $request->request->get('amount_total', ''));
    $amountPaid = trim((string) $request->request->get('amount_paid', ''));
    $paymentMode = trim((string) $request->request->get('payment_mode', ''));
    $status = trim((string) $request->request->get('status', ''));

    if (!in_array($status, ['Ouverte', 'En attente', 'Terminée', 'Annulée'], true)) {
        $status = 'Ouverte';
    }

    try {
        if ($manualNumber <= 0) {
            throw new \RuntimeException('Le numéro d’intervention est obligatoire.');
        }

        if ($amountTotal !== '' && (float) $amountTotal < 0) {
            throw new \RuntimeException('Montant total invalide.');
        }

        if ($amountPaid !== '' && (float) $amountPaid < 0) {
            throw new \RuntimeException('Montant versé invalide.');
        }

        $this->numberGenerator->validateManualNumber($manualNumber, $intervention->getId());



        $intervention
            ->setInterventionNumber($manualNumber)
            ->setCustomerLastName((string) $request->request->get('customer_last_name', ''))
            ->setCustomerFirstName((string) $request->request->get('customer_first_name', ''))
            ->setCustomerPhone(($v = trim((string) $request->request->get('customer_phone', ''))) !== '' ? $v : null)
            ->setDeviceLabel(($v = trim((string) $request->request->get('device_label', ''))) !== '' ? $v : null)
            ->setWorkToDo(($v = trim((string) $request->request->get('work_to_do', ''))) !== '' ? $v : null)
            ->setStatus($status)
            ->setAmountTotal($amountTotal !== '' ? number_format((float) $amountTotal, 2, '.', '') : null)
            ->setAmountPaid($amountPaid !== '' ? number_format((float) $amountPaid, 2, '.', '') : null)
            ->setPaymentMode($paymentMode !== '' ? $paymentMode : null);

        $intervention->touch();
        $this->em->flush();

        $this->numberGenerator->bumpCounterIfNeeded($manualNumber);

        $this->addFlash('success', 'Intervention mise à jour.');

        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    } catch (\Throwable $e) {
        $this->addFlash('error', $e->getMessage());
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }
}

    $unlockData = $this->cipher->decrypt($intervention->getUnlockPayloadEncrypted());

    $hibCustomer = null;
    if ($intervention->getHiboutikCustomerId()) {
        try {
            $hibCustomer = $this->hib->getCustomer($intervention->getHiboutikCustomerId());
        } catch (\Throwable) {
            $hibCustomer = null;
        }
    }

    return $this->render('@SyliusAdmin/Intervention/edit.html.twig', [
        'intervention' => $intervention,
        'unlockData' => $unlockData,
        'hibCustomer' => $hibCustomer,
        'tablet_socket_token' => (string) $this->getParameter('tablet_socket_token'),
        'availableBoxes' => $availableBoxes,
        'nextBoxCode' => $nextBoxCode,
    ]);
}

    #[Route('/{id}/link-hiboutik', name: 'link_hiboutik', methods: ['POST'])]
    public function linkHiboutik(int $id, Request $request): Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);

        if (!$intervention) {
            throw $this->createNotFoundException('Intervention introuvable.');
        }

        $customerId = (int) $request->request->get('hiboutik_customer_id', 0);
        if ($customerId <= 0) {
            $this->addFlash('error', 'ID client Hiboutik invalide.');
            return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
        }

        try {
            $this->hib->getCustomer($customerId);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Client Hiboutik introuvable : ' . $e->getMessage());
            return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
        }

        $intervention->setHiboutikCustomerId($customerId);
        $intervention->touch();
        $this->em->flush();

        $this->addFlash('success', 'Client Hiboutik lié à l’intervention.');

        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }


    /**
 * @Route("/{id}/delete", name="delete", methods={"POST"})
 */
public function delete(int $id, Request $request): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    if (!$this->isCsrfTokenValid('delete_intervention_' . $intervention->getId(), (string) $request->request->get('_token'))) {
        $this->addFlash('error', 'Jeton CSRF invalide.');
        return $this->redirectToRoute('app_admin_intervention_index');
    }

    try {
    $number = $intervention->getInterventionNumber();

    $this->em->remove($intervention);
    $this->em->flush();

    $this->numberGenerator->rewindIfLastDeleted($number);

    $this->addFlash('success', sprintf('Intervention #%s supprimée.', $number));
} catch (\Throwable $e) {
    $this->addFlash('error', 'Suppression impossible : ' . $e->getMessage());
}

    return $this->redirectToRoute('app_admin_intervention_index');
}

    #[Route('/{id}/create-hiboutik', name: 'create_hiboutik', methods: ['POST'])]
    public function createHiboutik(int $id): Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);

        if (!$intervention) {
            throw $this->createNotFoundException('Intervention introuvable.');
        }

        if ($intervention->getHiboutikCustomerId()) {
            $this->addFlash('error', 'Cette intervention est déjà liée à un client Hiboutik.');
            return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
        }

        $result = $this->hib->createCustomer([
            'first_name' => $intervention->getCustomerFirstName(),
            'last_name'  => $intervention->getCustomerLastName(),
            'country'    => 'FRA',
        ]);

        if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
            $this->addFlash('error', 'Impossible de créer le client Hiboutik.');
            return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
        }

        $customerId = (int) $result['customer_id'];

        if ($intervention->getCustomerPhone()) {
            try {
                $this->hib->updateCustomerAttributes($customerId, [
                    'phone' => $intervention->getCustomerPhone(),
                ]);
            } catch (\Throwable) {
                // on n'empêche pas la liaison si la maj téléphone échoue
            }
        }

        $intervention->setHiboutikCustomerId($customerId);
        $intervention->touch();
        $this->em->flush();

        $this->addFlash('success', 'Client Hiboutik créé et lié à l’intervention.');

        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }




    #[Route('/{id}/tablet-link', name: 'tablet_link', methods: ['POST'])]
    public function tabletLink(int $id): JsonResponse
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);

        if (!$intervention) {
            return $this->json(['ok' => false, 'error' => 'Intervention introuvable'], 404);
        }

        $token = bin2hex(random_bytes(24));

        $intervention
            ->setTabletToken($token)
            ->setTabletTokenExpiresAt(new \DateTimeImmutable('+30 minutes'));

        $intervention->touch();
        $this->em->flush();

        $url = $this->generateUrl('app_tablet_intervention_show', [
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'ok' => true,
            'url' => $url,
            'intervention_id' => $intervention->getId(),
            'intervention_number' => $intervention->getInterventionNumber(),
        ]);
    }

/**
 * @Route("/{id}/unlock-status", name="unlock_status", methods={"GET"})
 */
public function unlockStatus(int $id): JsonResponse
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        return new JsonResponse([
            'ok' => false,
            'error' => 'Intervention introuvable',
        ], 404);
    }

    return new JsonResponse([
        'ok' => true,
        'id' => $intervention->getId(),
        'updatedAt' => $intervention->getUpdatedAt()->format(DATE_ATOM),
        'hasUnlock' => $intervention->getUnlockPayloadEncrypted() ? true : false,
        'unlockSummary' => $intervention->getUnlockSummary(),
    ]);
}


/**
     * @Route("/hiboutik-search", name="hiboutik_search", methods={"GET"})
     */
    public function hiboutikSearch(Request $request): JsonResponse
    {
        $phone = trim((string) $request->query->get('phone', ''));
        $lastName = trim((string) $request->query->get('last_name', ''));
        $firstName = trim((string) $request->query->get('first_name', ''));
        $companyName = trim((string) $request->query->get('company_name', ''));

        if ($phone === '' && $lastName === '' && $firstName === '' && $companyName === '') {
            return new JsonResponse([
                'ok' => true,
                'items' => [],
            ]);
        }

        $customers = $this->hib->getCustomers();

        $phoneNorm = $this->normalizePhone($phone);
        $lastNeedle = $this->normalizeText($lastName);
        $firstNeedle = $this->normalizeText($firstName);
        $companyNeedle = $this->normalizeText($companyName);

        $fullNeedle = trim($lastNeedle . ' ' . $firstNeedle);

        $matches = [];

        foreach ($customers as $c) {
            $cPhone = (string) ($c['phone'] ?? '');
            $cPhoneNorm = $this->normalizePhone($cPhone);

            $cLast = (string) ($c['last_name'] ?? '');
            $cFirst = (string) ($c['first_name'] ?? '');

            $cLastNorm = $this->normalizeText($cLast);
            $cFirstNorm = $this->normalizeText($cFirst);
            $cCompanyNorm = $this->normalizeText($c['company'] ?? '');

            $cFullNorm = trim($cLastNorm . ' ' . $cFirstNorm . ' ' . $cFirstNorm . ' ' . $cLastNorm);

            $score = 0;

            if ($phoneNorm !== '' && $cPhoneNorm !== '') {
                if ($cPhoneNorm === $phoneNorm) {
                    $score += 100;
                } elseif (str_contains($cPhoneNorm, $phoneNorm) || str_contains($phoneNorm, $cPhoneNorm)) {
                    $score += 60;
                }
            }

            if ($companyNeedle !== '' && $cCompanyNorm !== '') {
                if ($cCompanyNorm === $companyNeedle) {
                    $score += 40;
                } elseif (str_contains($cCompanyNorm, $companyNeedle)) {
                    $score += 25;
                }
            }   

            if ($lastNeedle !== '' && $cLastNorm !== '') {
                if ($cLastNorm === $lastNeedle) {
                    $score += 35;
                } elseif (str_contains($cLastNorm, $lastNeedle)) {
                    $score += 20;
                }
            }

            if ($firstNeedle !== '' && $cFirstNorm !== '') {
                if ($cFirstNorm === $firstNeedle) {
                    $score += 25;
                } elseif (str_contains($cFirstNorm, $firstNeedle)) {
                    $score += 15;
                }
            }

            if ($fullNeedle !== '' && $cFullNorm !== '') {
                if (str_contains($cFullNorm, $fullNeedle)) {
                    $score += 15;
                }
            }

            if ($score <= 0) {
                continue;
            }

            $matches[] = [
                'score' => $score,
                'customers_id' => (int) ($c['customers_id'] ?? 0),
                'last_name' => $cLast,
                'first_name' => $cFirst,
                'phone' => $cPhone,
                'email' => (string) ($c['email'] ?? ''),
                'company' => (string) ($c['company'] ?? ''),

            ];
        }

        usort($matches, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['customers_id'] <=> $b['customers_id'];
            }
            return $b['score'] <=> $a['score'];
        });

        $matches = array_slice($matches, 0, 15);

        return new JsonResponse([
            'ok' => true,
            'items' => $matches,
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        $v = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($v, '0033')) {
            $v = '0' . substr($v, 4);
        } elseif (str_starts_with($v, '33')) {
            $v = '0' . substr($v, 2);
        }

        return $v;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $replace = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ÿ' => 'y',
            '-' => ' ',
            '_' => ' ',
        ];

        $text = strtr($text, $replace);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    #[Route('/{id}/create-sale-hiboutik', name: 'create_sale_hiboutik', methods: ['POST'])]
public function createSaleHiboutik(int $id): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    if ($intervention->getHiboutikSaleId()) {
        $this->addFlash('error', 'Cette intervention est déjà liée à une vente Hiboutik.');
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    if (!$intervention->getHiboutikCustomerId()) {
        $this->addFlash('error', 'Aucun client Hiboutik lié à cette intervention.');
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    try {
        $storeMeta = $this->hib->getDefaultStoreMeta();

        $result = $this->hib->createSale([
            'store_id' => (int)($storeMeta['store_id'] ?? 1),
            'customer_id' => (int)$intervention->getHiboutikCustomerId(),
            'currency_code' => (string)($storeMeta['currency_code'] ?? 'EUR'),
            'duty_free_sale' => 0,
            'prices_without_taxes' => 0,
            'quotation' => 0,
        ]);

        if (!($result['ok'] ?? false) || empty($result['sale_id'])) {
            $debug = $this->hib->getLastDebug();
            throw new \RuntimeException('Création de la vente Hiboutik impossible.');
        }

        $intervention->setHiboutikSaleId((int)$result['sale_id']);
        $intervention->touch();
        $this->em->flush();

        $this->addFlash('success', 'Vente Hiboutik #' . $result['sale_id'] . ' créée.');
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur création vente Hiboutik : ' . $e->getMessage());
    }

    return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
}

#[Route('/sales', name: 'sales_index', methods: ['GET'])]
public function salesIndex(Request $request): Response
{
    $q = trim((string) $request->query->get('q', ''));

    $qb = $this->em->getRepository(Intervention::class)
        ->createQueryBuilder('i')
        ->andWhere('i.hiboutikSaleId IS NOT NULL')
        ->orderBy('i.interventionNumber', 'DESC')
        ->addOrderBy('i.id', 'DESC');

    if ($q !== '') {
        $qLower = '%' . mb_strtolower($q) . '%';

        $orX = $qb->expr()->orX(
            $qb->expr()->like('LOWER(i.customerLastName)', ':q'),
            $qb->expr()->like('LOWER(i.customerFirstName)', ':q'),
            $qb->expr()->like('LOWER(i.customerPhone)', ':q'),
            $qb->expr()->like('LOWER(i.customerCompanyName)', ':q'),
            $qb->expr()->like('LOWER(i.deviceLabel)', ':q'),
            $qb->expr()->like('LOWER(i.workToDo)', ':q')
        );

        if (ctype_digit($q)) {
            $orX->add($qb->expr()->eq('i.interventionNumber', ':num'));
            $orX->add($qb->expr()->eq('i.hiboutikSaleId', ':saleNum'));
            $qb->setParameter('num', (int) $q);
            $qb->setParameter('saleNum', (int) $q);
        }

        $qb->andWhere($orX)
           ->setParameter('q', $qLower);
    }

    $items = $qb->getQuery()->getResult();

    $rows = [];

    foreach ($items as $intervention) {
        $sale = null;
        $saleError = null;

        try {
            $sale = $this->hib->getSale((int) $intervention->getHiboutikSaleId());
        } catch (\Throwable $e) {
            $saleError = $e->getMessage();
        }

        $rows[] = [
            'intervention' => $intervention,
            'sale' => $sale,
            'saleError' => $saleError,
        ];
    }

    return $this->render('@SyliusAdmin/Intervention/Sales/index.html.twig', [
        'rows' => $rows,
        'filters' => [
            'q' => $q,
        ],
    ]);
}


#[Route('/hiboutik-create-customer', name: 'hiboutik_create_customer', methods: ['POST'])]
public function hiboutikCreateCustomer(Request $request): JsonResponse
{
    $lastName = trim((string) $request->request->get('last_name', ''));
    $firstName = trim((string) $request->request->get('first_name', ''));
    $phone = trim((string) $request->request->get('phone', ''));

    // if ($lastName === '' || $firstName === '') {
    //     return new JsonResponse([
    //         'ok' => false,
    //         'error' => 'Nom et prénom requis.',
    //     ], 400);
    // }

    try {
        $result = $this->hib->createCustomer([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
            'country'    => 'FRA',
        ]);

        if (!($result['ok'] ?? false) || empty($result['customer_id'])) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Création du client Hiboutik impossible.',
                'debug' => $this->hib->getLastDebug(),
            ], 500);
        }

        $customerId = (int) $result['customer_id'];

        $customer = $this->hib->getCustomer($customerId);

        return new JsonResponse([
            'ok' => true,
            'item' => [
                'customers_id' => $customerId,
                'last_name' => (string)($customer['last_name'] ?? $lastName),
                'first_name' => (string)($customer['first_name'] ?? $firstName),
                'phone' => (string)($customer['phone'] ?? $phone),
                'email' => (string)($customer['email'] ?? ''),
                'company' => (string)($customer['company'] ?? ''),
            ],
        ]);
    } catch (\Throwable $e) {
        return new JsonResponse([
            'ok' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

#[Route('/{id}/save-unlock', name: 'save_unlock', methods: ['POST'])]
public function saveUnlock(int $id, Request $request): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    $pin = trim((string) $request->request->get('pin', ''));
    $remarks = trim((string) $request->request->get('remarks', ''));
    $patternRaw = (string) $request->request->get('pattern', '[]');

    $pattern = json_decode($patternRaw, true);
    if (!is_array($pattern)) {
        $pattern = [];
    }

    $pattern = array_values(array_filter(array_map(
        static fn($v) => is_numeric($v) ? (int) $v : null,
        $pattern
    ), static fn($v) => $v !== null && $v >= 1 && $v <= 9));

    if ($pin === '' && $remarks === '' && !$pattern) {
        $this->addFlash('error', 'Aucune donnée de sécurité saisie.');
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    $summaryParts = [];
    if ($pin !== '') {
        $summaryParts[] = 'Code PIN';
    }
    if ($pattern) {
        $summaryParts[] = 'Schéma';
    }
    if ($remarks !== '') {
        $summaryParts[] = 'Remarques';
    }

    $payload = [
        'pin' => $pin !== '' ? $pin : null,
        'pattern' => $pattern ?: null,
        'remarks' => $remarks !== '' ? $remarks : null,
        'capturedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        'source' => 'admin',
    ];

    $encrypted = $this->cipher->encrypt($payload);

    $intervention
        ->setUnlockPayloadEncrypted($encrypted)
        ->setUnlockSummary(implode(' + ', $summaryParts));

    $intervention->touch();
    $this->em->flush();

    $this->addFlash('success', 'Données de sécurité enregistrées.');

    return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
}

#[Route('/{id}/print-ticket-ajax', name: 'print_ticket_ajax', methods: ['POST'])]
public function printTicketAjax(int $id, Request $request): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable');
    }

    $hibCustomer = null;
    if ($intervention->getHiboutikCustomerId()) {
        try {
            $hibCustomer = $this->hib->getCustomer($intervention->getHiboutikCustomerId());
        } catch (\Throwable) {
            $hibCustomer = null;
        }
    }

    $ticket = [
        'number' => (int) $request->request->get('intervention_number', $intervention->getInterventionNumber()),
        'date' => $intervention->getCreatedAt(),
        'status' => (string) $request->request->get('status', $intervention->getStatus()),
        'boxCode' => $intervention->getBoite() ? $intervention->getBoite()->getCode() : '',

        'lastName' => (string) ($hibCustomer['last_name'] ?? ''),
        'firstName' => (string) ($hibCustomer['first_name'] ?? ''),
        'company' => (string) ($hibCustomer['company'] ?? ''),
        'phone' => (string) ($hibCustomer['phone'] ?? ''),
        'email' => (string) ($hibCustomer['email'] ?? ''),

        'deviceLabel' => (string) $request->request->get('device_label', $intervention->getDeviceLabel()),
        'workToDo' => (string) $request->request->get('work_to_do', $intervention->getWorkToDo()),

        'amountTotal' => (($v = $request->request->get('amount_total', '')) !== '')
            ? number_format((float) $v, 2, ',', '')
            : ($intervention->getAmountTotal() !== null ? number_format((float) $intervention->getAmountTotal(), 2, ',', '') : null),

        'amountPaid' => (($v = $request->request->get('amount_paid', '')) !== '')
            ? number_format((float) $v, 2, ',', '')
            : ($intervention->getAmountPaid() !== null ? number_format((float) $intervention->getAmountPaid(), 2, ',', '') : '0,00'),

        'paymentMode' => (string) $request->request->get('payment_mode', $intervention->getPaymentMode() ?? ''),
    ];

    return $this->render('@SyliusAdmin/Intervention/print_ticket_80mm.html.twig', [
        'ticket' => $ticket,
    ]);
}

#[Route('/{id}/print-ticket', name: 'print_ticket', methods: ['GET'])]
public function printTicket(int $id): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable');
    }

    $hibCustomer = null;
    if ($intervention->getHiboutikCustomerId()) {
        try {
            $hibCustomer = $this->hib->getCustomer($intervention->getHiboutikCustomerId());
        } catch (\Throwable) {
            $hibCustomer = null;
        }
    }

    $ticket = [
        'number' => $intervention->getInterventionNumber(),
        'date' => $intervention->getCreatedAt(),
        'status' => $intervention->getStatus(),
        'boxCode' => $intervention->getBoite() ? $intervention->getBoite()->getCode() : '',

        'lastName' => (string) ($hibCustomer['last_name'] ?? ''),
        'firstName' => (string) ($hibCustomer['first_name'] ?? ''),
        'company' => (string) ($hibCustomer['company'] ?? ''),
        'phone' => (string) ($hibCustomer['phone'] ?? ''),
        'email' => (string) ($hibCustomer['email'] ?? ''),

        'deviceLabel' => (string) $intervention->getDeviceLabel(),
        'workToDo' => (string) $intervention->getWorkToDo(),

        'amountTotal' => $intervention->getAmountTotal() !== null
            ? number_format((float) $intervention->getAmountTotal(), 2, ',', '')
            : null,

        'amountPaid' => $intervention->getAmountPaid() !== null
            ? number_format((float) $intervention->getAmountPaid(), 2, ',', '')
            : '0,00',

        'paymentMode' => (string) ($intervention->getPaymentMode() ?? ''),
    ];

    return $this->render('@SyliusAdmin/Intervention/print_ticket_80mm.html.twig', [
        'ticket' => $ticket,
    ]);
}


#[Route('/{id}/assign-box', name: 'assign_box', methods: ['POST'])]
public function assignBox(
    int $id,
    Request $request,
    \App\Repository\Stock\BoiteRepository $boiteRepository,
    \App\Service\BoiteManager $boiteManager
): Response {
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    $boiteValue = (string) $request->request->get('boite_id', '');

    if ($boiteValue === '') {
        if ($intervention->getBoite()) {
            $boiteManager->release($intervention->getBoite());
        }

        $this->addFlash('success', 'Boîte retirée.');
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    if ($boiteValue === '__create__') {
        try {
            $boite = $boiteManager->createNext();
            $boiteManager->assignToIntervention($boite, $intervention);

            $this->addFlash('success', sprintf('Boîte %s créée et affectée.', $boite->getCode()));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    $boiteId = (int) $boiteValue;
    $boite = $boiteRepository->find($boiteId);

    if (!$boite) {
        $this->addFlash('error', 'Boîte introuvable.');
        return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
    }

    try {
        $boiteManager->assignToIntervention($boite, $intervention);
        $this->addFlash('success', sprintf('Boîte %s affectée.', $boite->getCode()));
    } catch (\Throwable $e) {
        $this->addFlash('error', $e->getMessage());
    }

    return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
}
#[Route('/{id}/release-box', name: 'release_box', methods: ['GET'])]
public function releaseBox(int $id, \App\Service\BoiteManager $boiteManager): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    $boiteManager->release($intervention->getBoite());

    $this->addFlash('success', 'Boîte libérée.');
    return $this->redirectToRoute('app_admin_intervention_edit', ['id' => $id]);
}

}

    