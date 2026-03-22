<?php

namespace App\Controller\Tablet;

use App\Entity\Intervention\Intervention;
use App\Service\SensitiveDataCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tablet/intervention', name: 'app_tablet_intervention_')]
class TabletInterventionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SensitiveDataCipher $cipher,
    ) {}

    #[Route('/{token}', name: 'show', methods: ['GET'])]
    public function show(string $token): Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->findOneBy([
            'tabletToken' => $token,
        ]);

        if (
            !$intervention ||
            !$intervention->getTabletTokenExpiresAt() ||
            $intervention->getTabletTokenExpiresAt() < new \DateTimeImmutable()
        ) {
            throw $this->createNotFoundException('Lien tablette invalide ou expiré.');
        }

        return $this->render('tablet/intervention/tablet_unlock.html.twig', [
            'intervention' => $intervention,
            'token' => $token,
        ]);
    }

    #[Route('/{token}/save', name: 'save', methods: ['POST'])]
    public function save(string $token, Request $request): JsonResponse
    {
        $intervention = $this->em->getRepository(Intervention::class)->findOneBy([
            'tabletToken' => $token,
        ]);

        if (
            !$intervention ||
            !$intervention->getTabletTokenExpiresAt() ||
            $intervention->getTabletTokenExpiresAt() < new \DateTimeImmutable()
        ) {
            return $this->json(['ok' => false, 'error' => 'Lien invalide ou expiré'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $pin = trim((string) ($data['pin'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $pattern = $data['pattern'] ?? null;

        if ($pattern !== null && !is_array($pattern)) {
            return $this->json(['ok' => false, 'error' => 'Pattern invalide'], 422);
        }

        if ($pin === '' && $remarks === '' && (empty($pattern) || !is_array($pattern))) {
            return $this->json(['ok' => false, 'error' => 'Aucune donnée à enregistrer'], 422);
        }

        $payload = [
            'pin' => $pin !== '' ? $pin : null,
            'pattern' => is_array($pattern) && count($pattern) > 0 ? array_values($pattern) : null,
            'remarks' => $remarks !== '' ? $remarks : null,
            'capturedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $summary = [];
        if ($payload['pin']) $summary[] = 'Code';
        if ($payload['pattern']) $summary[] = 'Schéma';
        if ($payload['remarks']) $summary[] = 'Remarques';

        $intervention
            ->setUnlockPayloadEncrypted($this->cipher->encrypt($payload))
            ->setUnlockSummary(implode(' + ', $summary) ?: null)
            ->setTabletToken(null)
            ->setTabletTokenExpiresAt(null);

        $intervention->touch();
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'summary' => $intervention->getUnlockSummary(),
        ]);
    }
}