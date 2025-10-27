<?php

namespace Swoldier\Http\Middleware;

use Psr\Log\LoggerInterface;
use Swoldier\Http\Context;

class RequestLogger
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function __invoke(Context $ctx, callable $next)
    {
        $method = $ctx->getMethod();
        $path = $ctx->getPath();
        $ip = $ctx->getIp();

        $this->logger?->info("{method} {path} {ip}", [
            'method' => $method,
            'path' => $path,
            'ip' => $ip,
        ]);
        $next($ctx);
    }
}
