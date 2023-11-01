<?php

namespace App\Command\Crawler;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:download:wordpress:extract',
    description: 'Add a short description for your command',
)]
class ExtractWordpressPluginsCommand extends Command
{
    private $projectDir;

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $folder = "{$this->projectDir}/data/wordpress/plugins";
        $extractFolder = "{$this->projectDir}/data/wordpress/plugins_extracted";
        if (!is_dir($extractFolder)) {
            mkdir($extractFolder, 0755, true);
        }

        $zips = glob("$folder/*.zip");

        $count = 0;
        foreach ($zips as $zip) {
            if ($count === 10) {
                while (pcntl_waitpid(0, $status) != -1);
                $count = 0;
            }

            $pid = pcntl_fork();

            if ($pid == -1) {
                $io->error('Could not fork');

                return Command::FAILURE;
            } elseif ($pid == 0) {
                $io->writeln("Extracting $zip");
                system("unzip -o $zip -d $extractFolder &>/dev/null");
                exit(0);
            }
            $count++;
        }

        while (pcntl_waitpid(0, $status) != -1);

        return Command::SUCCESS;
    }
}
