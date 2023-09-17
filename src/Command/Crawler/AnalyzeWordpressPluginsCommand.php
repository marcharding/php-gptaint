<?php

namespace App\Command\Crawler;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:download:wordpress:analyze',
    description: 'Add a short description for your command',
)]
class AnalyzeWordpressPluginsCommand extends Command
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

        $folder = "{$this->projectDir}/data/wordpress/plugins_extracted";
        $tainted = "{$this->projectDir}/data/wordpress/plugins_tainted";
        $resultsDir = "{$this->projectDir}/data/wordpress/results";
        $crashed = "{$this->projectDir}/data/wordpress/plugins_crashed";

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0755, true);
        }
        if (!is_dir($tainted)) {
            mkdir($tainted, 0755, true);
        }
        if (!is_dir($crashed)) {
            mkdir($crashed, 0755, true);
        }

        $config = <<<'EOT'
<?xml version="1.0"?>
<psalm
    errorLevel="2"
    resolveFromConfigFile="true"
    findUnusedBaselineEntry="false"
    findUnusedCode="false"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
>
    <projectFiles>
        <directory name="{{FOLDER}}" />
    </projectFiles>
</psalm>
EOT;

        // Get the list of zip files
        $plugins = glob("$folder/*");

        foreach ($plugins as $plugin) {

            $pluginName = basename($plugin);
            if (is_file("$resultsDir/$pluginName.sarif")) {
                continue;
            }

            $pluginName = basename($plugin);

            file_put_contents("$plugin/psalm.xml", strtr($config, ['{{FOLDER}}' => $plugin]));
            $result = [];
            ob_start();
            exec("timeout 60 php vendor/bin/psalm --threads=4 --no-diff --no-cache --no-file-cache --no-progress --no-reflection-cache --report=$resultsDir/$pluginName.sarif --taint-analysis --monochrome --config=$plugin/psalm.xml 2>&1", $result, $resultCode);
            ob_end_clean();
            $resultOrg = $result;
            unlink("$plugin/psalm.xml");

            if (!in_array($resultCode, [0, 2])) {
                file_put_contents("$crashed/$pluginName.txt", implode(PHP_EOL, $resultOrg));
                system("cp -r $plugin $crashed");
                $io->block("Plugin '$pluginName' crashed the analysis", 'ERROR', 'bg=red', ' ', false);
                continue;
            }

            $result = array_filter($result, function ($item) {
                if (strpos($item, 'ERROR: ') === 0) {
                    return true;
                }
            });

            $result = array_map(function ($item) {
                return trim(str_replace('ERROR: ', '', strtok($item, '-')));
            }, $result);

            $types = [];
            foreach ($result as $entry) {
                if (!isset($types[$entry])) {
                    $types[$entry] = 0;
                }
                $types[$entry]++;
            }

            file_put_contents("{$resultsDir}/$pluginName.txt", implode(PHP_EOL, $resultOrg));

            $results = dirname($resultsDir) . "/_results.txt";
            file_put_contents($results, '###########################################################' . PHP_EOL, FILE_APPEND);
            file_put_contents($results, $pluginName . PHP_EOL, FILE_APPEND);
            file_put_contents($results, '###########################################################' . PHP_EOL, FILE_APPEND);
            file_put_contents($results, PHP_EOL, FILE_APPEND);
            file_put_contents($results, "Total Errors: " . count($result), FILE_APPEND);
            file_put_contents($results, PHP_EOL, FILE_APPEND);
            foreach ($types as $type => $count) {
                file_put_contents($results, "$type: " . $count . PHP_EOL, FILE_APPEND);
            }
            file_put_contents($results, PHP_EOL, FILE_APPEND);
            file_put_contents($results, PHP_EOL, FILE_APPEND);

            if ($result) {
                $io->block("Plugin '$pluginName' seems tained", 'INFO', 'fg=yellow', ' ', false);
                file_put_contents("$tainted/$pluginName.txt", implode(PHP_EOL, $resultOrg));
                system("cp -r $plugin $tainted");
            } else {
                $io->block("Plugin '$pluginName' seems untained", 'INFO', 'fg=green', ' ', false);
            }

        }

        return Command::SUCCESS;

    }

}
