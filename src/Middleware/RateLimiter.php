<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\Http\HttpContext;
use Swoole\Table;

class RateLimiter
{
    private Table $table;

    /**
     * @param int $maxRequestsPerIp Maximum requests allowed per IP within the time window
     * @param int $timeWindow Time window in seconds
     * @param LoggerInterface|null $logger Optional logger for logging rate limit breaches
     * @param int $workerId Worker ID for logging purposes, will show init message only for worker 0
     */
    public function __construct(
        private int $maxRequestsPerIp = 100,
        private int $timeWindow = 60,
        private ?LoggerInterface $logger = null,
        private int $workerId = 0
    ) {
        $table = new Table(1024);
        $table->column('requests', Table::TYPE_INT, 4);
        $table->column('timestamp', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
        if ($this->workerId === 0) {
            $this->logger?->info("RateLimiter initialized: maxRequestsPerIp={$maxRequestsPerIp}, timeWindow={$timeWindow}");
        }
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $maxRequests = $this->maxRequestsPerIp;
        $timeWindow = $this->timeWindow;
        $table = $this->table;

        $ip = $ctx->getIp();
        $currentTime = \time();

        $data = $table->get($ip);
        if ($data) {
            $requests = $data['requests'];
            $timestamp = $data['timestamp'];

            if ($currentTime - $timestamp < $timeWindow) {
                // Within time window
                if ($requests >= $maxRequests) {
                    // Rate limit exceeded
                    $ctx->abortConnection();
                    $this->logger?->warning("Rate limit exceeded for {$ip}: {$requests} requests");
                    return;
                } else {
                    // Increment request count
                    $table->set($ip, [
                        'requests' => $requests + 1,
                        'timestamp' => $timestamp,
                    ]);
                }
            } else {
                // Time window expired, reset count
                $table->set($ip, [
                    'requests' => 1,
                    'timestamp' => $currentTime,
                ]);
            }
        } else {
            // First request from this IP
            $table->set($ip, [
                'requests' => 1,
                'timestamp' => $currentTime,
            ]);
        }

        // Proceed to next middleware or handler
        $next($ctx);
    }
}
