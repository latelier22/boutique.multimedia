<?php

namespace App\Controller\Admin;

use App\Entity\DisplayMessage;
use App\Form\DisplayMessageType;
use App\Repository\DisplayMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\CacheApiClient;

#[Route('/admin/display-messages', name: 'admin_display_message_')]
class DisplayMessageController extends AbstractController
{

    public function __construct(
        private \App\Service\CacheApiClient $cacheApi,
    ) {
    }

#[Route('', name : 'index', methods: ['GET'])]
public function index(
    Request $request,
    DisplayMessageRepository $repository,
    \App\Service\DiaporamaSlotProvider $slotProvider
): Response {
    $slot = trim((string)$request->query->get('slot', ''));

    return $this->render('@SyliusAdmin/display_message/index.html.twig', [
        'messages'    => $repository->findForAdmin($slot ?: null),
        'slotChoices' => $slotProvider->getChoices(),
        'currentSlot' => $slot,
    ]);
}

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $message = new DisplayMessage();

        $form = $this->createForm(DisplayMessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($message);
            $em->flush();

            $this->cacheApi->refreshDisplayMessages($message->getSlot());
            $this->addFlash('success', 'Message créé.');
            return $this->redirectToRoute('admin_display_message_index');
        }



        return $this->render('@SyliusAdmin/display_message/new.html.twig', [
            'form' => $form->createView(),
            'message' => $message,
        ]);
    }

   #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
public function edit(DisplayMessage $message, Request $request, EntityManagerInterface $em): Response
{
    $form = $this->createForm(DisplayMessageType::class, $message);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $em->flush();

        $this->cacheApi->refreshDisplayMessages($message->getSlot());

        $this->addFlash('success', 'Message mis à jour.');
        return $this->redirectToRoute('admin_display_message_index');
    }

    return $this->render('@SyliusAdmin/display_message/edit.html.twig', [
        'form' => $form->createView(),
        'message' => $message,
    ]);
}

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(DisplayMessage $message, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_display_message_' . $message->getId(), (string) $request->request->get('_token'))) {
            $em->remove($message);
            $em->flush();
            $this->cacheApi->refreshDisplayMessages($message->getSlot());
            $this->addFlash('success', 'Message supprimé.');
        }

        return $this->redirectToRoute('admin_display_message_index');
    }

#[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'])]
public function duplicate(DisplayMessage $message, Request $request, EntityManagerInterface $em): Response
{
    if (!$this->isCsrfTokenValid('duplicate_display_message_' . $message->getId(), (string) $request->request->get('_token'))) {
        $this->addFlash('error', 'Token CSRF invalide.');
        return $this->redirectToRoute('admin_display_message_index');
    }

    $copy = new DisplayMessage();

    $copy
        ->setName($message->getName() . ' (copie)')
        ->setSlot($message->getSlot())
        ->setTemplate($message->getTemplate())
        ->setBadge($message->getBadge())
        ->setTitle($message->getTitle())
        ->setLine1($message->getLine1())
        ->setLine2($message->getLine2())
        ->setLine3($message->getLine3())
        ->setFooterText($message->getFooterText())
        ->setBackgroundType($message->getBackgroundType())
        ->setTextAlign($message->getTextAlign())
        ->setAccentColor($message->getAccentColor())
        ->setIntervalSeconds($message->getIntervalSeconds())
        ->setDurationSeconds($message->getDurationSeconds())
        ->setSortOrder($message->getSortOrder() + 1)
        ->setStartsAt($message->getStartsAt())
        ->setEndsAt($message->getEndsAt())
        ->setImagePath($message->getImagePath())
        ->setIsEnabled(false) // plus sûr : la copie n'est pas active tout de suite
    ;

    $em->persist($copy);
    $em->flush();
    $this->cacheApi->refreshDisplayMessages($message->getSlot());
    $this->addFlash('success', 'Message dupliqué.');

    return $this->redirectToRoute('admin_display_message_edit', [
        'id' => $copy->getId(),
    ]);
}
}