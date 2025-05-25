<?php

declare(strict_types=1);

namespace App\Utils;

use DateTimeImmutable;
use Stringable;

// A simple logger, consider using a PSR-3 compliant logger like Monolog for more features.
class Logger
{
    public const DEBUG = 100;
    public const INFO = 200;
    public const WARNING = 300;
    public const ERROR = 400;

    private string $logFilePath;
    private int $minLevel;

    public function __construct(string $logFilePath = 'php://stdout', int $minLevel = self::INFO)
    {
        $this->logFilePath = $logFilePath;
        $this->minLevel = $minLevel;
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(self::DEBUG, (string) $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(self::INFO, (string) $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(self::WARNING, (string) $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(self::ERROR, (string) $message, $context);
    }

    private function log(int $level, string $message, array $context = []): void
    {
        if ($level < $this->minLevel) {
            return;
        }

        $levelName = match ($level) {
            self::DEBUG => 'DEBUG',
            self::INFO => 'INFO',
            self::WARNING => 'WARNING',
            self::ERROR => 'ERROR',
            default => 'LOG',
        };

        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $formattedMessage = sprintf(
            "[%s] [%s]: %s%s\n",
            $timestamp,
            $levelName,
            $this->interpolate($message, $context),
            $context ? " " . json_encode($context) : ""
        );

        if ($this->logFilePath === 'php://stdout' || $this->logFilePath === 'php://stderr') {
            file_put_contents($this->logFilePath, $formattedMessage);
        } else {
            // Ensure directory exists
            $logDir = dirname($this->logFilePath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }
            file_put_contents($this->logFilePath, $formattedMessage, FILE_APPEND);
        }
    }

    /**
     * Interpolates context values into the message placeholders.
     * (PSR-3 style interpolation)
     */
    private function interpolate(string $message, array $context = []): string
    {
        if (strpos($message, '{') === false) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements['{' . $key . '}'] = $val;
            } elseif (is_scalar($val)) {
                 $replacements['{' . $key . '}'] = (string) $val;
            }
        }
        return strtr($message, $replacements);
    }
}
