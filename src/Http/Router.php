<?php

declare(strict_types=1);

namespace Swoldier\Http;

use Swoldier\Enum\Http\HttpStatus;

use Wilaak\Http\RadixRouter;

use Closure;
use LogicException;

class Router
{
    /**
     * @var array<callable(HttpContext, callable): HttpContext> List of middleware for route groups
     */
    private array $groupMiddleware = [];

    /**
     * @var array<callable(HttpContext, callable): HttpContext> List of global middleware
     */
    private array $globalMiddleware = [];

    /**
     * Current global middleware pipeline
     */
    private Closure $globalMiddlewarePipeline;

    /**
     * Indicates if this Router instance is a route group
     */
    private bool $isGroup = false;

    public function __construct(
        public RadixRouter $router,
    ) {
        $this->use(function (HttpContext $ctx, callable $next) {
            try {
                $next($ctx);
            } catch (\Throwable $e) {
                $ctx->sendText('500 Internal Server Error', HttpStatus::InternalServerError->value);
            }
        });
    }

    /**
     * Add route to the application
     */
    public function map(string $method, string $pattern, callable $handler): self
    {
        $middleware = self::composeMiddleware($this->groupMiddleware);
        $this->router->add($method, $pattern, fn(HttpContext $ctx) => $middleware($ctx, $handler));
        return $this;
    }

    /**
     * Create a route group with shared middleware.
     */
    public function group(callable ...$middleware): self
    {
        $group = clone $this;
        $group->groupMiddleware = [...$this->groupMiddleware, ...$middleware];
        $group->isGroup = true;
        return $group;
    }

    /**
     * Add global middleware to the router.
     */
    public function use(callable ...$middleware): self
    {
        if ($this->isGroup) {
            throw new LogicException("Cannot add global middleware to a route group");
        }
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
        $this->globalMiddlewarePipeline = self::composeMiddleware($this->globalMiddleware);
        return $this;
    }

    /**
     * Handle incoming HTTP request
     *
     * @param HttpContext $ctx The HTTP context containing request and response
     * @return HttpContext The modified HTTP context after handling the request
     */
    public function dispatch(HttpContext $ctx): HttpContext
    {
        if ($this->isGroup) {
            throw new LogicException("Cannot dispatch requests on a route group");
        }

        $method = $ctx->getMethod();
        $decodedPath = \urldecode($ctx->getPath());

        $result = $this->router->lookup($method, $decodedPath);
        $ctx->setRouteParams($result['params'] ?? []);

        ($this->globalMiddlewarePipeline)($ctx, function (HttpContext $ctx) use ($result, $method, $decodedPath) {
            $code = $result['code'];

            if ($code === HttpStatus::OK->value) {

                if ($method === 'OPTIONS') {
                    $allowedMethods = $this->router->methods($decodedPath);
                    $ctx->setHeader('Allow', \implode(',', $allowedMethods));
                }
                $result['handler']($ctx);
                return $ctx->end();
            }

            if ($code === HttpStatus::MethodNotAllowed->value) {

                if ($method === 'HEAD') {

                    $result = $this->router->lookup('GET', $decodedPath);

                    if ($result['code'] === HttpStatus::OK->value) {
                        $ctx->setStatus(HttpStatus::OK->value);
                        return $ctx->end();
                    }
                }

                $ctx->setHeader('Allow', \implode(',', $result['allowed_methods']));

                if ($method === 'OPTIONS') {
                    $ctx->setStatus(HttpStatus::NoContent->value);
                    return $ctx->end();
                }

                return $ctx->sendText('405 Method Not Allowed', HttpStatus::MethodNotAllowed->value);
            }

            return $ctx->sendText('404 Not Found', HttpStatus::NotFound->value);
        });

        return $ctx;
    }

    /**
     * Composes a stack of middleware into a pipeline that accepts a final handler per request.
     *
     * @param array<callable(HttpContext, callable): HttpContext> $middlewares Middleware functions to compose
     * @return Closure(HttpContext, callable): HttpContext The composed middleware pipeline
     */
    public static function composeMiddleware(array $middlewares): Closure
    {
        return function (HttpContext $ctx, callable $finalHandler) use ($middlewares) {
            $handler = $finalHandler;
            foreach (\array_reverse($middlewares) as $middleware) {
                $handler = fn(HttpContext $ctx) => $middleware($ctx, $handler);
            }
            return $handler($ctx);
        };
    }
}
