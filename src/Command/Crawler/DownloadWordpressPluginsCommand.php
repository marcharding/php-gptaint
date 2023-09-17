<?php

namespace App\Command\Crawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:download:wordpress:plugins',
    description: 'Add a short description for your command',
)]
class DownloadWordpressPluginsCommand extends Command
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

        $folder = "{$this->projectDir}/data/wordpress/plugins/";

        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $client = new Client([
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36'
            ]
        ]);

        $requests = function ($pages, $resultsPerPage = 250) use ($client, $io) {
            foreach ($pages as $page) {
                yield function () use ($client, $page, $resultsPerPage, $io) {
                    $io->writeln("Crawling page $page with $resultsPerPage results per page");
                    return $client->getAsync("https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[page]={$page}&request[per_page]={$resultsPerPage}");
                };
            }
        };

        $results = [];

        $pool = new \GuzzleHttp\Pool($client, $requests(range(0, 200)), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$results) {
                $json = json_decode($response->getBody(), true);
                foreach ($json["plugins"] as $entry) {

                    // only download plugins with at least 5000 installations
                    if ($entry['downloaded'] < 5000) {
                        continue;
                    }

                    // skips plugins that are not updated in 12 months
                    $lastUpdated = new \DateTime($entry['last_updated']);
                    $nowMinus18Month = new \DateTime('-12 months');
                    if ($lastUpdated < $nowMinus18Month) {
                        continue;
                    }

                    $results[] = $entry['download_link'];
                }
            },
            'rejected' => function (RequestException $reason, $index) use ($io) {
                $io->error("Request failed for index $index: " . $reason->getMessage());
            },
        ]);

        // Wait for the requests to complete
        $pool->promise()->wait();

        $downloadFolder = "{$this->projectDir}/data/wordpress/plugins/";

        $requests = function ($urls) use ($io) {
            foreach ($urls as $url) {
                $io->writeln("Downloading $url");
                yield new Request('GET', $url);
            }
        };

        $pool = new \GuzzleHttp\Pool($client, $requests($results), [
            'concurrency' => 50,
            'fulfilled' => function ($response, $index) use ($results, $downloadFolder) {
                $filename = basename($results[$index]);
                file_put_contents("$downloadFolder/$filename", $response->getBody());
            },
            'rejected' => function ($reason, $index) use ($io) {
                $io->error("Request failed for index $index: " . $reason->getMessage());
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return Command::SUCCESS;
    }

}
