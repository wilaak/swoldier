<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Http\{Server, Request, Response};

use Wilaak\Http\RadixRouter;

use Closure, Throwable;

/**
 * Swoldier micro-framework
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/swoldier
 */
class Router
{
    /**
     * Global middleware stack
     */
    private array $globalMiddleware = [];

    /**
     * Global middleware pipeline
     */
    private Closure $pipeline;

    /**
     * Group middleware stack
     */
    private array $groupMiddleware = [];

    /**
     * Underlying HTTP request router implementation
     */
    public RadixRouter $router;

    /**
     * Create a new Router instance.
     *
     * @param Server $server The Swoole HTTP server instance
     * @param array $trustedProxies List of trusted proxy IP addresses for client IP resolution
     */
    public function __construct(
        public Server $server,
        public array $trustedProxies = ['127.0.0.1', '::1']
    ) {
        $this->router = new RadixRouter();
        $this->pipeline = fn (HttpContext $ctx, callable $next) => $next($ctx);

        $server->on('Request', function (Request $request, Response $response) use (&$server) {
            $this->handleRequest($request, $response);
        });

        $this->use(function (HttpContext $ctx, callable $next) {
            try {
                $next($ctx);
            } catch (Throwable $e) {
                $message = $e->getMessage() . "\n" . $e->getTraceAsString();
                if (!$ctx->isCommitted()) {
                    $ctx->text('500 Internal Server Error', 500);
                }
                \error_log("[Uncaught Exception] " . $message);
            }
        });
    }

    /**
     * Add route to the application
     *
     * @param string|array $methods HTTP method(s) (e.g., 'GET', 'POST', ['GET', 'POST'])
     * @param string $pattern URL pattern (e.g., '/users/:id')
     * @param callable(HttpContext): void $handler The route handler
     */
    public function map(string|array $methods, string $pattern, callable $handler): self
    {
        $middleware = self::buildMiddlewarePipeline($this->groupMiddleware);
        $this->router->add($methods, $pattern, fn (HttpContext $ctx) => $middleware($ctx, $handler));
        return $this;
    }

    /**
     * Create a route group with shared middleware.
     *
     * @param callable(HttpContext, callable(HttpContext): void): void ...$middleware
     */
    public function group(callable ...$middleware): self
    {
        $group = clone $this;
        $group->groupMiddleware = [...$this->groupMiddleware, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware to the router.
     *
     * @param callable(HttpContext, callable(HttpContext): void): void ...$middleware
     */
    public function use(callable ...$middleware): self
    {
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
        $this->pipeline = self::buildMiddlewarePipeline($this->globalMiddleware);
        return $this;
    }

    /**
     * Handle an incoming HTTP request.
     */
    private function handleRequest(Request $request, Response $response): HttpContext
    {
        // Decode the request URI and extract the path
        $method = $request->server['request_method'] ?? 'GET';
        $decodedPath = \urldecode(\strtok($request->server['request_uri'] ?? '', '?'));

        // Lookup the route
        $result = $this->router->lookup($method, $decodedPath);

        // Create the HTTP context
        $ctx = new HttpContext(
            $this->server,
            $request,
            $response,
            $this->trustedProxies,
            $result['params'] ?? []
        );

        // Execute the middleware pipeline
        ($this->pipeline)($ctx, fn (HttpContext $ctx) => $this->dispatchRoute(
            $ctx,
            $result,
            $method,
            $decodedPath
        ));
        return $ctx;
    }

    /**
     * Dispatch the route based on the lookup result.
     */
    private function dispatchRoute(HttpContext $ctx, array $result, string $method, string $decodedPath): HttpContext
    {
        // Successful match
        if ($result['code'] === 200) {

            // Add allowed methods header for OPTIONS requests
            if ($method === 'OPTIONS') {
                $allowedMethods = $this->router->methods($decodedPath);
                $ctx->setHeader('Allow', \implode(',', $allowedMethods));
            }

            $result['handler']($ctx);
            return $ctx->end();
        }

        // Method Not Allowed
        if ($result['code'] === 405) {

            // Handle HEAD requests for existing GET routes
            if ($method === 'HEAD') {
                $headResult = $this->router->lookup('GET', $decodedPath);

                if ($headResult['code'] === 200) {
                    // If a GET route exists at this path, respond with headers only
                    $ctx->setWriter(fn (string $data) => null); // Disable writing body
                    $headResult['handler']($ctx);
                    return $ctx->end();
                }
            }

            $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));

            // Upgrade OPTIONS requests to 204 No Content
            if ($method === 'OPTIONS') {
                $ctx->setStatus(204);
                return $ctx->end();
            }

            return $ctx->text('405 Method Not Allowed', 405);
        }

        // Not found
        return $ctx->text('404 Not Found', 404);
    }

    /**
     * Build a middleware pipeline from an array of middleware callables.
     */
    private static function buildMiddlewarePipeline(array $middlewares): \Closure
    {
        return function (HttpContext $ctx, callable $finalHandler) use ($middlewares) {
            $handler = $finalHandler;
            foreach (\array_reverse($middlewares) as $middleware) {
                $handler = fn (HttpContext $ctx) => $middleware($ctx, $handler);
            }
            return $handler($ctx);
        };
    }
}
