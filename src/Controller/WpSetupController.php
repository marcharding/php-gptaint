<?php

namespace App\Controller;

use App\Entity\Code;
use App\Service\WordpressSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/wp_setup')]
class WpSetupController extends AbstractController
{
    #[Route('/{id}', name: 'wp_setup_with_plugin', methods: ['GET'])]
    public function index(Code $code, WordpressSetup $wordpressSetupService, string $projectDir): Response
    {
        $publicPath = '/var/www/application/sandbox/public';
        $wpVersion = '6.2';
        $dbHost = 'database';
        $dbName = 'wordpress';
        $dbUser = 'wordpress';
        $dbPass = 'wordpress';
        $url = 'wp.localhost';
        $title = 'PHP-GPTaint';
        $adminUser = 'admin';
        $adminPassword = 'admin';
        $adminEmail = 'noreply@wp.localhost';

        $plugin = glob($projectDir."/data/wordpress/plugins_zipped/{$code->getDirectory()}*.zip", GLOB_NOSORT);
        $pluginZip = reset($plugin);

        return $wordpressSetupService->setupWordPress($publicPath, $wpVersion, $dbHost, $dbName, $dbUser, $dbPass, $url, $title, $adminUser, $adminPassword, $adminEmail, $pluginZip);
    }
}
