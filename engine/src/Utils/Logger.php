<?php

declare(strict_types=1);

namespace App\Utils;

use DateTimeImmutable;
use Psr\Log\LoggerInterface; // Impor interface PSR-3
use Psr\Log\LogLevel;      // Impor konstanta level log PSR-3
use Stringable;

// Implementasikan LoggerInterface
class Logger implements LoggerInterface
{
    // Level logging kustom Anda bisa tetap ada jika ingin digunakan secara internal,
    // tetapi metode PSR-3 akan menggunakan konstanta dari LogLevel.
    public const DEBUG = 100;   // Sesuai dengan LogLevel::DEBUG
    public const INFO = 200;    // Sesuai dengan LogLevel::INFO
    public const WARNING = 300; // Sesuai dengan LogLevel::WARNING
    public const ERROR = 400;   // Sesuai dengan LogLevel::ERROR

    private string $logFilePath;
    private int $minLevelPsr; // Gunakan level PSR-3 untuk perbandingan internal

    private static array $psrLevelMap = [
        LogLevel::DEBUG     => self::DEBUG,
        LogLevel::INFO      => self::INFO,
        LogLevel::NOTICE    => self::INFO, // Map Notice ke Info
        LogLevel::WARNING   => self::WARNING,
        LogLevel::ERROR     => self::ERROR,
        LogLevel::CRITICAL  => self::ERROR, // Map Critical ke Error
        LogLevel::ALERT     => self::ERROR, // Map Alert ke Error
        LogLevel::EMERGENCY => self::ERROR, // Map Emergency ke Error
    ];


    public function __construct(string $logFilePath = 'php://stdout', string $minLevelName = LogLevel::INFO)
    {
        $this->logFilePath = $logFilePath;
        // Konversi nama level PSR-3 ke nilai integer internal Anda jika perlu,
        // atau langsung gunakan level PSR-3 untuk $minLevelPsr
        $this->minLevelPsr = $this->levelToPsrInt($minLevelName);
    }

    private function levelToPsrInt(string $levelName): int
    {
        $levelName = strtolower($levelName);
        return self::$psrLevelMap[$levelName] ?? self::INFO; // Default ke INFO jika tidak dikenal
    }

    // Implementasi metode-metode dari LoggerInterface
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level   Nama level dari Psr\Log\LogLevel
     * @param string|Stringable $message
     * @param array  $context
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levelName = (string) $level; // Level dari PSR-3 adalah string
        $currentLevelPsr = $this->levelToPsrInt($levelName);

        if ($currentLevelPsr < $this->minLevelPsr) {
            // Jika level logging kustom Anda lebih granular, Anda mungkin perlu logika berbeda.
            // Untuk PSR-3, perbandingan level biasanya dilakukan dengan konstanta integer dari library PSR-3 itu sendiri.
            // Namun, karena kita memetakan ke integer internal, kita gunakan itu.
            // Atau, Anda bisa menyimpan $minLevel sebagai string dan membandingkan dengan $levelName.
            return;
        }

        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $formattedMessage = sprintf(
            "[%s] [%s]: %s%s\n",
            $timestamp,
            strtoupper($levelName), // Gunakan nama level PSR-3
            $this->interpolate((string) $message, $context),
            $context ? " " . json_encode($context) : ""
        );

        if ($this->logFilePath === 'php://stdout' || $this->logFilePath === 'php://stderr') {
            file_put_contents($this->logFilePath, $formattedMessage);
        } else {
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
            // Pastikan key adalah string dan tidak mengandung karakter yang tidak valid untuk placeholder
            if (!is_string($key) || empty($key)) {
                continue;
            }
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replacements['{' . $key . '}'] = (string) $val;
            } elseif (is_scalar($val) || $val === null) {
                 $replacements['{' . $key . '}'] = (string) $val;
            } elseif (is_array($val)) { // PSR-3 tidak secara eksplisit menangani array, tapi json_encode adalah opsi
                $replacements['{' . $key . '}'] = '[array_data]'; // atau json_encode($val) jika diinginkan
            }
            // Objek tanpa __toString() atau resource akan diabaikan oleh strtr, atau bisa ditangani di sini
        }
        return strtr($message, $replacements);
    }
}
