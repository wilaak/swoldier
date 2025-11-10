<?php

declare(strict_types=1);

namespace Swoldier;

use Swoole\Http\{Server, Request, Response};

use RuntimeException, Closure;

class HttpContext
{
    public function __construct(
        private Server $server,
        private Request $request,
        private Response $response,
        private array $trustedProxies = ['127.0.0.1', '::1'],
        private array $routeParams = []
    ) {
        $this->writer = function (string $data): string {
            return $data;
        };
    }

    /**
     * Response status code
     */
    private int $status = 200;

    /**
     * Whether the response has been committed (headers sent)
     */
    private bool $committed = false;

    /**
     * Whether the last write was successful (connection still open)
     */
    public bool $connected = true;

    /**
     * Custom attributes for this context
     */
    private array $attributes = [];

    /**
     * Response body writer function
     */
    private Closure $writer;

    /**
     * Intercept or transform body output before sending
     *
     * @param callable(string): string $writer The writer function
     */
    public function setWriter(callable $writer): self
    {
        $this->writer = Closure::fromCallable($writer);
        return $this;
    }

    /**
     * Get custom attribute for this context
     */
    public function getAttr(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->attributes;
        }
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set custom attribute for this context
     */
    public function setAttr(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return $this->request->server['request_method'] ?? '';
    }

    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->request->server['request_uri'];
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
        return $this->request->rawContent() ?: '';
    }

    /**
     * Get client IP address
     */
    public function getIp(): string
    {
        $ip = $this->request->server['remote_addr'];

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
        return $this->request->header['host'] ?? null;
    }

    /**
     * Get request scheme (e.g., http or https)
     */
    public function getScheme(): string
    {
        $ip = $this->request->server['remote_addr'];

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
        return $this->request->server['server_protocol'] ?? 'HTTP/1.1';
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
     * Get request URL query parameters
     */
    public function getQueryParam(?string $key = null, ?string $default = null): array|string|null
    {
        if ($key === null) {
            return $this->request->get ?? [];
        }
        return $this->request->get[$key] ?? $default;
    }

    /**
     * Get request query string
     */
    public function getQueryString(): ?string
    {
        return $this->request->server['query_string'] ?? null;
    }

    /**
     * Get request POST parameters
     */
    public function getPostParam(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->request->post ?? [];
        }
        return $this->request->post[$key] ?? null;
    }

    /**
     * Get request headers
     */
    public function getHeader(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->request->header ?? [];
        }
        $key = \strtolower($key);
        return $this->request->header[$key] ?? null;
    }

    /**
     * Set response header
     */
    public function setHeader(string $key, string $value): self
    {
        $this->assertNotCommitted();
        $this->response->header($key, $value);
        return $this;
    }

    /**
     * Get response headers
     */
    public function getResponseHeader(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->response->header ?? [];
        }
        $key = \strtolower($key);
        return $this->response->header[$key] ?? null;
    }

    /**
     * Get request uploaded files
     */
    public function getUploadedFile(?string $key = null): ?array
    {
        if ($key === null) {
            return $this->request->files ?? [];
        }
        return $this->request->files[$key] ?? null;
    }

    /**
     * Write data to the response buffer
     *
     * @return bool Whether the write was successful (connection still open)
     */
    public function write(string $data): bool
    {
        $this->committed = true;
        $output = ($this->writer)($data);
        if ($output === '' || $output === null) {
            return $this->connected;
        }
        $this->connected = $this->response->write($output);
        return $this->connected;
    }

    /**
     * End response
     *
     * @param string|null $data Optional data to write before ending
     */
    public function end(?string $data = null): self
    {
        $this->committed = true;
        $this->connected = false;
        if ($data === null) {
            $this->response->end();
            return $this;
        }
        $output = ($this->writer)($data);
        if ($output === '' || $output === null) {
            $this->response->end();
            return $this;
        }
        $this->response->end($output);
        return $this;
    }

    /**
     * Close connection at the TCP level
     */
    public function abort(): self
    {
        $this->server->close($this->request->fd);
        $this->connected = false;
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json(mixed $data, ?int $status = null): self
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
    public function html(string $html, ?int $status = null): self
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
    public function text(string $text, ?int $status = null): self
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
    public function redirect(string $url, int $status = 302): self
    {
        $this->setHeader('Location', $url);
        $this->setStatus($status);
        return $this->end();
    }

    /**
     * Send a file response to the client.
     *
     * @param string $baseDir The base directory for file resolution
     * @param string $filePath The relative file path to serve
     * @param bool $download Whether to force download (Content-Disposition: attachment)
     * @param string|null $downloadName The filename to use for download (optional)
     */
    public function file(string $baseDir, string $filePath, bool $download = false, ?string $downloadName = null): self
    {
        $this->assertNotCommitted();

        $fullPath = $this->resolveSafeFilePath($baseDir, $filePath);
        if (!$fullPath) {
            return $this->text('File not found', 404);
        }

        if ($download) {
            $fileName = \rawurlencode($downloadName ?? \basename($fullPath));
            $this->setHeader('Content-Type', 'application/octet-stream');
            $this->setHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"");
        } else {
            $contentType = getMimeType($fullPath);
            $this->setHeader('Content-Type', $contentType);
        }

        $fileSize = (string)\filesize($fullPath);
        $this->setHeader('Content-Length', $fileSize);

        $resource = \fopen($fullPath, 'rb');
        $chunkSize = 16384;
        while (!\feof($resource)) {
            $data = \fread($resource, $chunkSize);
            if ($data === false || $data === '') {
                break;
            }
            $this->write($data);
        }
        \fclose($resource);
        return $this->end();
    }

    /**
     * Set response status code
     */
    public function setStatus(int $code): self
    {
        $this->assertNotCommitted();
        $this->response->status($code);
        $this->status = $code;
        return $this;
    }

    /**
     * Get response status code
     */
    public function getResponseStatus(): int
    {
        return $this->status;
    }

    /**
     * Check if the client is still connected
     */
    public function isConnected(): bool
    {
        // When writing and an error occurs this will be set to false
        // We do this since the underlying TCP connection can remain open
        // Until the browser actually closes it.
        if (!$this->connected) {
            return false;
        }
        if ($this->getScheme() !== 'https') {
            // For HTTP connections we must check if the connection descriptor still exists
            return $this->server->exist($this->request->fd);
        }
        // For HTTPS connections we can check if the response is still writable
        return $this->response->isWritable();
    }

    /**
     * Check if response has been committed
     */
    public function isCommitted(): bool
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
     * Ensure that the resolved file is within the specified base directory and exists.
     */
    private function resolveSafeFilePath(string $baseDir, string $filePath): ?string
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
