<?php

declare(strict_types=1);

namespace Swoldier;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Swoole\Timer;

/**
 * Simple logger with batching for better performance.
 */
class SimpleLogger implements LoggerInterface
{
    private array $batch = [];
    private ?int $flushTimerId = null;

    public function __construct(
        private int $flushDelayMs = 100,
        private bool $useColors = true,
        private string $stdoutLogLevel = LogLevel::DEBUG,
        private ?string $logFilePath = null,
        private string $fileLogLevel = LogLevel::INFO,
    ) {
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
        $interpolated = self::interpolate($message, $context);
        $entry = self::formatEntry($level, $interpolated, $this->useColors);
        $fileEntry = $this->formatFileEntry($level, $message, $context);
        $this->batch[] = [
            'stdout' => $entry,
            'file' => $fileEntry,
            'level' => $level,
        ];
        if ($this->flushTimerId === null) {
            $this->flushTimerId = Timer::after($this->flushDelayMs, function () {
                self::flush();
            });
        }
    }

    /**
     * Format file output: ISO8601 timestamp, level, message, context as JSON
     */
    private function formatFileEntry(string $level, string $message, array $context): string
    {
        $timestamp = \date('c');
        $contextJson = empty($context) ? '{}' : \json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return \sprintf('%s	%s	%s	%s', $timestamp, \strtoupper($level), $message, $contextJson);
    }

    private function formatEntry(string $level, string $message, ?bool $useColors = null): string
    {
        if (!$useColors) {
            return \sprintf('[%s] %s', \strtoupper($level), $message);
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
        return \sprintf('%s[%s]%s %s', $color, \strtoupper($level), $reset, $message);
    }

    public function flush(): void
    {
        if (!empty($this->batch)) {
            $stdoutLines = [];
            $fileLines = [];
            foreach ($this->batch as $entry) {
                $stdoutLines[] = $entry['stdout'];
                if ($this->logFilePath && self::levelCompare($entry['level'], $this->fileLogLevel) >= 0) {
                    $fileLines[] = $entry['file'];
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
     * Compare log levels for thresholding (returns >=0 if $level >= $threshold)
     */
    private static function levelCompare(string $level, string $threshold): int
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

    private static function interpolate(string $message, array $context): string
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
