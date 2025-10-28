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
     * Add a route to the application
     *
     * @param string $pattern Route pattern (e.g. /users/:id)
     * @param callable $handler Function to handle the request
     * @param HttpMethod ...$methods HTTP method or list of methods
     */
    public function addRoute(HttpMethod|array $methods, string $pattern, callable $handler): self
    {
        $handler = $this->buildMiddlewarePipeline($this->middleware, $handler);

        if (!\is_array($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as $method) {
            $this->router->add($method->value, $pattern, $handler);
        }
        return $this;
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
                    $result = $ctx->getData('_result');
                    $router = $ctx->getData('_router');

                    switch ($result['code']) {
                        case 200:
                            if ($ctx->getMethod() === 'OPTIONS') {
                                $allowedMethods = $router->methods($ctx->getPath());
                                $ctx->setHeader('Allow', \implode(',', $allowedMethods));
                            }
                            $result['handler']($ctx);
                            break;

                        case 404:
                            $ctx->text('404 Not Found', HttpStatus::NotFound);
                            break;

                        case 405:
                            $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));
                            if ($ctx->getMethod() === 'OPTIONS') {
                                $ctx->setStatus(HttpStatus::NoContent);
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
                $ctx->setData('_result', $result);
                $ctx->setData('_router', $app->router);
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
