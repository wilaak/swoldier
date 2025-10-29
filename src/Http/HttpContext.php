<?php

declare(strict_types=1);

namespace Swoldier\Http;

use Swoole\{Server, Http\Request, Http\Response};

use Swoldier\Http\Enum\{HttpStatus, HttpRedirectStatus};

use RuntimeException;

/**
 * HTTP request and response context
 */
class HttpContext
{
    /**
     * @param array $routeParams Route parameters extracted from the URI
     * @param array $trustedProxies List of trusted proxy IP addresses when determining client IP address
     */
    public function __construct(
        private Server $srv,
        private Request $req,
        private Response $res,
        private array $routeParams = [],
        private array $trustedProxies = ['127.0.0.1', '::1']
    ) {
    }

    /**
     * @var HttpStatus $statusCode Response status code
     */
    private HttpStatus $statusCode = HttpStatus::OK;

    /**
     * @var bool Whether response has been committed
     */
    private bool $isCommitted = false;

    /**
     * @var array HttpContext key-value data store
     */
    private array $contextData = [];

    /**
     * Get data from context store
     */
    public function getData(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->contextData;
        }
        return $this->contextData[$key] ?? null;
    }

    /**
     * Set data in context store
     */
    public function setData(string $key, mixed $value): self
    {
        $this->contextData[$key] = $value;
        return $this;
    }

    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return $this->req->server['request_method'];
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
    public function getClientIp(): string
    {
        $ip = $this->req->server['remote_addr'];

        if (!\in_array($ip, $this->trustedProxies, true)) {
            return $ip;
        }

        $forwardedFor = $this->getHeader('x-forwarded-for');
        if (\is_string($forwardedFor) && $forwardedFor !== '') {
            $forwarded = \array_map(\trim(...), \explode(',', $forwardedFor));
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
     * Get request scheme (http or https)
     */
    public function getScheme(): string
    {
        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get request protocol (e.g., HTTP/1.1)
     */
    public function getProtocol(): string
    {
        return $this->req->server['server_protocol'] ?? 'HTTP/1.1';
    }

    /**
     * Get request route parameters
     */
    public function getParam(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->routeParams ?? [];
        }
        return $this->routeParams[$key] ?? null;
    }

    /**
     * Get request query parameters
     */
    public function getQueryParam(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? null;
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
    public function getResponseHeader(?string $key = null): array|string|null
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
     * Write data to the response
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
    public function abort(): self
    {
        $this->srv->close($this->req->fd);
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json(mixed $data, ?HttpStatus $status = null): self
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
    public function html(string $html, ?HttpStatus $status = null): self
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
    public function text(string $text, ?HttpStatus $status = null): self
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
    public function redirect(string $url, HttpRedirectStatus $status = HttpRedirectStatus::Found): self
    {
        $this->setHeader('Location', $url);
        $this->setStatus(HttpStatus::tryFrom($status->value));
        return $this->end();
    }

    /**
     * Send file response
     */
    public function file(string $baseDir, string $filePath): self
    {
        $this->assertNotCommitted();
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $this->res->sendfile($fullPath);
            return $this;
        }
        return $this->text('File not found', HttpStatus::NotFound);
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
        return $this->text('File not found', HttpStatus::NotFound);
    }

    /**
     * Set response status code
     */
    public function setStatus(HttpStatus $code): self
    {
        $this->assertNotCommitted();
        $this->res->status($code->value);
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Get response status code
     */
    public function getStatus(): HttpStatus
    {
        return $this->statusCode;
    }

    /**
     * Check if the client is still connected
     */
    public function isConnected(): bool
    {
        // TODO: ensure this works with reused connections
        // look into using swoole onClose event
        // also consider what to do when server wants to shut down
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
