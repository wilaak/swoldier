<?php

declare(strict_types=1);

namespace Swoldier;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Swoole\Timer;

/**
 * Swoldier context aware logger with batching for better performance.
 */
class ContextAwareLogger implements LoggerInterface
{
    /**
     * @var array $batch Batched log entries to be flushed
     */
    private array $batch = [];

    /**
     * @var int|null $flushTimerId Timer ID for scheduled flush, or null if none scheduled
     */
    private ?int $flushTimerId = null;

    /**
     * @param int $flushDelayMs Delay in milliseconds before flushing the log batch
     * @param bool $useColors Whether to use colored output in stdout
     * @param string $stdoutLogLevel Minimum log level for stdout
     * @param string|null $logFilePath Path to log file, or null to disable file logging
     * @param string $fileLogLevel Minimum log level for file logging
     */
    public function __construct(
        private string $channel = 'app',
        private int $flushDelayMs = 100,
        private bool $useColors = true,
        private string $stdoutLogLevel = LogLevel::INFO,
        private ?string $logFilePath = null,
        private string $fileLogLevel = LogLevel::INFO,
    ) {}

    /**
     * Create a new logger with modified settings.
     * 
     * @param string $channel
     * @param string|null $stdoutLogLevel
     * @param string|null $fileLogLevel
     */
    public function withSettings(
        string $channel = 'app',
        ?string $stdoutLogLevel = null,
        ?string $fileLogLevel = null
    ): self {
        return new self(
            channel: $channel,
            flushDelayMs: $this->flushDelayMs,
            useColors: $this->useColors,
            stdoutLogLevel: $stdoutLogLevel ?? $this->stdoutLogLevel,
            logFilePath: $this->logFilePath,
            fileLogLevel: $fileLogLevel ?? $this->fileLogLevel,
        );
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->batch[] = [
            'message' => $message,
            'context' => $context,
            'level' => $level,
        ];
        if ($this->flushTimerId === null) {
            $this->flushTimerId = Timer::after($this->flushDelayMs, function () {
                $this->flush();
            });
        }
    }

    /**
     * Flush the log batch to stdout and file if configured.
     */
    public function flush(): void
    {
        if (!empty($this->batch)) {
            $stdoutLines = [];
            $fileLines = [];
            foreach ($this->batch as $entry) {
                $message = $entry['message'];
                $context = $entry['context'];
                $level   = $entry['level'];

                $stdout = $this->formatStdout($level, $this->interpolate($message, $context), $this->useColors);

                $stdoutLines[] = $stdout;
                if ($this->logFilePath && $this->levelCompare($entry['level'], $this->fileLogLevel) >= 0) {
                    $file = $this->formatFile($level, $message, $context);
                    $fileLines[] = $file;
                }
            }
            if ($stdoutLines) {
                $output = \implode(PHP_EOL, $stdoutLines) . PHP_EOL;
                \fwrite(STDOUT, $output);
            }
            if ($fileLines && $this->logFilePath) {
                $fileOutput = \implode(PHP_EOL, $fileLines) . PHP_EOL;
                \file_put_contents($this->logFilePath, $fileOutput, FILE_APPEND | LOCK_EX);
            }
            $this->batch = [];
        }
        if ($this->flushTimerId !== null) {
            Timer::clear($this->flushTimerId);
            $this->flushTimerId = null;
        }
    }

    /**
     * Format file output: ISO8601 timestamp, level, message, context as JSON
     */
    private function formatFile(string $level, string $message, array $context): string
    {
        $timestamp = \date('c');
        $contextJson = empty($context) ? '{}' : \json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return \sprintf('%s	%s %s	%s	%s', $timestamp, $this->channel, \strtoupper($level), $message, $contextJson);
    }

    /**
     * Format stdout output with optional colors
     */
    private function formatStdout(string $level, string $message, ?bool $useColors = null): string
    {
        $channelLevel = $this->channel . '.' . strtoupper($level);
        if (!$useColors) {
            return \sprintf('[%s] %s', $channelLevel, $message);
        }
        $colorMap = [
            LogLevel::EMERGENCY => "\033[1;41m", // white on red
            LogLevel::ALERT     => "\033[1;35m", // magenta
            LogLevel::CRITICAL  => "\033[1;31m", // red
            LogLevel::ERROR     => "\033[0;31m", // light red
            LogLevel::WARNING   => "\033[0;33m", // yellow
            LogLevel::NOTICE    => "\033[0;36m", // cyan
            LogLevel::INFO      => "\033[0;32m", // green
            LogLevel::DEBUG     => "\033[0;37m", // gray
        ];
        $reset = "\033[0m";
        $color = $colorMap[$level] ?? "";
        return \sprintf('%s %s[%s]%s %s', date('Y-m-d H:i:s'), $color, $channelLevel, $reset, $message);
    }


    /**
     * Compare log levels for thresholding (returns >=0 if $level >= $threshold)
     */
    private function levelCompare(string $level, string $threshold): int
    {
        $order = [
            LogLevel::DEBUG     => 0,
            LogLevel::INFO      => 1,
            LogLevel::NOTICE    => 2,
            LogLevel::WARNING   => 3,
            LogLevel::ERROR     => 4,
            LogLevel::CRITICAL  => 5,
            LogLevel::ALERT     => 6,
            LogLevel::EMERGENCY => 7,
        ];
        return ($order[$level] ?? 0) - ($order[$threshold] ?? 0);
    }

    /**
     * Interpolate context values into the message placeholders.
     * 
     * Example: "User {username} created" with ['username' => 'alice']
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (\is_array($val)) {
                $val = \json_encode($val);
            } elseif (\is_object($val)) {
                $val = \method_exists($val, '__toString') ? (string)$val : '[object]';
            }
            $replace['{' . $key . '}'] = $val;
        }
        return \strtr($message, $replace);
    }
}
