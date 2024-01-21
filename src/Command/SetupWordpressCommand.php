<?php

namespace App\Command;

use App\Service\WordpressSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:setup-wordpress',
    description: 'Setup WordPress using Symfony Process and Filesystem Component'
)]
class SetupWordpressCommand extends Command
{
    private WordpressSetup $wordpressSetupService;

    public function __construct(WordpressSetup $wordpressSetupService)
    {
        parent::__construct();
        $this->wordpressSetupService = $wordpressSetupService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'WordPress installation path', '/var/www/application/sandbox/public')
            ->addOption('wp-version', null, InputOption::VALUE_REQUIRED, 'WordPress version', '6.2')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Database host', 'database')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Database name', 'wordpress')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Database user', 'wordpress')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Database password', 'wordpress')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'WordPress site URL', 'wp.localhost')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'WordPress site title', 'PHP-GPTaint')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Admin username', 'admin')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password', 'admin')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email', 'noreply@wp.localhost')
            ->addOption('plugin-zip', null, InputOption::VALUE_REQUIRED, 'Path to plugin zip');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publicPath = $input->getOption('path');
        $wpVersion = $input->getOption('wp-version');
        $dbHost = $input->getOption('db-host');
        $dbName = $input->getOption('db-name');
        $dbUser = $input->getOption('db-user');
        $dbPass = $input->getOption('db-pass');
        $url = $input->getOption('url');
        $title = $input->getOption('title');
        $adminUser = $input->getOption('admin-user');
        $adminPassword = $input->getOption('admin-password');
        $adminEmail = $input->getOption('admin-email');
        $pluginZip = $input->getOption('plugin-zip');

        if ($this->wordpressSetupService->setupWordPress($publicPath, $wpVersion, $dbHost, $dbName, $dbUser, $dbPass, $url, $title, $adminUser, $adminPassword, $adminEmail, $pluginZip)) {
            $output->writeln('WordPress setup completed.');
            return Command::SUCCESS;
        } else {
            $output->writeln('WordPresssetup failed.');
            return Command::FAILURE;
        }
    }
}
