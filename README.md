# Swoldier

Swoldier is a micro-framework powered by Swoole. It's coroutine based HTTP server enables you to build fast, concurrent, and scalable applications using PHP.

It does not use PSR-15 request objects making it an ideal choice for real-time streaming applications.

## Usage

Below is a basic usage examle:

```PHP
use Swoldier\{App, Http\Context, BatchLogger};
use Swoldier\Http\Middleware\{ConnectionLimiter, RateLimiter};

$logger = new BatchLogger();

$app = new App(
    host: '0.0.0.0',
    port: 8080,
    logger: $logger,
);

/**
 * Add global middleware
 */
$app->use(
    new ConnectionLimiter(
        maxConnections: 100,
        maxConnectionsPerIp: 5,
        logger: $logger,
    )
);

/**
 * Register a simple route
 */
$app->get('/hello/:world?', function (Context $ctx) {
    $name = $ctx->getRouteParams('world') ?? 'World';
    $ctx->write("Hello, {$name}!");
});

/**
 * Create a route group with specific middleware
 */
$api = $app->group(
    new RateLimiter(
        maxRequestsPerIp: 100,
        timeWindow: 60,
        logger: $logger,
    )
);

/**
 * Add routes to the group
 */
$api->get('/data', function (Context $ctx) {
    $ctx->sendJson(['data' => 'This is some rate limited data.']);
});

/**
 * Start the server
 */
$app->run();
```

## Plugins

```php
$app->plugin(
    // Load your plugin of choice!
);
```