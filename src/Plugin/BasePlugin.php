<?php

declare(strict_types=1);

namespace Swoldier\Plugin;

use Swoldier\App;

abstract class BasePlugin
{
    /**
     * Called at the start of each worker process.
     */
    public function boot(App $app): void
    {
    }

    /**
     * Called when the server is shutting down.
     */
    public function shutdown(App $app): void
    {
    }
}
