<?php

declare(strict_types=1);

/*
 * Export the public marketing pages as static HTML for GitHub Pages.
 *
 * Usage: php scripts/export-static-site.php
 * Override the deployment URL with STATIC_SITE_URL.
 *
 * GitHub Pages cannot run PHP, so this is a snapshot of the public site only:
 * sign-in, forms and the member portal still require a PHP host.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$baseUrl = getenv('STATIC_SITE_URL') ?: 'https://cheruiyotruto.github.io/chamacore';
$host = parse_url($baseUrl, PHP_URL_HOST);

$_ENV['APP_URL'] = $_SERVER['APP_URL'] = $baseUrl;
putenv('APP_URL='.$baseUrl);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_HOST' => $host, 'HTTPS' => 'on']));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app['url']->forceRootUrl($baseUrl);
$app['url']->forceScheme('https');

$outDir = __DIR__.'/../static-site';
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($outDir.'/.nojekyll', '');

$pages = [
    '/' => 'index.html',
    '/features' => 'features/index.html',
    '/pricing' => 'pricing/index.html',
    '/about' => 'about/index.html',
    '/contact' => 'contact/index.html',
];

foreach ($pages as $uri => $file) {
    $request = Request::create($uri, 'GET', [], [], [], ['HTTP_HOST' => $host]);
    $response = $kernel->handle($request);

    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, sprintf("Failed to render %s: HTTP %d\n", $uri, $response->getStatusCode()));
        exit(1);
    }

    $target = $outDir.'/'.$file;
    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    file_put_contents($target, $response->getContent());

    echo sprintf("%s -> %s\n", $uri, $file);
    $kernel->terminate($request, $response);
}
