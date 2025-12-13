<?php

namespace App\Controller\Admin\Mail;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class TestMailController extends AbstractController
{
    #[Route('/admin/test-mail', name: 'test_mail')]
    public function test(MailerInterface $mailer): Response
    {
        $email = (new Email())
            ->from('contact@multimedia-services.fr')           // expéditeur
            ->to('lecorre@yahoo.com')                 // destinataire
            ->subject('Test mail Symfony (boutique)')
            ->text('Ceci est un test de mail envoyé depuis Symfony.');

        $mailer->send($email);

        return new Response('OK : email envoyé (si DSN correct).');
    }
}
