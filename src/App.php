<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Http\{Server, Request, Response};

use Swoole\Runtime;

use Closure;

/**
 * Swoldier micro-framework
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/swoldier
 */
class App
{
    private Closure $httpWorkerSetup;

    private Closure $taskWorkerSetup;

    /**
     * @param string $host Server host
     * @param int $port Server port
     * @param int $workers Number of worker processes
     * @param array $trustedProxies List of trusted proxy IPs for client IP resolution
     */
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public int $httpWorkers = 4,
        public int $taskWorkers = 0,
        public array $trustedProxies = ['127.0.0.1', '::1'],
    ) {}

    /**
     * Set up the HTTP worker configuration
     * 
     * @param callable $setup Function that receives the HttpWorker instance
     */
    public function httpWorker(callable $setup): self
    {
        if (!isset($this->httpWorkerSetup)) {
            $this->httpWorkerSetup = $setup;
            return $this;
        }
        throw new \RuntimeException("HTTP Worker already configured");
    }

    /**
     * Set up the Task worker configuration
     * 
     * @param callable $setup Function that receives the TaskWorker instance
     */
    public function taskWorker(callable $setup): self
    {
        if (!isset($this->taskWorkerSetup)) {
            $this->taskWorkerSetup = $setup;
            return $this;
        }
        throw new \RuntimeException("Task Worker already configured");
    }

    /**
     * Run the application
     */
    public function run(): void
    {
        Runtime::enableCoroutine();

        $server = new Server($this->host, $this->port);

        $server->set([
            'worker_num' => $this->httpWorkers,
            'task_worker_num' => $this->taskWorkers,
            'enable_reuse_port' => true,
            'open_cpu_affinity' => true,
        ]);

        /** @var HttpWorker $worker */
        $worker = new HttpWorker(0);

        $server->on('Start', function (Server $srv) use (&$worker) {
            ($this->httpWorkerSetup)($worker);
        });

        $server->on('WorkerStart', function (Server $srv, int $workerId) use (&$worker) {
            $worker = $worker;
        });

        $server->on('Request', function (Request $req, Response $res) use (&$worker, &$server) {
            $worker->handleRequest($server, $req, $res);
        });

        $server->start();
    }
}
