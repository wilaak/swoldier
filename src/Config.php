<?php

declare(strict_types=1);

namespace Swoldier;

class Config
{
    public function __construct(
        /**
         * Server listening port
         */
        public int $port = 8080,

        /**
         * Server listening host
         */
        public string $host = '127.0.0.1',

        /**
         * Number of worker processes
         */
        public int $workers = 1,

        /**
         * Enable coroutine hooks for built-in functions (see Swoole documentation)
         */
        public bool $enableCoroutineHook = true,

        /**
         * List of trusted proxy IP addresses when determining client IP address
         */
        public array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
    }
}
