<?php

declare(strict_types=1);

namespace Swoldier\Plugin;

use Swoldier\{App, HttpContext};

class Prometheus implements PluginInterface
{
    public function register(App $app): void
    {
        $app->match('GET', '/metrics', function (HttpContext $ctx) {
            $ctx->sendText($this->getMetrics());
        });
    }

    private function getMetrics(): string
    {
        // Gather and return Prometheus metrics
        return '';
    }
}
