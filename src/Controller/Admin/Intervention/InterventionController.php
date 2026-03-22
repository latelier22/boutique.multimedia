<?php

namespace App\Controller\Admin\Intervention;

use App\Entity\Intervention\Intervention;
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
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $items = $this->em->getRepository(Intervention::class)->findBy([], ['id' => 'DESC']);

        return $this->render('@SyliusAdmin/Intervention/index.html.twig', [
            'items' => $items,
            'tablet_socket_token' => (string) $this->getParameter('tablet_socket_token'),
        ]);
    }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
public function new(Request $request): Response
{
    if ($request->isMethod('POST')) {
        $manualNumber = (int) $request->request->get('intervention_number', 0);
        $lastName = trim((string) $request->request->get('customer_last_name', ''));
        $firstName = trim((string) $request->request->get('customer_first_name', ''));
        $phone = trim((string) $request->request->get('customer_phone', ''));
        $deviceLabel = trim((string) $request->request->get('device_label', ''));
        $workToDo = trim((string) $request->request->get('work_to_do', ''));

        if ($lastName === '' && $firstName === '') {
            $this->addFlash('error', 'Nom ou prénom requis.');
            return $this->redirectToRoute('app_admin_intervention_new');
        }

        try {
            if ($manualNumber > 0) {
                $this->numberGenerator->validateManualNumber($manualNumber);
                $number = $manualNumber;
            } else {
                $number = $this->numberGenerator->next();
            }

            $intervention = new Intervention();
            $intervention
                ->setInterventionNumber($number)
                ->setCustomerLastName($lastName)
                ->setCustomerFirstName($firstName)
                ->setCustomerPhone($phone !== '' ? $phone : null)
                ->setDeviceLabel($deviceLabel !== '' ? $deviceLabel : null)
                ->setWorkToDo($workToDo !== '' ? $workToDo : null)
                ->setStatus('open');

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

    return $this->render('@SyliusAdmin/Intervention/new.html.twig');
}

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
public function edit(int $id, Request $request): Response
{
    $intervention = $this->em->getRepository(Intervention::class)->find($id);

    if (!$intervention) {
        throw $this->createNotFoundException('Intervention introuvable.');
    }

    if ($request->isMethod('POST')) {
        $manualNumber = (int) $request->request->get('intervention_number', 0);

        try {
            if ($manualNumber <= 0) {
                throw new \RuntimeException('Le numéro d’intervention est obligatoire.');
            }

            $this->numberGenerator->validateManualNumber($manualNumber, $intervention->getId());

            $intervention
                ->setInterventionNumber($manualNumber)
                ->setCustomerLastName((string) $request->request->get('customer_last_name', ''))
                ->setCustomerFirstName((string) $request->request->get('customer_first_name', ''))
                ->setCustomerPhone(($v = trim((string) $request->request->get('customer_phone', ''))) !== '' ? $v : null)
                ->setDeviceLabel(($v = trim((string) $request->request->get('device_label', ''))) !== '' ? $v : null)
                ->setWorkToDo(($v = trim((string) $request->request->get('work_to_do', ''))) !== '' ? $v : null)
                ->setStatus((string) $request->request->get('status', 'open'));

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


}

    