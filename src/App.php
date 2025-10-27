<?php

declare(strict_types=1);

namespace Swoldier;

use Swoldier\Http\{HttpContext, Enum\HttpMethod, Enum\HttpStatus};

use Swoole\Runtime;
use Swoole\Http\{Server, Request, Response};

use Wilaak\Http\RadixRouter;

/**
 * Swoldier micro-framework
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/swoldier
 */
class App
{
    /**
     * Current group middleware stack
     */
    private array $middleware = [];

    /**
     * Global middleware stack
     */
    private array $globalMiddleware = [];

    /**
     * Underlying radix tree router implementation
     */
    private RadixRouter $router;

    private function __construct(
        public array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
        $this->router = new RadixRouter();

        $this->router->allowedMethods = [];
        foreach (HttpMethod::cases() as $method) {
            $this->router->allowedMethods[] = $method->value;
        }
    }

    /**
     * Register route
     *
     * @param HttpMethod|string|array $methods HTTP method or list of methods
     * @param string $pattern Route pattern (e.g. /users/:id)
     * @param callable $handler Function to handle the request
     */
    public function route(HttpMethod|string|array $methods, string $pattern, callable $handler)
    {
        $handler = $this->buildMiddlewarePipeline($this->middleware, $handler);

        if (!\is_array($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as $key => $method) {
            if ($method instanceof HttpMethod) {
                $methods[$key] = $method->value;
            }
        }

        $this->router->add($methods, $pattern, $handler);
    }

    /**
     * Alias for route('GET', ...)
     */
    public function get(string $pattern, callable $handler)
    {
        $this->route('GET', $pattern, $handler);
    }

    /**
     * Alias for route('POST', ...)
     */
    public function post(string $pattern, callable $handler)
    {
        $this->route('POST', $pattern, $handler);
    }

    /**
     * Alias for route('PUT', ...)
     */
    public function put(string $pattern, callable $handler)
    {
        $this->route('PUT', $pattern, $handler);
    }

    /**
     * Alias for route('PATCH', ...)
     */
    public function patch(string $pattern, callable $handler)
    {
        $this->route('PATCH', $pattern, $handler);
    }

    /**
     * Alias for route('DELETE', ...)
     */
    public function delete(string $pattern, callable $handler)
    {
        $this->route('DELETE', $pattern, $handler);
    }

    /**
     * Alias for route('HEAD', ...)
     */
    public function head(string $pattern, callable $handler)
    {
        $this->route('HEAD', $pattern, $handler);
    }

    /**
     * Alias for route('OPTIONS', ...)
     */
    public function options(string $pattern, callable $handler)
    {
        $this->route('OPTIONS', $pattern, $handler);
    }

    /**
     * Alias for all HTTP methods
     */
    public function any(string $pattern, callable $handler)
    {
        $this->route($this->router->allowedMethods, $pattern, $handler);
    }

    /**
     * Create a route group with specific middleware
     *
     * @param callable ...$middleware Middleware functions to apply to the group
     * @return self New App instance representing the route group
     */
    public function group(callable ...$middleware): self
    {
        $group = clone $this;
        $group->middleware = [...$this->middleware, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware
     *
     * @param callable ...$middleware Middleware functions to add
     */
    public function use(callable ...$middleware): void
    {
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
    }

    /**
     * Configure worker setup and start server
     */
    public static function spawn(callable $setup, array $options = []): void
    {
        Runtime::enableCoroutine();

        $host    = $options['host'] ?? '0.0.0.0';
        $port    = $options['port'] ?? 8080;
        $workers = $options['workers'] ?? 10;

        $server = new Server($host, $port);

        $server->set([
            'worker_num' => $workers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        $app = new self();

        $pipeline = null;

        $server->on('WorkerStart', function () use ($setup, $app, &$pipeline) {
            $setup($app);
            $pipeline = $app->buildMiddlewarePipeline(
                $app->globalMiddleware,
                function (HttpContext $ctx) {
                    $result = $ctx->data('_result');
                    $router = $ctx->data('_router');

                    switch ($result['code']) {
                        case 200:
                            if ($ctx->method() === 'OPTIONS') {
                                $allowedMethods = $router->methods($ctx->path());
                                $ctx->header('Allow', \implode(',', $allowedMethods));
                            }
                            $result['handler']($ctx);
                            break;

                        case 404:
                            $ctx->text('404 Not Found', HttpStatus::NotFound);
                            break;

                        case 405:
                            $ctx->header('Allow', \implode(',', $result['allowed_methods']));
                            if ($ctx->method() === 'OPTIONS') {
                                $ctx->status(204);
                                break;
                            }
                            $ctx->text('405 Method Not Allowed', HttpStatus::MethodNotAllowed);
                            break;
                    }

                    $ctx->end();
                }
            );
        });

        $server->on('Request', function (Request $req, Response $res) use ($server, &$pipeline, $app) {
            $method = $req->server['request_method'];

            $path = \strtok($req->server['request_uri'], '?');
            $decodedPath = \rawurldecode($path);

            $result = $app->router->lookup($method, $decodedPath);

            $ctx = new HttpContext(
                $server,
                $req,
                $res,
                $result['params'] ?? [],
                $app->trustedProxies
            );

            try {
                $ctx->data('_result', $result);
                $ctx->data('_router', $app->router);
                $pipeline($ctx);
            } catch (\Throwable $e) {
                $res->status(500);
                $res->end("Internal Server Error");
                \fwrite(STDOUT, "Error handling request: " . $e->getMessage() . "\n");
            }
        });

        $server->start();
    }

    /**
     * Build middleware pipeline
     *
     * @param array $middlewares List of middleware callables
     * @param callable $finalHandler Final handler to be called after middleware
     * @return callable The composed middleware pipeline
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
}
