<?php

declare(strict_types=1);

namespace Swoldier\Http\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\Http\HttpContext;

class RequestLogger
{
    public function __construct(
        private LoggerInterface $logger
    ) {
        $logger->info("RequestLogger middleware initialized");
    }

    public function __invoke(HttpContext $ctx, callable $next)
    {
        $method = $ctx->method();
        $path = $ctx->path();
        $ip = $ctx->ip();

        $this->logger->info("{method} {path} {ip}", [
            'method' => $method,
            'path' => $path,
            'ip' => $ip,
        ]);
        $next($ctx);
    }
}
