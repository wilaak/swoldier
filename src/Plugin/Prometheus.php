<?php

declare(strict_types=1);

namespace Swoldier\Plugin;

use Swoldier\{App, Http\HttpContext};

class Prometheus extends BasePlugin
{
    private static ?\Swoole\Table $table = null;

    public function __construct(
        private \Psr\Log\LoggerInterface $logger
    ) {

    }

    public function register(App $app): void
    {
        $this->logger->info("Initializing Prometheus plugin");
        if (self::$table === null) {
            $table = new \Swoole\Table(1024);
            $table->column('requests', \Swoole\Table::TYPE_INT, 8);
            $table->create();
            self::$table = $table;
        }
    }

    public function boot(App $app): void
    {
        // Increment request count for every request (middleware or route)
        $app->globalMiddleware(function (HttpContext $ctx, callable $next) {
            $ip = $ctx->getIp();
            // You can track per-IP or global stats
            self::$table->incr($ip, 'requests');
            $next($ctx);
        });

        $app->match('GET', '/metrics', function (HttpContext $ctx) {
            $lines = [];
            foreach (self::$table as $ip => $row) {
                $lines[] = "requests_total{ip=\"$ip\"} " . $row['requests'];
            }
            $ctx->sendText(implode("\n", $lines));
        });
    }
}
