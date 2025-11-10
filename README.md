# Swoldier 🪖

> [!IMPORTANT]  
> This is a baby project and may not be production ready.

Swoole micro-framework for fast concurrent applications

## Install

You can install Swoldier via Composer:

```bash
composer require wilaak/swoldier
```

Since it relies on Swoole, make sure you have the Swoole extension installed.

```bash
pecl install swoole
```

## Get Started

Below is a usage example to get your started.

```php
use Swoldier\{BatchLogger, HttpContext, Router};

use Swoldier\Middleware\{
    RequestLogger,
    BrotliCompression,
    ConnectionLimiter,
    RateLimiter,
};

// Create Swoole HTTP server
$server = new Swoole\Http\Server('0.0.0.0', 8082, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);

// Server settings
$server->set([
    'ssl_cert_file' => __DIR__ . '/cert.pem', // Path to SSL certificate file
    'ssl_key_file' => __DIR__ . '/key.pem',   // Path to SSL key file
    'open_http2_protocol' => true,            // Enable HTTP/2
    'worker_num' => 1,                        // Number of worker processes
    'max_wait_time' => 0,                     // Graceful shutdown time
]);

// Replace blocking PHP functions with coroutine-friendly versions
Swoole\Runtime::enableCoroutine();

// Create logger instance
$logger = new BatchLogger();

// Create router instance
$router = new Router($server);

// Use connection limiter middleware
$connectionLimiter = new ConnectionLimiter(
    maxConnections: 100,
    logger: $logger
);
$router->use($connectionLimiter);

// Use rate limiter middleware
$rateLimiter = new RateLimiter(
    maxRequests: 100,
    timeWindow: 60,
    logger: $logger
);
$router->use($rateLimiter);

// Use request logging middleware
$requestLogger = new RequestLogger($logger);
$router->use($requestLogger);

// Use Brotli compression middleware
$brotliCompression = new BrotliCompression();
$router->use($brotliCompression);

// Serve files from the "public" directory
$router->map('GET', '/public/:file+', function (HttpContext $ctx) {
    $file = $ctx->getRouteParam('file');
    $ctx->file(__DIR__ . '/public/', $file);
});

// Simple route that says hello
$router->map('GET', '/', function (HttpContext $ctx) {
    $ctx->html('<h1>Hello, World!</h1>');
});

// SSE endpoint that sends current time every second
$router->map('GET', '/test', function (HttpContext $ctx) {
    while ($ctx->isConnected()) {
        $ctx->write("data: " . date('Y-m-d H:i:s') . "\n\n");
        sleep(1);
    }
});

// Fallback route for custom 404 handler
$router->map('*', '/:fallback+', function (HttpContext $ctx) {
    $ctx->text('404 Not Found', 404);
});

// Log server start event
$server->on('Start', function () use ($logger) {
    $logger->info("Server started");
});

// Start the server
$server->start();
```

## Routing

The `map` method allows you to define routes for different HTTP methods and patterns.

> [!TIP]    
> See the [RadixRouter](https://github.com/wilaak/radix-router) documentation for more details on the routing capabilities.

```php
// Simple route with a route parameter
$router->map('GET', '/hello/:name', function (HttpContext $ctx) {
    $name = $ctx->getRouteParam('name');
    // ...
});

// Multiple methods for a single route
$router->map(['GET', 'POST'], '/form', function (HttpContext $ctx) {
    // ...
});

// Route for all methods
$router->map('*', '/all-methods', function (HttpContext $ctx) {
    // ...
});
```

### Middleware

The `use` method allows you to register a global middleware that will be executed for every incoming request.

```php
use Swoldier\Middleware\RequestLogger;

$requestLogger = new RequestLogger($logger);
$router->use($requestLogger);
```

The `group` method allows you to create a group of routes that share the same middleware.

```php
use Swoldier\HttpContext;

$middleware = function (HttpContext $ctx, callable $next) {
    $next($ctx);
};

$group = $router->group($middleware);

$group->map('GET', '/grouped', function (HttpContext $ctx) {
    // ...
});
```

## Datastar Integration

See [Datastar Swoldier](https://github.com/wilaak/datastar-swoldier)

## Brotli Compression

To enable Brotli compression for responses, you can use the built-in `BrotliCompression` middleware.

> [!NOTE]   
> Brotli compression requires the `brotli` PHP extension to be installed and enabled. See [Brotli PHP Extension](https://github.com/kjdev/php-ext-brotli).

To install the extension using PECL, run the following command:

```bash
pecl install brotli
```

Then, you can add the `BrotliCompression` middleware to your router as shown below:

```php
use Swoldier\Middleware\BrotliCompression;

$brotliCompression = new BrotliCompression(
    level: 4, // Compression level (0-11)
);

$router->use($brotliCompression);
```

## Links

Some links you might find useful:

* [Introduction to swoole](https://phpgoodness.com/articles/introduction-to-swoole.html)