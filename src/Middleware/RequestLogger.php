<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\HttpContext;

use Throwable;

class RequestLogger
{
    /**
     * @param LoggerInterface $logger PSR-3 compatible logger instance
     */
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $method = $ctx->getMethod();
        $uri = $ctx->getUri();
        $ip = $ctx->getIp();
        $agent = $ctx->getHeader('user-agent') ?? '-';

        $this->logger->info("{ip} {method} {uri} - {agent}", [
            'ip' => $ip,
            'method' => $method,
            'uri' => $uri,
            'agent' => $agent,
        ]);

        try {
            $next($ctx);
        } catch (Throwable $e) {
            $ctx->text('Internal Server Error', 500);
            $this->logger->error("{ip} {method} {uri} - {message}\n{trace}", [
                'ip' => $ip,
                'method' => $method,
                'uri' => $uri,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'agent' => $agent,
            ]);
            return;
        }
    }
}
