<?php

namespace App\Controller\Admin\Hiboutik\Arrivage;

use App\Service\Pdf\UtopyaInvoiceParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/hiboutik/arrivages/documents/utopya-pdf-test', name: 'arrivages_documents_utopya_pdf_test_')]
class UtopyaPdfTestController extends AbstractController
{
    public function __construct(
        private UtopyaInvoiceParser $utopyaInvoiceParser,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $result = null;
        $error = null;
        $uploadedName = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('utopya_pdf_test', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('CSRF invalid');
            }

            /** @var UploadedFile|null $file */
            $file = $request->files->get('pdf_file');

            if (!$file) {
                $error = 'Aucun fichier envoyé.';
            } elseif (strtolower((string) $file->getClientOriginalExtension()) !== 'pdf') {
                $error = 'Le fichier doit être un PDF.';
            } else {
                try {
                    // ----------------------------------------------------
                    // On utilise directement le fichier uploadé temporaire
                    // ----------------------------------------------------
                    $uploadedName = $file->getClientOriginalName();
                    $result = $this->utopyaInvoiceParser->parse($file->getPathname());
                } catch (\Throwable $e) {
                    $error = 'Erreur parser PDF : ' . $e->getMessage();
                }
            }
        }

        return $this->render('@SyliusAdmin/Hiboutik/Arrivages/documents/upload_test_pdf.html.twig', [
            'result' => $result,
            'error' => $error,
            'uploadedName' => $uploadedName,
        ]);
    }
}