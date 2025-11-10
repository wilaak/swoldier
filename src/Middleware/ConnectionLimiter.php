<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\HttpContext;
use Swoole\{Table, Atomic};

class ConnectionLimiter
{
    private Table $table;

    private Atomic $totalConnections;

    private ?LoggerInterface $logger = null;

    /**
     * @param int $maxConnections Maximum total concurrent connections allowed
     * @param int $maxConnectionsPerIp Maximum concurrent connections allowed per IP
     */
    public function __construct(
        private int $maxConnections = 1000,
        private int $maxConnectionsPerIp = 1,
        private int $workerId = 0
    ) {
        $table = new Table($maxConnections + 1024);
        $table->column('count', Table::TYPE_INT);
        $table->create();
        $this->table = $table;
        $this->totalConnections = new Atomic(0);
    }

    /**
     * @param LoggerInterface $logger Logger instance for logging
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $maxTotal = $this->maxConnections;
        $maxPerIp = $this->maxConnectionsPerIp;
        $table = $this->table;
        $atomic = $this->totalConnections;

        $ip = $ctx->ip();
        $count = $table->get($ip, 'count') ?? 0;
        $totalConnections = $atomic->get();

        if ($totalConnections >= $maxTotal) {
            $ctx->abort();
            $this->logger?->error("Total connection limit exceeded: {$totalConnections} connections");
            return;
        }

        if ($count >= $maxPerIp) {
            $ctx->abort();
            $this->logger?->warning("Connection limit exceeded for {$ip}: {$count} connections");
            return;
        }

        $table->set($ip, ['count' => $count + 1]);
        $atomic->add(1);
        $next($ctx);
        $newCount = ($table->get($ip, 'count') ?? 1) - 1;
        if ($newCount > 0) {
            $table->set($ip, ['count' => $newCount]);
        } else {
            $table->del($ip);
        }
        $atomic->sub(1);
    }
}
