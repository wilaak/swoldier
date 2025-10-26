<?php

declare(strict_types=1);

namespace Swoldier\Plugin;

use Swoldier\App;

interface PluginInterface
{
    /**
     * Register the plugin with the given application instance
     */
    public function register(App $app): void;
}
