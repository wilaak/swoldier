<?php

declare(strict_types=1);

namespace Swoldier\Http;

class Context
{
    public function __construct(
        public \Swoole\Http\Server $srv,
        public \Swoole\Http\Request $req,
        public \Swoole\Http\Response $res,
        private array $params = [],
        private \Closure $triggerClose
    ) {}

    protected bool $connectionClosed = false; 

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
     * Get HTTP method
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
     * Get remote IP address
     */
    public function remoteAddress(): string
    {
        $ip = $this->req->server['remote_addr'];
        if (isset($this->req->header['x-forwarded-for'])) {
            $ip = explode(',', $this->req->header['x-forwarded-for'])[0];
        }
        return $ip;
    }

    /**
     * Get host
     */
    public function host(): ?string
    {
        return $this->req->header['host'] ?? null;
    }

    /**
     * Get scheme (http or https)
     */
    public function scheme(): string
    {
        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get a route parameter
     */
    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    /**
     * Get all route parameters
     */
    public function params(): array
    {
        return $this->params;
    }

    public function query(string $key): ?string
    {
        return $this->req->get[$key] ?? null;
    }

    public function queries(): array
    {
        return $this->req->get ?? [];
    }

    public function postParam(string $key): ?string
    {
        return $this->req->post[$key] ?? null;
    }

    public function postParams(): array
    {
        return $this->req->post ?? [];
    }

    public function header(string $key): ?string
    {
        return $this->req->header[$key] ?? null;
    }

    public function headers(): array
    {
        return $this->req->header ?? [];
    }

    public function setHeader(string $key, string $value): void
    {
        $this->res->header($key, $value);
    }

    public function cookie(string $key): ?string
    {
        return $this->req->cookie[$key] ?? null;
    }

    public function cookies(): array
    {
        return $this->req->cookie ?? [];
    }

    public function setCookie(string $key, string $value, int $expire = 0, string $path = '/', ?string $domain = null, bool $secure = false, bool $httpOnly = true): void
    {
        $this->res->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly);
    }

    public function deleteCookie(string $key, string $path = '/', ?string $domain = null): void
    {
        $this->res->cookie($key, '', time() - 3600, $path, $domain);
    }

    public function uploadedFile(string $key): ?array
    {
        return $this->req->files[$key] ?? null;
    }

    public function uploadedFiles(): array
    {
        return $this->req->files ?? [];
    }

    public function write(string $data): void
    {
        $this->res->write($data);
    }

    public function close(): void
    {
        $this->res->end();
    }

    public function json(mixed $data, int $status = 200): void
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->status($status);
        $this->res->end(json_encode($data));
    }

    public function html(string $html, int $status = 200): void
    {
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        $this->status($status);
        $this->res->end($html);
    }

    public function text(string $text, int $status = 200): void
    {
        $this->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->status($status);
        $this->res->end($text);
    }

    public function redirect(string $url, int $status = 302): void
    {
        $this->setHeader('Location', $url);
        $this->status($status);
        $this->res->end();
    }

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

    public function status(int $code): void
    {
        $this->res->status($code);
    }

    public function connected(): bool
    {
        // TODO: ensure this works with reused connections
        // look into using swoole onClose event
        // also consider what to do when server wants to shut down
        return $this->srv->exist($this->req->fd);
    }
}
