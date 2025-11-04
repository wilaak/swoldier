<?php

declare(strict_types=1);

namespace Swoldier;

use Swoldier\Enum\Event;

use Swoole\Http\{Server, Request, Response};
use Swoole\Runtime;

use Wilaak\Http\RadixRouter;

use Swoldier\Http\Router;

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

    private Router $router;

    /**
     * @param string $host Server host
     * @param int $port Server port
     * @param int $httpWorkers Number of HTTP worker processes
     * @param int $taskWorkers Number of Task worker processes
     * @param array $trustedProxies List of trusted proxy IPs for client IP resolution
     */
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public int $httpWorkers = 4,
        public int $taskWorkers = 2,
        public array $trustedProxies = ['127.0.0.1', '::1'],
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
    public function map(string $method, string $pattern, callable $handler): Router
    {
        return $this->router->map($method, $pattern, $handler);
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
     * Run the application
     * 
     * @param callable $callback Callback to execute when the server starts
     */
    public function run(?callable $callback = null): void
    {
        if ($callback) {
            $this->on(Event::Start, $callback);
        }

        Runtime::enableCoroutine();

        $server = new Server($this->host, $this->port);

        $server->set([
            'worker_num' => $this->httpWorkers,
            'task_worker_num' => $this->taskWorkers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        $server->on('Start', function (Server $server) {
            foreach ($this->events[Event::Start->name] ?? [] as $callback) {
                $callback();
            }
        });

        $server->on('WorkerStart', function (Server $server, int $workerId) {
            foreach ($this->events[Event::WorkerStart->name] ?? [] as $callback) {
                $callback();
            }
        });

        $server->on('WorkerStop', function (Server $server, int $workerId) {
            foreach ($this->events[Event::WorkerStop->name] ?? [] as $callback) {
                $callback();
            }
        });

        $server->on('Request', function (Request $request, Response $response) use (&$server) {
            $this->router->dispatch(
                new HttpContext($server, $request, $response)
            );
        });

        $server->on('Task', function (Server $srv, int $taskId, int $workerId, mixed $data) {
        });

        $server->start();
    }
}
