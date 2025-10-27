<?php

declare(strict_types=1);

namespace Swoldier\Http;

/**
 * HTTP request and response context
 */
class Context
{
    public function __construct(
        private \Swoole\Http\Server $srv,
        private \Swoole\Http\Request $req,
        private \Swoole\Http\Response $res,

        /**
         * Route parameters extracted from the URI
         */
        private array $params = [],

        /**
         * List of trusted proxy IP addresses when determining client IP address
         */
        private array $trustedProxies = ['127.0.0.1', '::1']
    ) {
    }

    /**
     * Response status code
     */
    private int $statusCode = 200;

    /**
     * Custom key-value storage for the request context
     */
    private array $attributes = [];

    /**
     * Set a custom attribute key-value pair
     *
     * This can be used by middleware and handlers to store and share data
     * throughout the lifecycle of the request.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a custom attribute value by key or all attributes if key is null
     *
     * Returns null if the attribute does not exist.
     */
    public function getAttribute(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->attributes;
        }
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get request HTTP method
     */
    public function getMethod(): string
    {
        return $this->req->server['request_method'];
    }

    /**
     * Get full request URI
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
     * Get full request body as string
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

        if (!\in_array($ip, $this->trustedProxies)) {
            return $ip;
        }

        if ($this->getHeaders('x-forwarded-for')) {
            $forwarded = \array_map('trim', \explode(',', $this->getHeaders('x-forwarded-for')));
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
     * Get route parameter by key or all route parameters if key is null
     */
    public function getRouteParams(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->params ?? [];
        }
        return $this->params[$key] ?? null;
    }

    /**
     * Get request query parameter by key or all query parameters if key is null
     */
    public function getQueryParams(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? null;
    }

    /**
     * Get raw query string
     */
    public function getQueryString(): ?string
    {
        return $this->req->server['query_string'] ?? null;
    }

    /**
     * Get request POST parameter by key or all POST parameters if key is null
     */
    public function getPostParams(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->post ?? [];
        }
        return $this->req->post[$key] ?? null;
    }

    /**
     * Get request header by key or all headers if key is null
     */
    public function getHeaders(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->header ?? [];
        }
        return $this->req->header[$key] ?? null;
    }

    /**
     * Set response header
     */
    public function setHeader(string $key, string $value): void
    {
        $this->res->header($key, $value);
    }

    /**
     * Get request cookie by key or all cookies if key is null
     */
    public function getCookies(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->req->cookie ?? [];
        }
        return $this->req->cookie[$key] ?? null;
    }

    /**
     * Set response cookie
     */
    public function setCookie(string $key, string $value, int $expire = 0, string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = true): void
    {
        $this->res->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly);
    }

    /**
     * Delete response cookie
     */
    public function deleteCookie(string $key, string $path = '/', ?string $domain = null): void
    {
        $this->res->cookie($key, '', \time() - 3600, $path, $domain);
    }

    /**
     * Get uploaded file by key or all uploaded files if key is null
     */
    public function getUploadedFiles(?string $key = null): ?array
    {
        if ($key === null) {
            return $this->req->files ?? [];
        }
        return $this->req->files[$key] ?? null;
    }

    /**
     * Write data to the response
     */
    public function write(string $data): void
    {
        $this->res->write($data);
    }

    /**
     * End response
     */
    public function close(): void
    {
        $this->res->end();
    }

    /**
     * Close the connection without sending a response
     */
    public function abort(): void
    {
        $this->srv->close($this->req->fd);
    }

    /**
     * Send JSON response
     */
    public function sendJson(mixed $data, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        $this->res->end(\json_encode($data));
    }

    /**
     * Send HTML response
     */
    public function sendHtml(string $html, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        $this->res->end($html);
    }

    /**
     * Send plain text response
     */
    public function sendText(string $text, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        if ($status !== null) {
            $this->setStatus($status);
        }
        $this->res->end($text);
    }

    /**
     * Send redirect response
     */
    public function sendRedirect(string $url, int $status = 302): void
    {
        $this->setHeader('Location', $url);
        $this->setStatus($status);
        $this->res->end();
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

    /**
     * Send file as response
     */
    public function sendFile(string $baseDir, string $filePath): void
    {
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $this->res->sendfile($fullPath);
            return;
        }
        $this->sendText('File not found', 404);
    }

    /**
     * Send file as download response
     */
    public function sendDownload(string $baseDir, string $filePath, ?string $downloadName = null): void
    {
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $fileName = \rawurlencode($downloadName ?? \basename($filePath));
            $this->setHeader('Content-Type', 'application/octet-stream');
            $this->setHeader('Content-Transfer-Encoding', 'binary');
            $this->setHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"");
            $this->res->sendfile($fullPath);
            return;
        }
        $this->sendText('File not found', 404);
    }

    /**
     * Set response status code
     */
    public function setStatus(int $code): void
    {
        $this->res->status($code);
        $this->statusCode = $code;
    }

    /**
     * Get response status code
     */
    public function getStatus(): int
    {
        return $this->statusCode;
    }

    /**
     * Check if the connection is still alive
     */
    public function isConnected(): bool
    {
        // TODO: ensure this works with reused connections
        // look into using swoole onClose event
        // also consider what to do when server wants to shut down
        return $this->srv->exist($this->req->fd);
    }
}
