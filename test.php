<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Http\HttpContext, ContextAwareLogger};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter};

$logger = new ContextAwareLogger(
    logFilePath: __DIR__ . '/server.log',
);

$app = new App(
    host: '0.0.0.0',
    port: 8080,
    workers: 1,
    logger: $logger,
);

$app->registerPlugin(new Swoldier\Plugin\Prometheus(
    $logger->withSettings('prometheus')
));

$app->globalMiddleware(
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

$test = $app->groupMiddleware(
    function (HttpContext $ctx, callable $next) use ($logger) {
        $logger->info("Handling request for {path} from {ip}", [
            'path' => $ctx->getPath(),
            'ip' => $ctx->getIp(),
        ]);
        $next($ctx);
    }
);

$test->get('/test', function (HttpContext $ctx) use ($logger) {
    $ctx->sendJson($ctx->getAttribute('_router')->list());
});

$app->get('/limited', function (HttpContext $ctx) use ($logger) {
    $ctx->write("This is a rate and connection limited endpoint.");
});

$app->get('/hello/:world?', function (HttpContext $ctx) {
    $name = $ctx->getRouteParams('world') ?? 'World';
    $ctx->write("Hello, {$name}!");
});

$app->get('/stats', function (HttpContext $ctx) {
    $stats = [
        'uptime' => time() - $_SERVER['REQUEST_TIME'],
        'memory_usage' => memory_get_usage(),
        'memory_peak_usage' => memory_get_peak_usage(),
    ];
    $ctx->sendJson($stats);
});

$app->run();
