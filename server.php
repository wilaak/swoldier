
<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, BatchLogger, Event, Http\HttpContext};
use Swoldier\Middleware\RequestLogger;

// Create the application instance
$app = new App(
    port: 8082,
    httpWorkers: 4,
    taskWorkers: 2
);

// Create a logger instance
$logger = new BatchLogger(
    logFilePath: __DIR__ . '/server.log',
);

$app->on(Event::WorkerStart, function ($workerId) use (&$logger, $app) {
    $logger = $logger->withSettings(channel: "worker-{$workerId}");
    $app->use(
        new RequestLogger($logger)
    );
});



// Register a task handler
$app->task('testTask', function (string $data) {
    // Simulate some background work
    return strtoupper($data);
});

// Define a route handler
$app->get('/', function (HttpContext $ctx) {
    // Run a task and wait for the result
    $result = $ctx->awaitTask('testTask', ['test'], 1);
    $ctx->end("Task result: $result");
});

// Log server startup
$app->run(function ($host, $port) use ($logger) {
    $logger->info("Server running at http://{$host}:{$port}/");
});
