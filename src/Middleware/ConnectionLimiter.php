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

    public function __construct(
        private int $maxConnections = 1000,
        private int $maxConnectionsPerIp = 1,
        private ?LoggerInterface $logger = null
    ) {
        $table = new Table($maxConnections);
        $table->column('count', Table::TYPE_INT);
        $table->create();
        $this->table = $table;
        $this->totalConnections = new Atomic(0);
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $maxPerIp = $this->maxConnectionsPerIp;
        $maxTotal = $this->maxConnections;
        $table = $this->table;
        $atomic = $this->totalConnections;

        $ip = $ctx->getIp();
        $count = $table->get($ip, 'count') ?? 0;
        $totalConnections = $atomic->get();

        if ($totalConnections >= $maxTotal) {
            $ctx->sendJson(['error' => 'Too many total connections'], 429);
            $this->logger?->warning("Total connection limit exceeded: {$totalConnections} connections");
            return;
        }

        if ($count >= $maxPerIp) {
            $ctx->terminate();
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
