<?php

declare(strict_types=1);

namespace Swoldier\Http;

use Swoole\{Server, Http\Request, Http\Response};

use RuntimeException;

/**
 * HTTP request and response context
 */
class HttpContext
{
    public function __construct(
        private Server $srv,
        private Request $req,
        private Response $res,
        private array $trustedProxies = ['127.0.0.1', '::1'],
    ) {
    }

    /**
     * @var array|null Route parameters extracted from the route pattern defined in the router
     */
    private ?array $routeParams = null;

    /**
     * @var int HTTP response status code
     */
    private int $statusCode = 200;

    /**
     * @var bool Whether response has been committed
     */
    private bool $isCommitted = false;

    /**
     * @var array key-value attribute store
     */
    private array $attributes = [];

    /**
     * Get custom attributes for this context
     */
    public function getAttribute(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->attributes;
        }
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set custom attribute for this context
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return $this->req->server['request_method'] ?? '';
    }

    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->req->server['request_uri'];
    }

    /**
     * Get request path (URI without query string)
     */
    public function getPath(): string
    {
        return \strtok($this->getUri(), '?');
    }

    /**
     * Get request body
     */
    public function getBody(): string
    {
        return $this->req->rawContent() ?: '';
    }

    /**
     * Get client IP address
     */
    public function getIp(): string
    {
        $ip = $this->req->server['remote_addr'];

        if (!\in_array($ip, $this->trustedProxies, true)) {
            return $ip;
        }

        $forwarded = $this->getHeader('x-forwarded-for');
        if (\is_string($forwarded) && $forwarded !== '') {
            $forwarded = \array_map(\trim(...), \explode(',', $forwarded));
            foreach ($forwarded as $candidate) {
                if (\filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return $ip;
    }

    /**
     * Get request host header (e.g., example.com)
     */
    public function getHost(): ?string
    {
        return $this->req->header['host'] ?? null;
    }

    /**
     * Get request scheme (e.g., http or https)
     */
    public function getScheme(): string
    {
        $ip = $this->req->server['remote_addr'];

        if (\in_array($ip, $this->trustedProxies, true)) {
            $proto = $this->getHeader('x-forwarded-proto') ?? $this->getHeader('forwarded');
            if ($proto && \str_contains($proto, 'https')) {
                return 'https';
            }
        }

        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get request protocol (e.g., HTTP/1.1 or HTTP/2)
     */
    public function getProtocol(): string
    {
        return $this->req->server['server_protocol'] ?? 'HTTP/1.1';
    }

    /**
     * Get request parameters extracted from the route pattern defined in the router
     */
    public function getRouteParam(?string $key = null, ?string $default = null): array|string|null
    {
        if ($key === null) {
            return $this->routeParams ?? [];
        }
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Set route parameters
     */
    public function setRouteParams(array $params): self
    {
        if ($this->routeParams !== null) {
            throw new RuntimeException('Route parameters can only be set once.');
        }
        $this->routeParams = $params;
        return $this;
    }

    /**
     * Get request URL query parameters
     */
    public function getQueryParam(?string $key = null, ?string $default = null): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? $default;
    }

    /**
     * Get request query string
     */
    public function getQueryString(): ?string
    {
        return $this->req->server['query_string'] ?? null;
    }

    /**
     * Get request POST parameters
     */
    public function getPostParam(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->post ?? [];
        }
        return $this->req->post[$key] ?? null;
    }

    /**
     * Get request headers
     */
    public function getHeader(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->header ?? [];
        }
        return $this->req->header[$key] ?? null;
    }

    /**
     * Set response header
     */
    public function setHeader(string $key, string $value): self
    {
        $this->assertNotCommitted();
        $this->res->header($key, $value);
        return $this;
    }

    /**
     * Get response headers
     */
    public function getResHeader(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->res->header ?? [];
        }
        return $this->res->header[$key] ?? null;
    }

    /**
     * Get request uploaded files
     */
    public function getUploadedFile(?string $key = null): ?array
    {
        if ($key === null) {
            return $this->req->files ?? [];
        }
        return $this->req->files[$key] ?? null;
    }

    /**
     * Write data to the response buffer
     */
    public function write(string $data): self
    {
        $this->isCommitted = true;
        $this->res->write($data);
        return $this;
    }

    /**
     * End response
     */
    public function end(?string $data = null): self
    {
        $this->isCommitted = true;
        $this->res->end($data);
        return $this;
    }

    /**
     * Close connection
     */
    public function abortConnection(): self
    {
        $this->srv->close($this->req->fd);
        return $this;
    }

    /**
     * Send JSON response
     */
    public function sendJson(mixed $data, ?int $status = null): self
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        return $this->end(\json_encode($data));
    }

    /**
     * Send HTML response
     */
    public function sendHtml(string $html, ?int $status = null): self
    {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        return $this->end($html);
    }

    /**
     * Send plain text response
     */
    public function sendText(string $text, ?int $status = null): self
    {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        return $this->end($text);
    }

    /**
     * Send redirect response
     */
    public function sendRedirect(string $url, int $status = 302): self
    {
        $this->setHeader('Location', $url);
        $this->setStatus($status);
        return $this->end();
    }

    /**
     * Send file response
     */
    public function sendFile(string $baseDir, string $filePath): self
    {
        $this->assertNotCommitted();
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $this->res->sendfile($fullPath);
            return $this;
        }
        return $this->sendText('File not found', 404);
    }

    /**
     * Send download response
     */
    public function sendDownload(string $baseDir, string $filePath, ?string $downloadName = null): self
    {
        $this->assertNotCommitted();
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $fileName = \rawurlencode($downloadName ?? \basename($filePath));
            $this->setHeader('Content-Type', 'application/octet-stream');
            $this->setHeader('Content-Transfer-Encoding', 'binary');
            $this->setHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"");
            $this->res->sendfile($fullPath);
            return $this;
        }
        return $this->sendText('File not found', 404);
    }

    /**
     * Set response status code
     */
    public function setStatus(int $code): self
    {
        $this->assertNotCommitted();
        $this->res->status($code);
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get response status code
     */
    public function getStatus(): int
    {
        return $this->statusCode;
    }

    /**
     * Check if the client is still connected
     */
    public function isConnected(): bool
    {
        // TODO: ensure this works with HTTP/2 reused connections
        return $this->srv->exist($this->req->fd);
    }

    /**
     * Check if response has been committed
     */
    public function isCommitted(): bool
    {
        return $this->isCommitted;
    }

    /**
     * Run a background task
     */
    public function dispatchTask(string $name, mixed $payload): void
    {
        $this->srv->task(['name' => $name, 'payload' => $payload]);
    }

    /**
     * Run a background task and await its result
     */
    public function awaitTask(string $name, mixed $payload, float $timeout = 4): mixed
    {
        return $this->srv->taskCo([['name' => $name, 'payload' => $payload]], $timeout)[0] ?? null;
    }

    /**
     * Assert that headers have not been sent yet
     *
     * @throws RuntimeException if headers have already been sent
     */
    private function assertNotCommitted(): void
    {
        if ($this->isCommitted) {
            throw new RuntimeException('Cannot modify response after it has been sent');
        }
    }

    /**
     * Resolve file path within base directory
     *
     * This ensures that the resolved file is within the specified base directory
     * to prevent directory traversal attacks.
     */
    private function getResolvedFilePath(string $baseDir, string $filePath): ?string
    {
        $resolvedBaseDir = \realpath($baseDir);
        if ($resolvedBaseDir === false) {
            return null;
        }
        $baseDir = \rtrim($resolvedBaseDir, '/') . '/';
        $fullPath = \realpath("{$baseDir}{$filePath}");
        if ($fullPath && \str_starts_with($fullPath, $baseDir) && \is_file($fullPath)) {
            return $fullPath;
        }
        return null;
    }
}
