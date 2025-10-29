<?php

declare(strict_types=1);

namespace Swoldier;

use Swoldier\Http\{HttpContext, Enum\HttpMethod, Enum\HttpStatus};

use Swoole\Http\{Server, Request, Response};

use Wilaak\Http\RadixRouter;

use Closure;

class HttpWorker
{
    private array $groupMiddlewareStack = [];

    private array $globalMiddlewareStack = [];

    private ?Closure $globalMiddlewarePipeline = null;

    private RadixRouter $router;

    /**
     * @param int $id Worker ID
     */
    public function __construct(
        public int $id,
    ) {
        $this->router = new RadixRouter();
        $this->router->allowedMethods = [];
        foreach (HttpMethod::cases() as $method) {
            $this->router->allowedMethods[] = $method->value;
        }
    }

    /**
     * Add route to the application
     *
     * @param HttpMethod|array $methods HTTP method or list of methods
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param callable $handler Function to handle the request
     */
    public function addRoute(HttpMethod|array $methods, string $pattern, callable $handler): self
    {
        $handler = $this->buildMiddlewarePipeline($this->groupMiddlewareStack, $handler);

        if (!\is_array($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as $method) {
            if (\is_string($method)) {
                $method = HttpMethod::from(\strtoupper($method));
            }
            $this->router->add($method->value, $pattern, $handler);
        }
        return $this;
    }

    /**
     * Create a route group with middleware
     *
     * @param callable ...$middleware Middleware functions to apply to the group
     * @return self New App instance representing the route group
     */
    public function groupMiddleware(callable ...$middleware): self
    {
        $group = clone $this;
        $group->groupMiddlewareStack = [...$this->groupMiddlewareStack, ...$middleware];
        return $group;
    }

    /**
     * Add global middleware
     *
     * @param callable ...$middleware Middleware functions to add
     */
    public function globalMiddleware(callable ...$middleware): self
    {
        $this->globalMiddlewareStack = [...$this->globalMiddlewareStack, ...$middleware];

        $this->globalMiddlewarePipeline = $this->buildMiddlewarePipeline(
            $this->globalMiddlewareStack,
            fn(HttpContext $ctx) => $ctx
        );

        return $this;
    }

    /**
     * Handle incoming HTTP request
     *
     * @param Server $srv Swoole HTTP server instance
     * @param Request $req Swoole HTTP request instance
     * @param Response $res Swoole HTTP response instance
     */
    public function handleRequest(Server $srv, Request $req, Response $res): void
    {
        $method = $req->server['request_method'];
        $path = \strtok($req->server['request_uri'], '?');
        $decodedPath = \rawurldecode($path);

        $result = $this->router->lookup($method, $decodedPath);

        $ctx = new HttpContext(
            $srv,
            $req,
            $res,
            $result['params'] ?? [],
        );

        if ($this->globalMiddlewarePipeline !== null) {
            ($this->globalMiddlewarePipeline)($ctx);
        }

        switch ($result['code']) {
            case HttpStatus::OK->value:
                if ($ctx->getMethod() === 'OPTIONS') {
                    // Add Allow header for OPTIONS requests
                    $allowedMethods = $this->router->methods($ctx->getPath());
                    $ctx->setHeader('Allow', \implode(',', $allowedMethods));
                }
                $result['handler']($ctx);
                break;

            case HttpStatus::NotFound->value:
                $ctx->text('404 Not Found', HttpStatus::NotFound);
                break;

            case HttpStatus::MethodNotAllowed->value:
                $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));
                if ($ctx->getMethod() === 'OPTIONS') {
                    // Upgrade to No Content for OPTIONS requests
                    $ctx->setStatus(HttpStatus::NoContent);
                    break;
                }
                $ctx->text('405 Method Not Allowed', HttpStatus::MethodNotAllowed);
                break;
        }

        $ctx->end();
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
