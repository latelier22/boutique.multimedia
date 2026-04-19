<?php

namespace App\Command\Hiboutik;

use App\Service\Hiboutik\ArrivageMailFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:hiboutik:fetch-arrivage-emails')]
class FetchArrivageEmailsCommand extends Command
{
    public function __construct(
        private ArrivageMailFetcher $fetcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->fetcher->fetch($output);

        return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}