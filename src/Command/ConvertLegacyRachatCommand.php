<?php

namespace App\Command;

use App\Entity\Rachat\Rachat;
use App\Service\Rachat\RachatLegacyConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:rachat:convert-legacy')]
class ConvertLegacyRachatCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private RachatLegacyConverter $converter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'ID du rachat legacy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('id');

        /** @var Rachat|null $legacy */
        $legacy = $this->em->getRepository(Rachat::class)->find($id);

        if (!$legacy) {
            $output->writeln('<error>Rachat legacy introuvable.</error>');
            return Command::FAILURE;
        }

        $dossier = $this->converter->convert($legacy, true);

        $output->writeln(sprintf(
            '<info>Conversion OK. Legacy #%d => Dossier V2 #%d (%s)</info>',
            $legacy->getId(),
            $dossier->getId(),
            $dossier->getReference() ?? 'sans-ref'
        ));

        return Command::SUCCESS;
    }
}