<?php

namespace App\Command\Nist;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:download:nist:samples',
    description: 'Download nist samples',
)]
class DownloadNistSamplesCommand extends Command
{
    private $projectDir;

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // see https://www.nist.gov/system/files/documents/2021/03/24/Stivalet_Fong_ICST2016.pdf
        // Older tests not really usable / representative

        $zips = [
            // https://samate.nist.gov/SARD/test-suites/103
            'https://samate.nist.gov/SARD/downloads/test-suites/2015-10-27-php-vulnerability-test-suite.zip',
            // https://samate.nist.gov/SARD/test-suites/114
            'https://samate.nist.gov/SARD/downloads/test-suites/2022-05-12-php-test-suite-sqli-v1-0-0.zip',
            // https://samate.nist.gov/SARD/test-suites/115
            'https://samate.nist.gov/SARD/downloads/test-suites/2022-08-02-php-test-suite-xss-v1-0-0.zip',
        ];

        $io = new SymfonyStyle($input, $output);

        $downloadFolder = "{$this->projectDir}/data/nist/samples_zip/";

        if (!is_dir($downloadFolder)) {
            mkdir($downloadFolder, 0755, true);
        }

        $client = new Client([
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36',
            ],
        ]);

        $requests = function ($urls) use ($io) {
            foreach ($urls as $url) {
                $io->writeln("Downloading $url");
                yield new Request('GET', $url);
            }
        };

        $pool = new \GuzzleHttp\Pool($client, $requests($zips), [
            'concurrency' => 50,
            'fulfilled' => function ($response, $index) use ($zips, $downloadFolder, $io) {
                $filename = basename($zips[$index]);
                file_put_contents("$downloadFolder/$filename", $response->getBody());
                $io->success('Downloaded '.$zips[$index]);
            },
            'rejected' => function ($reason, $index) use ($io) {
                $io->error("Request failed for index $index: ".$reason->getMessage());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return Command::SUCCESS;
    }
}
