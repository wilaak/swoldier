<?php

declare(strict_types=1);

namespace Swoldier;

use Psr\Log\LoggerInterface;
use Swoldier\Enum\Http\HttpMethod;
use Swoldier\Http\Context;
use Swoldier\Plugin\BasePlugin;
use Swoole\Coroutine;
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

    /**
     * Registered plugins
     */
    private array $plugins = [];

    /**
     * @param LoggerInterface $logger Logger instance for the application
     * @param int $port Server listening port
     * @param string $host Server listening host
     * @param int $workers Number of worker processes
     * @param array $trustedProxies List of trusted proxy IP addresses when determining client IP address
     */
    public function __construct(
        private LoggerInterface $logger,
        public int $port = 8080,
        public string $host = '127.0.0.1',
        public int $workers = 1,
        public array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
        $this->router = new RadixRouter();
    }

    /**
     * Register a plugin
     */
    public function plugin(BasePlugin ...$plugins): void
    {
        foreach ($plugins as $plugin) {
            $this->plugins[] = $plugin;
        }
    }

    /**
     * Register a route with specific HTTP method(s)
     *
     * @param HttpMethod|string|array $methods HTTP method or list of methods
     * @param string $pattern Route pattern (e.g. /users/:id)
     * @param callable $handler Function to handle the request
     */
    public function match(HttpMethod|string|array $methods, string $pattern, callable $handler)
    {
        $handler = $this->buildMiddlewarePipeline($this->middleware, $handler);

        if (!is_array($methods)) {
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
     * Add a route for GET requests
     */
    public function get(string $pattern, callable $handler)
    {
        $this->match('GET', $pattern, $handler);
    }

    /**
     * Add a route for POST requests
     */
    public function post(string $pattern, callable $handler)
    {
        $this->match('POST', $pattern, $handler);
    }

    /**
     * Add a route for PUT requests
     */
    public function put(string $pattern, callable $handler)
    {
        $this->match('PUT', $pattern, $handler);
    }

    /**
     * Add a route for PATCH requests
     */
    public function patch(string $pattern, callable $handler)
    {
        $this->match('PATCH', $pattern, $handler);
    }

    /**
     * Add a route for DELETE requests
     */
    public function delete(string $pattern, callable $handler)
    {
        $this->match('DELETE', $pattern, $handler);
    }

    /**
     * Add a route for HEAD requests
     */
    public function head(string $pattern, callable $handler)
    {
        $this->match('HEAD', $pattern, $handler);
    }

    /**
     * Add a route for OPTIONS requests
     */
    public function options(string $pattern, callable $handler)
    {
        $this->match('OPTIONS', $pattern, $handler);
    }

    /**
     * Add a route for all HTTP methods
     */
    public function all(string $pattern, callable $handler)
    {
        $this->match($this->router->allowedMethods, $pattern, $handler);
    }

    /**
     * Create a route group with specific middleware
     */
    public function group(callable ...$middleware): self
    {
        $group = clone $this;
        $group->middleware = [...$this->middleware, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware
     */
    public function use(callable ...$middleware): void
    {
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
    }

    /**
     * Start the server and listen for requests
     */
    public function run(?callable $callback = null): void
    {
        Runtime::enableCoroutine();

        $server = new Server($this->host, $this->port);

        $server->set([
            'worker_num' => $this->workers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        $globalMiddlewarePipeline = $this->buildMiddlewarePipeline(
            $this->globalMiddleware,
            fn(Context $ctx) => $this->handleRequest($ctx)
        );

        $server->on('Request', function (Request $req, Response $res) use ($server, $globalMiddlewarePipeline) {

            Coroutine::getContext()['request_id'] = uniqid();

            $method = $req->server['request_method'];

            $path = \strtok($req->server['request_uri'], '?');
            $decodedPath = \rawurldecode($path);

            $result = $this->router->lookup($method, $decodedPath);

            $ctx = new Context(
                $server,
                $req,
                $res,
                $result['params'] ?? [],
                $this->trustedProxies
            );

            $this->logger->info("{method} {path} {ip}", [
                'method' => $method,
                'path' => $decodedPath,
                'ip' => $ctx->getIp(),
            ]);

            try {
                $ctx->setAttribute('_result', $result);
                $ctx->setAttribute('_router', $this->router);
                $globalMiddlewarePipeline($ctx);
            } catch (\Throwable $e) {
                $res->status(500);
                $res->end("Internal Server Error");
                $this->logger->error("Internal Server Error: {error}", [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $server->on('Start', function () use ($callback, $server) {
            foreach ($this->plugins as $plugin) {
                $plugin->register($this);
            }
            $host = $server->host;
            $port = $server->port;
            $this->logger->info("Server started on {$host}:{$port}, Workers {$this->workers}");
            if ($callback) {
                $callback();
            }
        });

        $server->on('WorkerStart', function (Server $server, int $workerId) {
            foreach ($this->plugins as $plugin) {
                $plugin->boot($this);
            }
        });

        $server->start();
    }

    private function handleRequest(Context $ctx): void
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

    private function buildMiddlewarePipeline(array $middlewares, callable $finalHandler): callable
    {
        return \array_reduce(
            \array_reverse($middlewares),
            function ($next, $middleware) {
                return function (Context $ctx) use ($middleware, $next) {
                    return $middleware($ctx, $next);
                };
            },
            $finalHandler
        );
    }
}
