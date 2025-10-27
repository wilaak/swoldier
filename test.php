<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{
    App,
    Http\Context,
    ContextAwareLogger
};

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

$app->plugin(new Swoldier\Plugin\Prometheus(
    $logger->withSettings('prometheus')
));

$app->use(
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

$test->get('/test', function (Context $ctx) {
    $ctx->sendJson($ctx->getAttribute('_router')->list());
});

$app->get('/limited', function (Context $ctx) {
    $ctx->write("This is a rate and connection limited endpoint.");
});

$app->get('/hello/:world?', function (Context $ctx) {
    $name = $ctx->getRouteParams('world') ?? 'World';
    $ctx->write("Hello, {$name}!");
});

$app->get('/stats', function (Context $ctx) {
    $stats = [
        'uptime' => time() - $_SERVER['REQUEST_TIME'],
        'memory_usage' => memory_get_usage(),
        'memory_peak_usage' => memory_get_peak_usage(),
    ];
    $ctx->sendJson($stats);
});

$app->run();
