<?php

declare(strict_types=1);

namespace Swoldier\Http;

use Swoole\{Server, Http\Request, Http\Response};

use RuntimeException;

use Swoldier\Http\Enum\{HttpStatus, HttpRedirectStatus};

/**
 * Marker to differentiate between null values and absent values
 */
enum None
{
    case Value;
}

/**
 * HTTP request and response context
 */
class HttpContext
{
    /**
     * @param array $params Route parameters extracted from the URI
     * @param array $trustedProxies List of trusted proxy IP addresses when determining client IP address
     */
    public function __construct(
        private Server $srv,
        private Request $req,
        private Response $res,
        private array $params = [],
        private array $trustedProxies = ['127.0.0.1', '::1']
    ) {
    }

    /**
     * @var int Response status code
     */
    private int $statusCode = 200;

    /**
     * @var bool Whether response headers have been sent
     */
    private bool $sentHeaders = false;

    /**
     * @var array HttpContext key-value data store
     */
    private array $contextData = [];

    /**
     * Get or set custom key-value data for the context
     *
     * @param string|null $key The key to get or set. If null, returns the entire data array.
     * @param mixed $value The value to set for the given key. If not provided, the method acts as a getter.
     */
    public function data(?string $key = null, mixed $value = None::Value): mixed
    {
        if ($key === null) {
            return $this->contextData;
        }
        if ($value !== None::Value) {
            $this->contextData[$key] = $value;
            return $this;
        }
        return $this->contextData[$key] ?? null;
    }

    /**
     * Get request method
     */
    public function method(): string
    {
        return $this->req->server['request_method'];
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

        if (!\in_array($ip, $this->trustedProxies)) {
            return $ip;
        }

        if ($this->header('x-forwarded-for')) {
            $forwarded = \array_map('trim', \explode(',', $this->header('x-forwarded-for')));
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
     * Get request scheme (http or https)
     */
    public function scheme(): string
    {
        return ($this->req->server['https'] ?? 'off') === 'on' ? 'https' : 'http';
    }

    /**
     * Get request protocol (e.g., HTTP/1.1)
     */
    public function protocol(): string
    {
        return $this->req->server['server_protocol'] ?? 'HTTP/1.1';
    }

    /**
     * Get request route parameters
     */
    public function param(?string $key = null): array|string|null
    {
        if ($key === null) {
            return $this->params ?? [];
        }
        return $this->params[$key] ?? null;
    }

    /**
     * Get request query parameters
     */
    public function query(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->get ?? [];
        }
        return $this->req->get[$key] ?? null;
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
    public function post(?string $key): array|string|null
    {
        if ($key === null) {
            return $this->req->post ?? [];
        }
        return $this->req->post[$key] ?? null;
    }

    /**
     * Get or set response header
     */
    public function header(?string $key = null, None|string $value = None::Value): array|string|null|self
    {
        if ($key === null) {
            return $this->req->header ?? [];
        }
        if ($value !== None::Value) {
            $this->assertHeadersNotSent();
            $this->res->header($key, $value);
            return $this;
        }
        return $this->res->header[$key] ?? null;
    }

    /**
     * Get uploaded files
     */
    public function files(?string $key = null): ?array
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
        $this->sentHeaders = true;
        $this->res->write($data);
        return $this;
    }

    /**
     * End response
     */
    public function end(?string $data = null): void
    {
        $this->sentHeaders = true;
        $this->res->end($data);
    }

    /**
     * Close connection
     */
    public function abort(): void
    {
        $this->srv->close($this->req->fd);
    }

    /**
     * Send JSON response
     */
    public function json(mixed $data, ?HttpStatus $status = null): void
    {
        $this->header('Content-Type', 'application/json; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->end(\json_encode($data));
    }

    /**
     * Send HTML response
     */
    public function html(string $html, ?HttpStatus $status = null): void
    {
        $this->header('Content-Type', 'text/html; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->end($html);
    }

    /**
     * Send plain text response
     */
    public function text(string $text, ?HttpStatus $status = null): void
    {
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        if ($status !== null) {
            $this->status($status);
        }
        $this->end($text);
    }

    /**
     * Send redirect response
     */
    public function redirect(string $url, HttpRedirectStatus $status = HttpRedirectStatus::Found): void
    {
        $this->header('Location', $url);
        $this->status($status->value);
        $this->end();
    }

    /**
     * Send file response
     */
    public function file(string $baseDir, string $filePath): void
    {
        $this->assertHeadersNotSent();
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $this->res->sendfile($fullPath);
            return;
        }
        $this->text('File not found', 404);
    }

    /**
     * Send download response
     */
    public function download(string $baseDir, string $filePath, ?string $downloadName = null): void
    {
        $this->assertHeadersNotSent();
        $fullPath = $this->getResolvedFilePath($baseDir, $filePath);
        if ($fullPath) {
            $fileName = \rawurlencode($downloadName ?? \basename($filePath));
            $this->header('Content-Type', 'application/octet-stream');
            $this->header('Content-Transfer-Encoding', 'binary');
            $this->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
            $this->res->sendfile($fullPath);
            return;
        }
        $this->text('File not found', 404);
    }

    /**
     * Get or set response status code
     */
    public function status(?HttpStatus $code = null): self|int
    {
        if ($code === null) {
            return $this->statusCode;
        }
        $this->assertHeadersNotSent();
        $statusCode = $code instanceof HttpStatus ? $code->value : $code;
        $this->res->status($statusCode);
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Check if the client is still connected
     */
    public function connected(): bool
    {
        // TODO: ensure this works with reused connections
        // look into using swoole onClose event
        // also consider what to do when server wants to shut down
        return $this->srv->exist($this->req->fd);
    }

    /**
     * Assert that headers have not been sent yet
     *
     * @throws RuntimeException if headers have already been sent
     */
    private function assertHeadersNotSent(): void
    {
        if ($this->sentHeaders) {
            throw new RuntimeException('Headers already sent.');
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
