<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Runtime;
use Swoole\Http\{Server, Request, Response};
use Wilaak\Http\RadixRouter;

class App
{
    private array $routes = [];
    private array $middleware = [];
    private array $globalMiddleware = [];
    private RadixRouter $router;

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
         * List of trusted proxy IP addresses when determining client IP address
         */
        public array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
        $this->router = new RadixRouter();
    }

    /**
     * Add a route with specified HTTP methods, pattern and handler
     *
     * @param string|array $methods HTTP method or list of methods
     * @param string $pattern Route pattern (e.g. /users/:id)
     * @param callable $handler Function to handle the request
     */
    public function match(string|array $methods, string $pattern, callable $handler)
    {
        $handler = $this->buildMiddlewarePipeline($this->middleware, $handler);
        $this->routes[] = [
            'method' => $methods,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Add a route for all HTTP methods
     *
     * @param string $pattern Route pattern (e.g. /users/:id)
     * @param callable $handler Function to handle the request
     */
    public function any(string $pattern, callable $handler)
    {
        $this->match($this->router->allowedMethods, $pattern, $handler);
    }

    /**
     * Create a route group with shared middleware
     */
    public function groupMiddleware(callable ...$middleware): self
    {
        $group = clone $this;
        $group->routes = &$this->routes;
        $group->middleware = [...$this->middleware, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware to be applied to all requests
     */
    public function globalMiddleware(callable ...$middleware): void
    {
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
    }

    /**
     * Build middleware pipeline
     */
    private function buildMiddlewarePipeline(array $middlewares, callable $finalHandler): callable
    {
        return \array_reduce(
            \array_reverse($middlewares),
            function ($next, $middleware) {
                return function (HttpContext $ctx) use ($middleware, $next) {
                    return $middleware($ctx, $next);
                };
            },
            $finalHandler
        );
    }

    /**
     * Start the server and listen for requests
     */
    public function run(?callable $callback = null): void
    {
        Runtime::enableCoroutine();

        $router = $this->router;
        $server = new Server($this->host, $this->port);

        $server->set([
            'worker_num' => $this->workers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        foreach ($this->routes as $route) {
            $router->add(
                $route['method'],
                $route['pattern'],
                $route['handler']
            );
        }

        $globalMiddlewarePipeline = $this->buildMiddlewarePipeline(
            $this->globalMiddleware,
            fn (HttpContext $ctx) => $this->handleRequest($ctx)
        );

        $server->on('Request', function (Request $req, Response $res) use ($server, $router, $globalMiddlewarePipeline) {
            $method = $req->server['request_method'];

            $path = \strtok($req->server['request_uri'], '?');
            $decodedPath = \rawurldecode($path);

            $result = $router->lookup($method, $decodedPath);

            $ctx = new HttpContext(
                $server,
                $req,
                $res,
                $result['params'] ?? [],
                $this->trustedProxies
            );

            try {
                $ctx->setAttribute('_result', $result);
                $ctx->setAttribute('_router', $router);
                $globalMiddlewarePipeline($ctx);
            } catch (\Throwable $e) {
                $res->status(500);
                $res->end("Internal Server Error");
            }
        });

        $server->on('Start', function () use ($callback, $server) {
            if ($callback) {
                $callback($server);
            }
        });

        $server->start();
    }

    private function handleRequest(HttpContext $ctx): void
    {
        $result = $ctx->getAttribute('_result');
        $router = $ctx->getAttribute('_router');

        switch ($result['code']) {
            case 200:
                if ($ctx->getMethod() === 'OPTIONS') {
                    $allowedMethods = $router->methods($ctx->getPath());
                    $ctx->setHeader('Allow', \implode(',', $allowedMethods));
                }
                $result['handler']($ctx);
                break;

            case 404:
                $ctx->sendText('404 Not Found', 404);
                break;

            case 405:
                $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));
                if ($ctx->getMethod() === 'OPTIONS') {
                    $ctx->setStatus(204);
                    break;
                }
                $ctx->sendText('405 Method Not Allowed', 405);
                break;
        }

        $ctx->close();
    }
}
