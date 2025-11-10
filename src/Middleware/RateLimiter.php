<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\HttpContext;
use Swoole\Table;

use Closure, InvalidArgumentException;

class RateLimiter
{
    private Table $table;

    private ?Closure $keyResolver = null;

    private ?Closure $onLimitExceeded = null;

    /**
     * RateLimiter Middleware
     *
     * Supports per-IP, global, or custom (user-defined) rate limiting scopes.
     *
     * @param int $maxRequests Maximum requests allowed within the time window
     * @param int $timeWindow Time window in seconds for rate limiting
     * @param string $scope Rate limiting scope ('ip', 'global', 'custom')
     * @param LoggerInterface|null $logger Optional PSR-3 logger for rate limit warnings
     * @param callable|null $onLimitExceeded Optional callback when limit is exceeded.
     * @param callable|null $keyResolver Custom key resolver for 'custom' scope.
     * @param int $tableSize Size of the Swoole table
     *
     * Usage examples:
     * ```php
     * // Per-IP rate limiting (default)
     * $app->use(new RateLimiter(100, 60));
     *
     * // Global rate limiting (all requests share the same bucket)
     * $app->use(new RateLimiter(1000, 60, 'global'));
     *
     * // Custom rate limiting (e.g., per API key)
     * $app->use(new RateLimiter(
     *     10, 60, 'custom', $logger, null,
     *     fn(HttpContext $ctx) => $ctx->getHeader('X-Api-Key') ?? 'anonymous'
     * ));
     *
     * // Custom rate limiting with callback on limit exceeded
     * $app->use(new RateLimiter(
     *     10, 60, 'ip', $logger,
     *     function(HttpContext $ctx) {
     *         $ctx->text('Too Many Requests', 429);
     *     }
     * ));
     * ```
     */
    public function __construct(
        private int $maxRequests = 100,
        private int $timeWindow = 60,
        private string $scope = 'ip',
        private ?LoggerInterface $logger = null,
        ?callable $onLimitExceeded = null,
        ?callable $keyResolver = null,
        int $tableSize = 65536
    ) {
        if (!\in_array($scope, ['ip', 'global', 'custom'], true)) {
            throw new InvalidArgumentException("Invalid scope '$scope'. Must be 'ip', 'global', or 'custom'.");
        }
        if (isset($keyResolver)) {
            $keyResolver = Closure::fromCallable($keyResolver);
        }
        if (isset($onLimitExceeded)) {
            $onLimitExceeded = Closure::fromCallable($onLimitExceeded);
        }
        if ($scope === 'global') {
            $tableSize = 1;
        }
        $table = new Table($tableSize);
        $table->column('requests', Table::TYPE_INT, 4);
        $table->column('timestamp', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
        $this->scope = $scope;
        $this->keyResolver = $keyResolver;
        $this->onLimitExceeded = $onLimitExceeded;
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $key = match ($this->scope) {
            'ip' => $ctx->getIp(),
            'global' => '__global__',
            'custom' => ($this->keyResolver) ? ($this->keyResolver)($ctx) : '__custom__',
            default => $ctx->getIp(),
        };

        $currentTime = \time();
        $data = $this->table->get($key);
        if ($data) {
            $requests = $data['requests'];
            $timestamp = $data['timestamp'];

            if ($currentTime - $timestamp < $this->timeWindow) {
                if ($requests >= $this->maxRequests) {
                    if ($this->onLimitExceeded) {
                        ($this->onLimitExceeded)($ctx);
                    } else {
                        $ctx->text('429 Too Many Requests', 429);
                    }
                    $this->logger?->warning("Rate limit exceeded for {$key}");
                    return;
                } else {
                    // Within limit, increment the count
                    $this->table->set($key, [
                        'requests' => $requests + 1,
                        'timestamp' => $timestamp,
                    ]);
                }
            } else {
                // Time window expired, reset the count
                $this->table->set($key, [
                    'requests' => 1,
                    'timestamp' => $currentTime,
                ]);
            }
        } else {
            // First request for this key
            $this->table->set($key, [
                'requests' => 1,
                'timestamp' => $currentTime,
            ]);
        }

        $next($ctx);
    }
}
