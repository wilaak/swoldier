<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Psr\Log\LoggerInterface;

use Swoldier\HttpContext;

use Swoole\Table;

use Closure, InvalidArgumentException;

class ConnectionLimiter
{
    private Table $table;

    private ?Closure $keyResolver;

    private ?Closure $onLimitExceeded;

    /**
     * ConnectionLimiter Middleware
     *
     * Support per-IP, global, or custom (user-defined) connection limiting scopes.
     *
     * @param int $maxConnections Maximum concurrent connections
     * @param string $scope Limiting scope: 'ip', 'global', or 'custom'
     * @param LoggerInterface|null $logger Optional PSR-3 logger for limit events
     * @param callable|null $onLimitExceeded Callback when limit is exceeded.
     * @param callable|null $keyResolver Custom key resolver for 'custom' scope.
     * @param int $tableSize Swoole table size
     *
     * Usage examples:
     * ```php
     * // Per-IP connection limiting
     * $app->use(new ConnectionLimiter(2, 'ip'));
     *
     * // Global connection limit
     * $app->use(new ConnectionLimiter(1000, 'global'));
     *
     * // Custom connection limiting
     * $app->use(new ConnectionLimiter(
     *     3, 'custom',
     *     keyResolver: fn(HttpContext $ctx) => $ctx->getHeader('X-User-Id') ?? 'guest'
     * ));
     *
     * // With custom onLimitExceeded callback
     * $app->use(new ConnectionLimiter(
     *     onLimitExceeded: function(HttpContext $ctx) {
     *         $ctx->text('Too many connections', 429);
     *     }
     * ));
     * ```
     */
    public function __construct(
        private int $maxConnections = 25,
        private string $scope = 'ip',
        private ?LoggerInterface $logger = null,
        ?callable $onLimitExceeded = null,
        ?callable $keyResolver = null,
        int $tableSize = 65536,
    ) {
        if (!\in_array($scope, ['ip', 'global', 'custom'], true)) {
            throw new InvalidArgumentException("Invalid scope '$scope'. Must be 'ip', 'global', or 'custom'.");
        }
        if (isset($onLimitExceeded)) {
            $onLimitExceeded = Closure::fromCallable($onLimitExceeded);
        }
        if (isset($keyResolver)) {
            $keyResolver = Closure::fromCallable($keyResolver);
        }
        if ($scope === 'global') {
            $tableSize = 1;
        }
        $table = new Table($tableSize);
        $table->column('count', Table::TYPE_INT);
        $table->create();
        $this->scope = $scope;
        $this->table = $table;
        $this->onLimitExceeded = $onLimitExceeded;
        $this->keyResolver = $keyResolver;
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $key = match ($this->scope) {
            'ip' => $ctx->getIp(),
            'global' => '__global__',
            'custom' => ($this->keyResolver) ? ($this->keyResolver)($ctx) : '__custom__',
            default => $ctx->getIp(),
        };

        $table = $this->table;

        $count = $table->get($key, 'count') ?? 0;
        if ($count >= $this->maxConnections) {
            if ($this->onLimitExceeded) {
                ($this->onLimitExceeded)($ctx);
            } else {
                $ctx->text('Too Many Connections', 429);
            }
            $this->logger?->warning("Connection limit exceeded for {$key}: {$count} connections");
            return;
        }

        // Increment connection count
        $table->set($key, ['count' => $count + 1]);

        try {
            $next($ctx);
        } finally {
            // Decrement connection count
            $newCount = ($table->get($key, 'count') ?? 1) - 1;
            if ($newCount > 0) {
                $table->set($key, ['count' => $newCount]);
            } else {
                $table->del($key);
            }
        }
    }
}
