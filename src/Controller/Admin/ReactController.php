<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReactController extends AbstractController
{
    #[Route('/admin/react', name: 'app_react')]
    public function index(): Response
    {
        return $this->render('@SyliusAdmin/React/index.html.twig', [
            'controller_name' => 'ReactController',
        ]);
    }
}
