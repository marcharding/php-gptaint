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
    name: 'app:samples:load-samples-preset',
    description: 'Loads the preset of samples analyzed in the accompanying work.',
)]
class SamplesLoadSamplesPresetCommand extends Command
{
    private $projectDir;

    private $samplePreset = [
        '254587-v1.0.0',
        '254912 -v1.0.0',
        '258774-v1.0.0',
        '263976-v1.0.0',
        '277835-v1.0.0',
        '285229-v1.0.0',
        '285759-v1.0.0',
        '289379-v1.0.0',
        '295527-v1.0.0',
        '295750-v1.0.0',
        '320780-v1.0.0',
        '323883-v1.0.0',
        '325534-v1.0.0',
        '326247-v1.0.0',
        '340974-v1.0.0',
        '342581-v1.0.0',
        '345124-v1.0.0',
        '347937-v1.0.0',
        '349980-v1.0.0',
        '354803-v1.0.0',
        '358898-v1.0.0',
        '362777-v1.0.0',
        '363307-v1.0.0',
        '365760-v1.0.0',
        '378748-v1.0.0',
        '379350-v1.0.0',
        '380614-v1.0.0',
        '382704-v1.0.0',
        '387750-v1.0.0',
        '388298-v1.0.0',
        '390622-v1.0.0',
        '390886-v1.0.0',
        '392249-v1.0.0',
        '399958-v1.0.0',
        '400305-v1.0.0',
        '406007-v1.0.0',
        '406799-v1.0.0',
        '410604-v1.0.0',
        '413635-v1.0.0',
        '418176-v1.0.0',
        '419511-v1.0.0',
        '423475-v1.0.0',
        '431201-v1.0.0',
        '433920-v1.0.0',
        '439236-v1.0.0',
        '446355-v1.0.0',
        '447443-v1.0.0',
        '450950-v1.0.0',
        '451129-v1.0.0',
        '451168-v1.0.0',
        '455049-v1.0.0',
        '459125-v1.0.0',
        '468846-v1.0.0',
        '473387-v1.0.0',
        '477403-v1.0.0',
        '478014-v1.0.0',
        '478230-v1.0.0',
        '486908-v1.0.0',
        '491292-v1.0.0',
        '493186-v1.0.0',
        '501058-v1.0.0',
        '501059-v1.0.0',
        '501060-v1.0.0',
        '501061-v1.0.0',
        '501062-v1.0.0',
        '501063-v1.0.0',
        '501064-v1.0.0',
        '501065-v1.0.0',
        '501066-v1.0.0',
        '501067-v1.0.0',
        '501068-v1.0.0',
        '501069-v1.0.0',
        '501070-v1.0.0',
        '501071-v1.0.0',
        '501072-v1.0.0',
        '501073-v1.0.0',
        '501074-v1.0.0',
        '501075-v1.0.0',
        '501076-v1.0.0',
        '501077-v1.0.0',
    ];

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-all/nist')
            ->addArgument('targetDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-selection/nist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();

        $sourceDirectory = $input->getArgument('sourceDirectory');
        $targetDirectory = $input->getArgument('targetDirectory');

        $filesystem->mkdir($sourceDirectory);
        $filesystem->mkdir($targetDirectory);

        foreach ($this->samplePreset as $samplePreset) {
            if (!$filesystem->exists("{$sourceDirectory}/{$samplePreset}")) {
                $io->warning("{$samplePreset} not found.");
                continue;
            }
            $filesystem->mirror("{$sourceDirectory}/{$samplePreset}", "{$targetDirectory}/{$samplePreset}");
        }

        $io->success('Sample presets created.');

        return Command::SUCCESS;
    }
}
