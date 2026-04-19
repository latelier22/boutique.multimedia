<?php

namespace App\Service\Hiboutik;

use App\Entity\Hiboutik\IncomingSupplierAttachment;
use App\Entity\Hiboutik\IncomingSupplierDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ArrivageMailFetcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $arrivageMailboxHost,
        private string $arrivageMailboxUser,
        private string $arrivageMailboxPassword,
    ) {
    }

    public function fetch(?OutputInterface $output = null): array
    {
        $this->log($output, '<info>=== FETCH ARRIVAGE EMAILS ===</info>');

        if (!function_exists('imap_open')) {
            $this->log($output, '<error>Extension IMAP absente côté CLI.</error>');
            return [
                'ok' => false,
                'error' => 'imap_extension_missing',
            ];
        }

        imap_timeout(IMAP_OPENTIMEOUT, 10);
        imap_timeout(IMAP_READTIMEOUT, 10);
        imap_timeout(IMAP_WRITETIMEOUT, 10);
        imap_timeout(IMAP_CLOSETIMEOUT, 10);

        $this->log($output, 'Host = ' . $this->arrivageMailboxHost);
        $this->log($output, 'User = ' . $this->arrivageMailboxUser);
        $this->log($output, 'Pass length = ' . strlen($this->arrivageMailboxPassword));

        $mailbox = sprintf('{%s:993/imap/ssl/novalidate-cert}INBOX', $this->arrivageMailboxHost);
        $this->log($output, 'Mailbox = ' . $mailbox);

        $inbox = imap_open($mailbox, $this->arrivageMailboxUser, $this->arrivageMailboxPassword);

        if (!$inbox) {
            $this->log($output, '<error>imap_open a échoué</error>');

            $errors = imap_errors();
            $alerts = imap_alerts();

            if ($errors) {
                foreach ($errors as $err) {
                    $this->log($output, '<error>IMAP ERROR: ' . $err . '</error>');
                }
            }

            if ($alerts) {
                foreach ($alerts as $alert) {
                    $this->log($output, '<comment>IMAP ALERT: ' . $alert . '</comment>');
                }
            }

            return [
                'ok' => false,
                'error' => 'imap_open_failed',
            ];
        }

        $this->log($output, '<info>Connexion IMAP OK</info>');

        $emails = imap_search($inbox, 'ALL');
        $this->log($output, 'Résultat ALL = ' . json_encode($emails));

        if (!$emails) {
            $this->log($output, '<comment>Aucun mail trouvé.</comment>');
            imap_close($inbox);

            return [
                'ok' => true,
                'mail_count' => 0,
                'created_documents' => 0,
                'completed_documents' => 0,
                'saved_attachments' => 0,
            ];
        }

        rsort($emails);

        $storageDir = dirname(__DIR__, 3) . '/var/arrivage-mails';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $createdDocuments = 0;
        $completedDocuments = 0;
        $savedAttachments = 0;

        foreach ($emails as $emailNumber) {
            $this->log($output, '');
            $this->log($output, sprintf('<info>--- Mail #%d ---</info>', $emailNumber));

            $overview = imap_fetch_overview($inbox, (string) $emailNumber, 0);
            $structure = imap_fetchstructure($inbox, (string) $emailNumber);

            if (!$overview || !isset($overview[0])) {
                $this->log($output, '<comment>Overview vide, skip.</comment>');
                continue;
            }

            $ov = $overview[0];
            $messageId = isset($ov->message_id) ? trim((string) $ov->message_id) : null;
            $subject = isset($ov->subject) ? imap_utf8((string) $ov->subject) : '(sans sujet)';
            $from = (string) ($ov->from ?? '(sans expéditeur)');

            $this->log($output, 'Sujet: ' . $subject);
            $this->log($output, 'From: ' . $from);
            $this->log($output, 'Message-ID: ' . ($messageId ?: '—'));

            $existing = null;

            if ($messageId) {
                $existing = $this->em->getRepository(IncomingSupplierDocument::class)->findOneBy([
                    'messageId' => $messageId,
                ]);
            }

            if ($existing) {
                $existingAttachmentCount = count($existing->getAttachments());

                if ($existingAttachmentCount > 0) {
                    $this->log($output, sprintf(
                        '<comment>Déjà importé avec %d pièce(s) jointe(s), skip.</comment>',
                        $existingAttachmentCount
                    ));
                    continue;
                }

                $this->log($output, '<comment>Document existant sans pièce jointe, on le complète.</comment>');
                $document = $existing;
                $completedDocuments++;
            } else {
                $document = new IncomingSupplierDocument();
                $document->setMessageId($messageId);
                $document->setFromEmail($this->extractEmail((string) ($ov->from ?? '')));
                $document->setFromName($this->extractName((string) ($ov->from ?? '')));
                $document->setSubject($subject);
                $document->setReceivedAt(isset($ov->date) ? new \DateTimeImmutable((string) $ov->date) : new \DateTimeImmutable());
                $document->setStatus('new');

                $this->applyHintsFromSubject($document, $subject);

                $this->em->persist($document);
                $createdDocuments++;
            }

            if (!$structure) {
                $this->log($output, '<comment>Pas de structure MIME.</comment>');
                $this->em->flush();
                continue;
            }

            $savedCount = $this->saveAttachmentsFromStructure(
                $inbox,
                $emailNumber,
                $structure,
                $document,
                $storageDir,
                $output
            );

            $savedAttachments += $savedCount;

            $this->em->flush();

            $this->log($output, sprintf('<info>%d pièce(s) jointe(s) sauvegardée(s).</info>', $savedCount));
        }

        imap_close($inbox);
        $this->log($output, '<info>Terminé.</info>');

        return [
            'ok' => true,
            'mail_count' => count($emails),
            'created_documents' => $createdDocuments,
            'completed_documents' => $completedDocuments,
            'saved_attachments' => $savedAttachments,
        ];
    }

    private function log(?OutputInterface $output, string $message): void
    {
        if ($output !== null) {
            $output->writeln($message);
        }
    }

    private function applyHintsFromSubject(IncomingSupplierDocument $document, string $subject): void
    {
        $s = mb_strtoupper($subject, 'UTF-8');

        if (str_contains($s, 'UTOPYA')) {
            $document->setSupplierName('UTOPYA');
        } elseif (str_contains($s, 'SENTRIX')) {
            $document->setSupplierName('SENTRIX');
        }

        if (preg_match('/\bFA\b/', $s)) {
            $document->setDocumentType('FA');
        } elseif (preg_match('/\bBL\b/', $s)) {
            $document->setDocumentType('BL');
        } elseif (preg_match('/\bBC\b/', $s)) {
            $document->setDocumentType('BC');
        } elseif (preg_match('/\bAV\b/', $s)) {
            $document->setDocumentType('AV');
        }
    }

    private function resolveFilenameFromPart(object $part): ?string
    {
        foreach (['dparameters', 'parameters'] as $prop) {
            if (!isset($part->{$prop}) || !is_array($part->{$prop})) {
                continue;
            }

            foreach ($part->{$prop} as $p) {
                $attribute = strtolower((string) ($p->attribute ?? ''));

                if (in_array($attribute, ['filename', 'name'], true)) {
                    $value = imap_utf8((string) ($p->value ?? ''));
                    $value = trim($value);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function collectAttachmentParts(object $part, string $partNumber = '1'): array
    {
        $results = [];

        $filename = $this->resolveFilenameFromPart($part);
        if ($filename) {
            $results[] = [
                'partNumber' => $partNumber,
                'filename' => $filename,
                'part' => $part,
            ];
        }

        if (isset($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $subPart) {
                $subPartNumber = $partNumber . '.' . ($index + 1);
                $results = array_merge($results, $this->collectAttachmentParts($subPart, $subPartNumber));
            }
        }

        return $results;
    }

    private function saveAttachmentsFromStructure($inbox, int $emailNumber, object $structure, IncomingSupplierDocument $document, string $storageDir, ?OutputInterface $output = null): int
    {
        $parts = [];

        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $partNumber = (string) ($index + 1);
                $parts = array_merge($parts, $this->collectAttachmentParts($part, $partNumber));
            }
        } else {
            $filename = $this->resolveFilenameFromPart($structure);
            if ($filename) {
                $parts[] = [
                    'partNumber' => '1',
                    'filename' => $filename,
                    'part' => $structure,
                ];
            }
        }

        $savedCount = 0;

        foreach ($parts as $item) {
            $filename = $item['filename'];
            $part = $item['part'];
            $partNumber = $item['partNumber'];

            $this->log($output, sprintf(
                'Attachment trouvé: part=%s filename=%s encoding=%s subtype=%s',
                $partNumber,
                $filename,
                (string) ($part->encoding ?? '?'),
                (string) ($part->subtype ?? '?')
            ));

            $body = imap_fetchbody($inbox, $emailNumber, $partNumber);

            if ((int) ($part->encoding ?? 0) === 3) {
                $body = base64_decode($body);
            } elseif ((int) ($part->encoding ?? 0) === 4) {
                $body = quoted_printable_decode($body);
            }

            if (!is_string($body) || $body === '') {
                $this->log($output, '<comment>Body vide pour ' . $filename . '</comment>');
                continue;
            }

            $storedFilename = uniqid('mail_att_', true) . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
            $fullPath = $storageDir . '/' . $storedFilename;

            file_put_contents($fullPath, $body);

            $attachment = new IncomingSupplierAttachment();
            $attachment->setOriginalFilename($filename);
            $attachment->setStoredFilename($storedFilename);
            $attachment->setMimeType($this->guessMimeType($filename));
            $attachment->setSize(strlen($body));
            $attachment->setIsParsable($this->isParsableFilename($filename));
            $attachment->setStatus('new');

            $document->addAttachment($attachment);
            $this->em->persist($attachment);

            $savedCount++;
            $this->log($output, '<info>Pièce jointe enregistrée: ' . $fullPath . '</info>');
        }

        return $savedCount;
    }

    private function isParsableFilename(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, ['xlsx', 'xls', 'csv', 'pdf'], true);
    }

    private function guessMimeType(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            default => null,
        };
    }

    private function extractEmail(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return trim($m[1]);
        }

        return filter_var(trim($from), FILTER_VALIDATE_EMAIL) ? trim($from) : null;
    }

    private function extractName(string $from): ?string
    {
        if (preg_match('/^(.*?)</', $from, $m)) {
            return trim(str_replace('"', '', $m[1]));
        }

        return null;
    }
}