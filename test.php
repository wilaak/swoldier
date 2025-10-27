<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Http\HttpContext, BatchLogger, Http\Enum\HttpMethod, Http\Enum\HttpStatus};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter, RequestLogger};

App::spawn(function (App $app) {

    $logger = new BatchLogger();

    $app->use(new RequestLogger($logger), new ConnectionLimiter(100, 5, $logger));

    $app->route(HttpMethod::GET, '/ping', function (HttpContext $ctx) use ($logger) {
        $ctx->text('pong');
        $logger->info("Handled /ping request from {ip}", ['ip' => $ctx->ip()]);
    });

    $app->route(HttpMethod::GET, '/hello/:world?', function (HttpContext $ctx) {
        $name = $ctx->param('world') ?? 'World';
        $ctx->text("Hello, {$name}!");
    });

    $api = $app->group(
        new RateLimiter(100, 60, $logger)
    );

    $api->route(HttpMethod::GET, '/data', function (HttpContext $ctx) {
        $ctx->json(['data' => 'This is some rate limited data.']);
    });

}, [
    'host' => '0.0.0.0',
    'port' => 8080,
    'workers' => 4,
]);