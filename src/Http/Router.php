<?php

declare(strict_types=1);

namespace Swoldier\Http;

use Wilaak\Http\RadixRouter as UnderlyingRouter;

class Router
{
    private UnderlyingRouter $router;

    private array $middlewares = [];

    public function __construct(
        private \Swoole\Http\Server $server,
    ) {
        $this->router = new UnderlyingRouter();
    }

    public function use(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function get(string $path, callable $handler): void
    {
        $this->router->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->router->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->router->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->router->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->router->add('DELETE', $path, $handler);
    }

    public function match(array $methods, string $path, callable $handler): void
    {
        $this->router->add($methods, $path, $handler);
    }
}
