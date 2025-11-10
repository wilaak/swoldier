<?php

declare(strict_types=1);

namespace Swoldier\Middleware;

use Swoldier\HttpContext;

/**
 * Brotli compression middleware.
 *
 * Requires the brotli PHP extension.
 */
class Brotli
{
    /**
     * @param int $level Compression level (0-11)
     */
    public function __construct(
        private int $level = 4
    ) {
    }

    public function __invoke(HttpContext $ctx, callable $next): void
    {
        $encoding = $ctx->header('accept-encoding') ?? '';
        if (\str_contains($encoding, 'br')) {
            $ctx->setHeader('content-encoding', 'br');
            $resource = \brotli_compress_init($this->level);
            $ctx->setWriter(function (string $data) use ($resource) {
                return \brotli_compress_add($resource, $data, BROTLI_FLUSH);
            });
        }
        $next($ctx);
    }
}
