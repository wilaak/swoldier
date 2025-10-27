<?php

declare(strict_types=1);

namespace Swoldier\Plugin;

use Swoldier\App;

abstract class BasePlugin
{
    /**
     * Called in the main process when starting the application.
     */
    public function register(App $app): void {}

    /**
     * Called at the start of each worker process.
     */
    public function boot(App $app): void {}

    /**
     * Called at the shutdown of each worker process.
     */
    public function shutdown(App $app): void {}
}
