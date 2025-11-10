<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Http\{Server, Request, Response};

use Wilaak\Http\RadixRouter;

/**
 * Swoldier micro-framework HTTP router
 */
class Router
{
    /**
     * Global middleware stack
     */
    private array $stack = [];

    /**
     * Group middleware stack
     */
    private array $groupStack = [];

    /**
     * Global middleware pipeline
     */
    private \Closure $pipeline;

    /**
     * Underlying HTTP request router implementation
     */
    public RadixRouter $radixRouter;

    /**
     * @param Server $server The Swoole HTTP server instance
     * @param array $trustedProxies List of trusted proxy IP addresses for client IP resolution
     */
    public function __construct(
        public Server $server,
        public array $trustedProxies = ['127.0.0.1', '::1']
    ) {
        $this->radixRouter = new RadixRouter();
        $this->pipeline = fn (HttpContext $ctx, callable $next) => $next($ctx);

        $server->on('Request', function (Request $request, Response $response) use (&$server) {
            $this->handleRequest($request, $response);
        });

        $this->use(function (HttpContext $ctx, callable $next) {
            try {
                $next($ctx);
            } catch (\Throwable $e) {
                $message = $e->getMessage() . "\n" . $e->getTraceAsString();
                if (!$ctx->committed()) {
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
        $middleware = self::buildMiddlewarePipeline($this->groupStack);
        $this->radixRouter->add($methods, $pattern, fn (HttpContext $ctx) => $middleware($ctx, $handler));
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
        $group->groupStack = [...$this->groupStack, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware to the router.
     *
     * @param callable(HttpContext, callable(HttpContext): void): void ...$middleware
     */
    public function use(callable ...$middleware): self
    {
        $this->stack = [...$this->stack, ...$middleware];
        $this->pipeline = self::buildMiddlewarePipeline($this->stack);
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
        $result = $this->radixRouter->lookup($method, $decodedPath);

        // Create the HTTP context
        $ctx = new HttpContext($this->server, $request, $response, $this->trustedProxies, $result['params'] ?? []);

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
        if ($result['code'] === 200) {

            // Add allowed methods header for OPTIONS requests
            if ($method === 'OPTIONS') {
                $allowedMethods = $this->radixRouter->methods($decodedPath);
                $ctx->setHeader('Allow', \implode(',', $allowedMethods));
            }

            $result['handler']($ctx);
            return $ctx->end();
        }

        if ($result['code'] === 405) {

            // Handle HEAD requests for existing GET routes
            if ($method === 'HEAD') {

                $result = $this->radixRouter->lookup('GET', $decodedPath);
                if ($result['code'] === 200) {
                    $ctx->status(204);
                    return $ctx->end();
                }
            }

            $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));

            if ($method === 'OPTIONS') {
                $ctx->status(204);
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
