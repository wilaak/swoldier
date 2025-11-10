<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Swoldier\HttpContext;

class ZstdCompression
{
    /**
     * Zstd Compression Middleware
     *
     * Enables Zstd compression for HTTP responses when the client supports it.
     *
     * @param int $level Compression level (1-22), default is 4
     */
    public function __construct(
        private int $level = 3
    ) {
    }

    public function __invoke(HttpContext $ctx, callable $next): void
    {
        if (!\function_exists('zstd_compress_init')) {
            \fwrite(STDERR, "Zstd extension is not installed. Skipping Zstd compression middleware.\n");
            $next($ctx);
            return;
        }

        $encoding = $ctx->getHeader('accept-encoding') ?? '';
        if (\str_contains($encoding, 'zstd')) {
            $ctx->setHeader('content-encoding', 'zstd');
            $resource = \zstd_compress_init($this->level);
            $ctx->setWriter(function (string $data) use ($resource) {
                return \zstd_compress_add($resource, $data);
            });
        }
        $next($ctx);
    }
}
