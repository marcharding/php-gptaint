<?php

namespace App\Command\Nist;

use App\Entity\Sample;
use App\Service\Stats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:sample:results:export:csv',
    description: 'Export the results as a csv (to plot graphs)',
)]
class SampleResultsExportCommandCsv extends Command
{
    private $projectDir;
    private EntityManagerInterface $entityManager;

    protected function configure(): void
    {
        $this
            ->addArgument('outputFile', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.')
            ->addOption('print-to-stdout', null, InputOption::VALUE_NONE)
            ->addOption('columns', null, InputOption::VALUE_OPTIONAL, 'Model to use (if none is given the default model from the configuration is used).',
                ['truePositives', 'trueNegatives', 'falsePositives', 'falseNegatives', 'time', 'sum', 'count', 'sensitivity', 'precision', 'accuracy', 'specificity', 'f1']);
    }

    public function __construct(string $projectDir, EntityManagerInterface $entityManager, Stats $stats)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        $this->stats = $stats;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = $this->entityManager->getRepository(Sample::class)->findAll();

        if (!$input->getArgument('outputFile')) {
            $outputFile = $this->projectDir.'/results_'.time().'.csv';
        } else {
            $outputFile = $this->projectDir.'/'.$input->getArgument('outputFile');
        }

        $columns = $input->getOption('columns');
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $statistics = $this->stats->getStatistics($issues);

        $printToStdout = $input->getOption('print-to-stdout');

        if ($printToStdout) {
            $file = fopen('php://stdout', 'w');
        } else {
            $file = fopen($outputFile, 'w');
        }

        // Header row
        $row = reset($statistics);
        foreach ($row as $key => $value) {
            if (array_search($key, $columns) === false) {
                unset($row[$key]);
            }
        }
        $row = array_keys($row);
        array_unshift($row, 'Method');
        fputcsv($file, $row);

        // Body rows
        foreach ($statistics as $type => $row) {
            foreach ($row as $key => $value) {
                if (array_search($key, $columns) === false) {
                    unset($row[$key]);
                }
            }
            array_unshift($row, $type);
            fputcsv($file, array_values($row));
        }

        fclose($file);

        return Command::SUCCESS;
    }
}
