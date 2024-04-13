<?php

namespace App\Command\Nist;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:samples:extract',
    description: 'Extracts the three nist sample sets (this takes a really long while!).',
)]
class SamplesExtractCommand extends Command
{
    private $projectDir;

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-all/nist/zip')
            ->addArgument('targetDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-all/nist/extracted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filesystem = new Filesystem();

        $sourceDirectory = $input->getArgument('sourceDirectory');
        $targetDirectory = $input->getArgument('targetDirectory');

        $filesystem->mkdir($sourceDirectory);
        $filesystem->mkdir($targetDirectory);

        $zips = glob("$sourceDirectory/*.zip");

        foreach ($zips as $zip) {
            $zipArchive = new \ZipArchive();
            $basename = basename($zip, '.zip');
            if ($zipArchive->open($zip) === true) {
                $zipArchive->extractTo("{$targetDirectory}/{$basename}");
                $zipArchive->close();
                $io->success("$basename extracted");
            } else {
                $io->error("Could not extract $basename");
            }
        }

        return Command::SUCCESS;
    }
}
