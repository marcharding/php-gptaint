<?php

namespace App\Service;


use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class WordpressSetup
{
    private Filesystem $filesystem;
    private string $projectDir;

    public function __construct(Filesystem $filesystem, string $projectDir)
    {
        $this->filesystem = $filesystem;
        $this->projectDir = $projectDir;
    }

    public function setupWordPress(
        string $publicPath,
        string $wpVersion,
        string $dbHost,
        string $dbName,
        string $dbUser,
        string $dbPass,
        string $url,
        string $title,
        string $adminUser,
        string $adminPassword,
        string $adminEmail,
        string $pluginZip
    ): StreamedResponse {
        $this->filesystem->remove(glob($publicPath.'/*'));

        $commands = [
            "$this->projectDir/vendor/wp-cli/wp-cli/bin/wp --allow-root core download --version=$wpVersion --path=$publicPath",
            "$this->projectDir/vendor/wp-cli/wp-cli/bin/wp --allow-root config create --dbhost=$dbHost --dbname=$dbName --dbuser=$dbUser --dbpass=$dbPass --path=$publicPath --force",
            "$this->projectDir/vendor/wp-cli/wp-cli/bin/wp --allow-root db reset --path=$publicPath --yes",
            "$this->projectDir/vendor/wp-cli/wp-cli/bin/wp --allow-root core install --url=$url --title=$title --admin_user=$adminUser --admin_password=$adminPassword --admin_email=$adminEmail --path=$publicPath --skip-email",
            "$this->projectDir/vendor/wp-cli/wp-cli/bin/wp --allow-root plugin install $pluginZip --path=$publicPath --activate",
        ];

        $response = new StreamedResponse();
        $response->setCallback(function () use ($commands) {
            foreach ($commands as $command) {

                $process = new Process(explode(' ', $command));
                $process->run();

                if (!$process->isSuccessful()) {
                    return false;
                }

                echo $process->getOutput();

                ob_flush();
                flush();
            }
        });

        return $response;

    }
}
