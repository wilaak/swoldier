<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Http\HttpContext, BatchLogger, Http\Enum\HttpMethod, Http\Enum\HttpStatus};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter, RequestLogger};

App::spawn(function (App $app) {

    $logger = new BatchLogger();

    $app->addRoute(HttpMethod::GET, '/ping', function (HttpContext $ctx) use ($logger) {
        $ctx->text('pong');
        $logger->info("Handled /ping request from {ip}", ['ip' => $ctx->getClientIp()]);
    });

    $app->addRoute(HttpMethod::GET, '/hello/:world?', function (HttpContext $ctx) {
        $name = $ctx->getParam('world') ?? 'World';
        $ctx->text("Hello, {$name}!");
    });

    $api = $app->group(
        new RateLimiter(100, 60, $logger)
    );

    $api->addRoute(HttpMethod::GET, '/data', function (HttpContext $ctx) {
        $ctx->json(['data' => 'This is some rate limited data.']);
    });

}, [
    'host' => '0.0.0.0',
    'port' => 8080,
    'workers' => 40,
]);