<?php

namespace App\Controller\Admin;

use App\Entity\Upload;
use App\Form\UploadType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UploadController extends AbstractController
{
    /**
     * @Route("/admin/upload", name="upload")
     */
    public function index(): Response
    {
        $upload = new Upload();

        $form = $this->createForm(UploadType::class, $upload);

        return $this->render('@SyliusAdmin/Upload/index.html.twig', [
            'controller_name' => 'UploadController',
            'form' => $form->createView(),
        ]);
    }
}
