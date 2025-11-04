<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Http\{Server, Request, Response};
use Swoole\Runtime;

use Wilaak\Http\RadixRouter;

use Swoldier\Http\{Router, HttpContext};

use InvalidArgumentException;

enum Event
{
    /**
     * Application started event
     */
    case Start;

    /**
     * Application shutdown event
     */
    case Shutdown;

    /**
     * Worker process started event
     */
    case WorkerStart;

    /**
     * Worker process stopped event
     */
    case WorkerStop;
}

/**
 * Swoldier micro-framework
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/swoldier
 */
class App
{
    /**
     * App lifecycle event listeners
     */
    private array $events = [];

    /**
     * Registered task handlers
     */
    private array $tasks = [];

    /**
     * HTTP router instance
     */
    private Router $router;

    /**
     * @param string $host Server host
     * @param int $port Server port
     * @param int $httpWorkers Number of HTTP worker processes
     * @param int $taskWorkers Number of Task worker processes
     * @param array $trustedProxies List of trusted proxy IPs for client IP resolution
     */
    public function __construct(
        private string $host = '0.0.0.0',
        private int $port = 8080,
        private int $httpWorkers = 4,
        private int $taskWorkers = 2,
        private array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
        $this->router = new Router(new RadixRouter());
    }

    /**
     * Register an event listener
     */
    public function on(Event $event, callable $callback): self
    {
        $this->events[$event->name][] = $callback;

        return $this;
    }

    /**
     * Add route to the application
     */
    public function map(array|string $methods, string $pattern, callable $handler): Router
    {
        if (!is_array($methods)) {
            $methods = [$methods];
        }
        foreach ($methods as $method) {
            $this->router->map($method, $pattern, $handler);
        }
        return $this->router;
    }

    /**
     * Add GET route to the application
     */
    public function get(string $pattern, callable $handler): Router
    {
        return $this->router->map('GET', $pattern, $handler);
    }

    /**
     * Add POST route to the application
     */
    public function post(string $pattern, callable $handler): Router
    {
        return $this->router->map('POST', $pattern, $handler);
    }

    /**
     * Add PUT route to the application
     */
    public function put(string $pattern, callable $handler): Router
    {
        return $this->router->map('PUT', $pattern, $handler);
    }

    /**
     * Add PATCH route to the application
     */
    public function patch(string $pattern, callable $handler): Router
    {
        return $this->router->map('PATCH', $pattern, $handler);
    }

    /**
     * Add DELETE route to the application
     */
    public function delete(string $pattern, callable $handler): Router
    {
        return $this->router->map('DELETE', $pattern, $handler);
    }

    public function head(string $pattern, callable $handler): Router
    {
        return $this->router->map('HEAD', $pattern, $handler);
    }

    /**
     * Add OPTIONS route to the application
     */
    public function options(string $pattern, callable $handler): Router
    {
        return $this->router->map('OPTIONS', $pattern, $handler);
    }

    /**
     * Add global middleware to the application
     */
    public function use(callable ...$middleware): Router
    {
        return $this->router->use(...$middleware);
    }

    /**
     * Create a route group with shared middleware.
     */
    public function group(callable ...$middleware): Router
    {
        return $this->router->group(...$middleware);
    }

    /**
     * Register a task handler
     */
    public function task(string $name, callable $handler): void
    {
        if (isset($this->tasks[$name])) {
            throw new InvalidArgumentException("Task handler for '$name' is already registered.");
        }
        $this->tasks[$name] = $handler;
    }

    /**
     * Run the application
     *
     * @param callable $callback Callback to execute when the server starts
     */
    public function run(?callable $callback = null): void
    {
        Runtime::enableCoroutine();

        $server = new Server($this->host, $this->port);

        $server->set([
            'worker_num' => $this->httpWorkers,
            'task_worker_num' => $this->taskWorkers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        $server->on('Start', function (Server $server) use ($callback) {
            if ($callback) {
                $callback($this->host, $this->port);
            }
            foreach ($this->events[Event::Start->name] ?? [] as $callback) {
                $callback();
            }
        });

        $server->on('WorkerStart', function (Server $server, int $workerId) {
            foreach ($this->events[Event::WorkerStart->name] ?? [] as $callback) {
                $callback($workerId);
            }
        });

        $server->on('WorkerStop', function (Server $server, int $workerId) {
            foreach ($this->events[Event::WorkerStop->name] ?? [] as $callback) {
                $callback($workerId);
            }
        });

        $server->on('Request', function (Request $request, Response $response) use (&$server) {
            $this->router->dispatch(
                new HttpContext($server, $request, $response, $this->trustedProxies)
            );
        });

        $server->on('Task', function (Server $srv, int $taskId, int $workerId, mixed $data) {
            $name = $data['name'] ?? null;

            if (!$name) {
                throw new InvalidArgumentException("Task name is missing in task data.");
            }

            $payload = $data['payload'] ?? [];

            if (!isset($this->tasks[$name])) {
                throw new InvalidArgumentException("No task handler registered for task '$name'.");
            }

            $handler = $this->tasks[$name];
            $result = $handler(...$payload);
            $srv->finish($result);
        });

        $server->on('Finish', function (Server $server, int $taskId, string $data) {
            return;
        });

        $server->start();
    }
}
