<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{BatchLogger, HttpContext, Router};

use Swoldier\Middleware\{
    RequestLogger,
    BrotliCompression,
    ConnectionLimiter,
    RateLimiter,
};

// Create Swoole HTTP server
$server = new Swoole\Http\Server('0.0.0.0', 8081, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

// Server settings
$server->set([
    'worker_num' => 1,                        // Number of worker processes
    'max_wait_time' => 0,                     // Graceful shutdown time
]);

// Enable Swoole coroutine hooks
// This replaces blocking PHP built-in functions with coroutine-friendly versions
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