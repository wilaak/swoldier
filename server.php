
<?php

require __DIR__ . '/vendor/autoload.php';

use Swoldier\HttpContext;

$server = new Swoole\Http\Server('0.0.0.0', 8082, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);

$server->set([
    'ssl_cert_file' => __DIR__ . '/../gui-test/cert.pem',
    'ssl_key_file' => __DIR__ . '/../gui-test/key.pem',
    'worker_num' => 1,
    'enable_reuse_port' => true,
    'open_cpu_affinity' => true,
    'max_wait_time' => 0,
    'open_http2_protocol' => true
]);

$router = new Swoldier\Router($server);

$brotli = new Swoldier\Middleware\Brotli(level: 4);

$router->use($brotli);

$router->map('GET', '/:file+', function (HttpContext $ctx) {
    $file = $ctx->routeParam('file');
    $ctx->file(__DIR__ . '/public/', $file);

});

$router->map('GET', '/', function (HttpContext $ctx) {
    $ctx->html('<h1>Hello, World!</h1>');
});

$router->map('get', '/test', function (HttpContext $ctx) {
    while ($ctx->connected()) {
        $ctx->write("data: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite(STDOUT, "Sent data to client\n");
        Swoole\Coroutine\System::sleep(1);
    }
});

$server->start();
