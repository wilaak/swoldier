<?php

declare(strict_types=1);

namespace Swoldier\Http;

class Context
{
    public function __construct(
        public \Swoole\Http\Server $srv,
        public \Swoole\Http\Request $req,
        public \Swoole\Http\Response $res,

        /**
         * Route parameters extracted from the URI
         */
        private array $params = [],

        /**
         * List of trusted proxy IP addresses
         */
        private array $trustedProxies = ['127.0.0.1', '::1']
    ) {}

    /**
     * Custom attributes storage
     */
    protected array $attributes = [];

    /**
     * Set a custom attribute
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a custom attribute
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get request HTTP method
     */
    public function method(): string
    {
        return $this->req->server['request_method'];
    }

    /**
     * Get full request URI
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
        return strtok($this->uri(), '?');
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

        if (!in_array($ip, $this->trustedProxies)) {
            return $ip;
        }

        if ($this->header('x-forwarded-for')) {
            $forwarded = array_map('trim', explode(',', $this->header('x-forwarded-for')));
            foreach ($forwarded as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return $ip;
    }

    /**
     * Get request host header
     */
    public function host(): ?string
    {
        return $this->req->header['host'] ?? null;
    }

    /**
     * Get request scheme (http or https)
     */
    public function scheme(): string
    {
        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get route parameter by key or all route parameters if key is null
     */
    public function param(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->params ?? [];
        }
        return $this->params[$key] ?? null;
    }

    /**
     * Get request query parameter by key or all query parameters if key is null
     */
    public function query(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? null;
    }

    /**
     * Get request form parameter by key or all form parameters if key is null
     */
    public function form(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->post ?? [];
        }
        return $this->req->post[$key] ?? null;
    }

    /**
     * Get request header
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
    public function setHeader(string $key, string $value): void
    {
        $this->res->header($key, $value);
    }

    /**
     * Get request cookie by key or all cookies if key is null
     */
    public function cookie(?string $key = null): array|string|null
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
        $this->res->cookie($key, '', time() - 3600, $path, $domain);
    }

    /**
     * Get uploaded file by key or all uploaded files if key is null
     */
    public function upload(?string $key = null): ?array
    {
        if ($key === null) {
            return $this->req->files ?? [];
        }
        return $this->req->files[$key] ?? null;
    }

    /**
     * Write data to response
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
     * Send JSON response
     */
    public function json(mixed $data, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->res->end(json_encode($data));
    }

    /**
     * Send HTML response
     */
    public function html(string $html, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->res->end($html);
    }

    /**
     * Send plain text response
     */
    public function text(string $text, ?int $status = null): void
    {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->res->end($text);
    }

    /**
     * Send redirect response
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->setHeader('Location', $url);
        $this->status($status);
        $this->res->end();
    }

    /**
     * Send file as response
     */
    public function file(string $baseDir, string $filePath): void
    {
        $baseDir = rtrim(realpath($baseDir), '/') . '/';
        $fullPath = realpath("{$baseDir}{$filePath}");
        if ($fullPath && str_starts_with($fullPath, $baseDir) && is_file($fullPath)) {
            $this->res->sendfile($fullPath);
            return;
        }
        $this->text('File not found', 404);
    }

    /**
     * Send file as download response
     */
    public function download(string $baseDir, string $filePath, ?string $downloadName = null): void
    {
        $baseDir = rtrim(realpath($baseDir), '/') . '/';
        $fullPath = realpath("{$baseDir}{$filePath}");
        if ($fullPath && str_starts_with($fullPath, $baseDir) && is_file($fullPath)) {
            $fileName = rawurlencode($downloadName ?? basename($filePath));
            $this->setHeader('Content-Type', 'application/octet-stream');
            $this->setHeader('Content-Transfer-Encoding', 'binary');
            $this->setHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"");
            $this->res->sendfile($fullPath);
            return;
        }
        $this->text('File not found', 404);
    }

    /**
     * Set response status code
     */
    public function status(int $code): void
    {
        $this->res->status($code);
    }

    /**
     * Check if the connection is still alive
     */
    public function connected(): bool
    {
        // TODO: ensure this works with reused connections
        // look into using swoole onClose event
        // also consider what to do when server wants to shut down
        return $this->srv->exist($this->req->fd);
    }
}
