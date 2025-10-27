<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Http\Context, BatchLogger};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter, RequestLogger};

App::spawn(function (App $app) {

    $logger = new BatchLogger();

    $app->use(
        new RequestLogger(logger: $logger),
        new ConnectionLimiter(
            maxConnections: 100,
            maxConnectionsPerIp: 5,
            logger: $logger,
        )
    );

    $app->get('/ping', function (Context $ctx) use ($logger) {
        $ctx->text('pong');
        $logger->info("Handled /ping request from {ip}", ['ip' => $ctx->getIp()]);
    });


    $app->get('/hello/:world?', function (Context $ctx) {
        $name = $ctx->getRouteParams('world') ?? 'World';
        $ctx->text("Hello, {$name}!");
    });

    $api = $app->group(
        new RateLimiter(
            maxRequestsPerIp: 100,
            timeWindow: 60,
            logger: $logger,
        )
    );

    $api->get('/data', function (Context $ctx) {
        $ctx->json(['data' => 'This is some rate limited data.']);
    });
}, [
    'port' => 8080,
    'host' => '0.0.0.0'
]);
