<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, HttpContext, SimpleLogger};
use Swoldier\Middleware\{ConnectionLimiter, RateLimiter};

$app = new App(
    host: '0.0.0.0',
    port: 8080,
    workers: 40,
);

$logger = new SimpleLogger(
    logFilePath: __DIR__ . '/server.log',
);

$limited = $app->groupMiddleware(
    new ConnectionLimiter(
        maxConnections: 500,
        maxConnectionsPerIp: 1,
        logger: $logger,
    ),
    new RateLimiter(
        maxRequestsPerIp: 100,
        timeWindow: 60,
        logger: $logger,
    )
);

$limited->match('GET', '/limited', function (HttpContext $ctx) {
    $ctx->write("This is a rate and connection limited endpoint.");
});

$app->globalMiddleware(function (HttpContext $ctx, callable $next) use ($logger) {
    $logger->info("Incoming request: {$ctx->getMethod()} {$ctx->getPath()} from {$ctx->getIp()}");
    $next($ctx);
});

$app->match('GET', '/hello/:world?', function (HttpContext $ctx) {
    $name = $ctx->getRouteParams('world') ?? 'World';
    $ctx->write("Hello, {$name}!");
});

$app->match('GET', '/stats', function (HttpContext $ctx) {
    $stats = [
        'uptime' => time() - $_SERVER['REQUEST_TIME'],
        'memory_usage' => memory_get_usage(),
        'memory_peak_usage' => memory_get_peak_usage(),
    ];
    $serverStats = $ctx->srv->stats();
    $ctx->sendJson($stats + $serverStats);
});

$app->run(function ($server) use ($logger) {
    $logger->info("Server started on {$server->host}:{$server->port}");
    $workerCount = $server->setting['worker_num'] ?? 1;
    $logger->info("Worker processes: {$workerCount}");
});
