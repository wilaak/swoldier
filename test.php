<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Http\HttpContext, BatchLogger, Http\Enum\HttpMethod, Http\Enum\HttpStatus};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter, RequestLogger};
use Swoldier\HttpWorker;

$app = new App(httpWorkers: 20);
$rateLimiter = new RateLimiter(10, 60);

$app->httpWorker(function (HttpWorker $worker) use($rateLimiter) {
    $logger = new BatchLogger();
    $logger = $logger->withSettings(
        channel: "HttpWorker{$worker->id}",
    );

    $worker->globalMiddleware(
        new RequestLogger($logger, $worker->id),
        new ConnectionLimiter(500, 5, $logger, $worker->id)
    );

    $worker->addRoute(HttpMethod::GET, '/ping', function (HttpContext $ctx) use ($logger) {
        $ctx->text('pong');
        $logger->info("Handled /ping request from {ip}", ['ip' => $ctx->getClientIp()]);
    });

    $worker->addRoute(HttpMethod::GET, '/hello/:world?', function (HttpContext $ctx) use ($worker) {
        $name = $ctx->getParam('world') ?? 'World';
        $ctx->text("Hello, {$name}! (from worker {$worker->id})");
    });

    $api = $worker->groupMiddleware(
        $rateLimiter,
    );

    $api->addRoute(HttpMethod::GET, '/data', function (HttpContext $ctx) {
        $ctx->json(['data' => 'This is some rate limited data.']);
    });
});

$app->run();