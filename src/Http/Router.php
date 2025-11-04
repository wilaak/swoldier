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
    private ?Closure $globalMiddlewarePipeline = null;

    /**
     * Indicates if this Router instance is a route group
     */
    private bool $isGroup = false;

    public function __construct(
        public RadixRouter $router,
    ) {
    }

    /**
     * Add route to the application
     */
    public function map(string $method, string $pattern, callable $handler): self
    {
        $handler = self::composeMiddleware($this->groupMiddleware, $handler);
        $this->router->add($method, $pattern, $handler);
        return $this;
    }

    /**
     * Create a route group with shared middleware.
     *
     * @param callable(HttpContext, callable): HttpContext ...$middleware Middleware functions to add to the group
     * @return self A new Router instance representing the route group
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
     *
     * @param callable(HttpContext, callable): HttpContext ...$middleware Middleware functions to add globally
     * @throws LogicException If called on a route group instance
     * @return self The current Router instance
     */
    public function use(callable ...$middleware): self
    {
        if ($this->isGroup) {
            throw new LogicException("Cannot add global middleware to a route group");
        }
        $this->globalMiddleware = [...$this->globalMiddleware, ...$middleware];
        $this->globalMiddlewarePipeline = self::composeMiddleware($this->globalMiddleware, fn (HttpContext $ctx) => $ctx);
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

        if ($this->globalMiddlewarePipeline !== null) {
            ($this->globalMiddlewarePipeline)($ctx);
        }

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
    }

    /**
     * Composes a stack of middleware into a single handler.
     *
     * @param array<callable(HttpContext, callable): HttpContext> $middlewares Middleware functions to compose
     * @param callable(HttpContext): HttpContext $finalHandler The final handler to be called after all middleware
     * @return Closure(HttpContext): HttpContext The composed middleware pipeline
     */
    public static function composeMiddleware(array $middlewares, callable $finalHandler): Closure
    {
        foreach (\array_reverse($middlewares) as $middleware) {
            $finalHandler = fn (HttpContext $ctx) => $middleware($ctx, $finalHandler);
        }
        return $finalHandler;
    }
}
