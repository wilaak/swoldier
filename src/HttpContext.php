<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\{Server, Http\Request, Http\Response};

use Swoldier\Enum\{Method, StatusCode, RedirectStatus};

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
        private array $trustedProxies = ['127.0.0.1', '::1']
    ) {}

    /**
     * @var array<string, string> Route parameters extracted from the route pattern
     */
    private array $routeParams = [];

    /**
     * @var int|StatusCode HTTP response status code
     */
    private int|StatusCode $statusCode = StatusCode::OK;

    /**
     * @var bool Whether response has been committed
     */
    private bool $committed = false;

    /**
     * @var array key-value attribute store
     */
    private array $attributes = [];

    /**
     * Get custom attributes for this context
     * 
     * @param string|null $key Specific attribute key to retrieve, or null to get all
     * @param mixed $default Default value to return if the key does not exist
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->attributes;
        }
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set custom attribute for this context
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     */
    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get request method
     * 
     * @return string HTTP method as Method enum or string for non-standard methods
     */
    public function method(): string
    {
        return $this->req->server['request_method'] ?? '';
    }

    /**
     * Get request URI
     */
    public function uri(): string
    {
        return $this->req->server['request_uri'];
    }

    /**
     * Get request path (URI without query string)
     */
    public function path(): string
    {
        return \strtok($this->uri(), '?');
    }

    /**
     * Get request body
     */
    public function body(): string
    {
        return $this->req->rawContent() ?: '';
    }

    /**
     * Get client IP address
     */
    public function ip(): string
    {
        $ip = $this->req->server['remote_addr'];

        if (!\in_array($ip, $this->trustedProxies, true)) {
            return $ip;
        }

        $forwarded = $this->header('x-forwarded-for');
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
    public function host(): ?string
    {
        return $this->req->header['host'] ?? null;
    }

    /**
     * Get request scheme
     * 
     * @return string 'http' or 'https'
     */
    public function scheme(): string
    {
        $ip = $this->req->server['remote_addr'];

        if (\in_array($ip, $this->trustedProxies, true)) {
            $proto = $this->header('x-forwarded-proto') ?? $this->header('forwarded');
            if ($proto && \str_contains($proto, 'https')) {
                return 'https';
            }
        }

        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get request protocol (e.g., HTTP/1.1 or HTTP/2)
     */
    public function protocol(): string
    {
        return $this->req->server['server_protocol'] ?? 'HTTP/1.1';
    }

    /**
     * Get request parameters extracted from the route pattern defined in the router
     * 
     * @param string|null $key Specific route parameter key to retrieve, or null to get all
     * @param string|null $default Default value to return if the key does not exist
     */
    public function params(?string $key = null, ?string $default = null): array|string|null
    {
        if ($key === null) {
            return $this->routeParams ?? [];
        }
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Set route parameters
     * 
     * @param array $params Key-value array of route parameters
     */
    public function setParams(array $params): self
    {
        $this->routeParams = $params;
        return $this;
    }

    /**
     * Get request URL query parameters
     * 
     * @param string|null $key Specific query parameter key to retrieve, or null to get all
     * @param string|null $default Default value to return if the key does not exist
     */
    public function query(?string $key = null, ?string $default = null): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? $default;
    }

    /**
     * Get request query string
     */
    public function queryString(): ?string
    {
        return $this->req->server['query_string'] ?? null;
    }

    /**
     * Get request POST parameters
     */
    public function post(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->post ?? [];
        }
        return $this->req->post[$key] ?? null;
    }

    /**
     * Get request headers
     */
    public function header(?string $key = null): array|string|null
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
    public function resHeader(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->res->header ?? [];
        }
        return $this->res->header[$key] ?? null;
    }

    /**
     * Get request uploaded files
     */
    public function uploadedFiles(?string $key = null): ?array
    {
        if ($key === null) {
            return $this->req->files ?? [];
        }
        return $this->req->files[$key] ?? null;
    }

    /**
     * Write data to the response
     */
    public function write(string $data): self
    {
        $this->committed = true;
        $this->res->write($data);
        return $this;
    }

    /**
     * End response
     */
    public function end(?string $data = null): self
    {
        $this->committed = true;
        $this->res->end($data);
        return $this;
    }

    /**
     * Close connection
     */
    public function abort(): self
    {
        $this->srv->close($this->req->fd);
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json(mixed $data, ?StatusCode $status = null): self
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        return $this->end(\json_encode($data));
    }

    /**
     * Send HTML response
     */
    public function html(string $html, ?StatusCode $status = null): self
    {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        return $this->end($html);
    }

    /**
     * Send plain text response
     */
    public function text(string $text, ?StatusCode $status = null): self
    {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        return $this->end($text);
    }

    /**
     * Send redirect response
     */
    public function redirect(string $url, RedirectStatus $status = RedirectStatus::Found): self
    {
        $this->setHeader('Location', $url);
        $this->status(StatusCode::tryFrom($status->value));
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
        return $this->text('File not found', StatusCode::NotFound);
    }

    /**
     * Send download response
     */
    public function download(string $baseDir, string $filePath, ?string $downloadName = null): self
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
        return $this->text('File not found', StatusCode::NotFound);
    }

    /**
     * Set response status code
     */
    public function status(StatusCode|int $code): self
    {
        $this->assertNotCommitted();
        $this->res->status($code instanceof StatusCode ? $code->value : $code);
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get response status code
     */
    public function getStatus(): StatusCode|int
    {
        if ($this->statusCode instanceof StatusCode) {
            return $this->statusCode;
        }
        return StatusCode::tryFrom($this->statusCode) ?? $this->statusCode;
    }

    /**
     * Check if the client is still connected
     */
    public function connected(): bool
    {
        // TODO: ensure this works with HTTP/2 reused connections
        return $this->srv->exist($this->req->fd);
    }

    /**
     * Check if response has been committed
     */
    public function committed(): bool
    {
        return $this->committed;
    }

    /**
     * Assert that headers have not been sent yet
     *
     * @throws RuntimeException if headers have already been sent
     */
    private function assertNotCommitted(): void
    {
        if ($this->committed) {
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
