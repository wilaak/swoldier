<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\{App, Event, Http\HttpContext};

$app = new App(port: 8082, httpWorkers: 1, taskWorkers: 1);

$app->task('testTask', function (int $taskId, int $workerId, string $data) {
    echo "Executing task #$taskId in worker #$workerId with data: $data\n";
    return strtoupper($data);
});

$app->get('/', function (HttpContext $ctx) {

    $result = $ctx->awaitTask('testTask', ['test']);

    var_dump($result);

    $ctx->end("Task result: $result");
});

$app->run(function ($host, $port) {
    echo "Server running at http://{$host}:{$port}\n";
});
