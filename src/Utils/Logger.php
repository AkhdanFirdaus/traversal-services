<?php

namespace Utils;

use ElephantIO\Client;
use ElephantIO\Engine\SocketIO\Version4X;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger extends AbstractLogger implements LoggerInterface
{
    private string $logFile;
    private ?Client $client = null;

    public function __construct(private string $roomName = '', private bool $useSocket = false)
    {
        $this->logFile = '/app/logs/app.log';
        $this->ensureLogDirectoryExists();

        if ($useSocket) {
            $this->initializeClient();
        }
    }

    private function ensureLogDirectoryExists(): void
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }

    private function initializeClient(): void
    {
        try {
            $host = $_ENV['SOCKET_HOST'] ?? 'localhost';
            $port = $_ENV['SOCKET_PORT'] ?? 3000;

            $this->client = new Client(new Version4X(
                "http://{$host}:{$port}",
                [],
            ));

            $this->client->connect();
        } catch (\Exception $e) {
            $this->error("Failed to initialize Socket.IO client", [
                'error' => $e->getMessage()
            ]);
            $this->client = null;
        }
    }

    public function sendUpdate(string $message, int $progress, array $data = []): void
    {
        if ($this->client === null) {
            $this->warning("Socket.IO client not initialized, skipping update", [
                'message' => $message,
                'progress' => $progress
            ]);
            return;
        }

        try {
            $payload = [
                'room_name' => $this->roomName,
                'message' => $message,
                'progress' => $progress,
                'timestamp' => time(),
                'data' => $data
            ];

            $this->client->emit('engine-update', $payload);
        } catch (\Exception $e) {
            $this->error("Failed to send Socket.IO update", [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }

    public function disconnect(): void
    {
        if ($this->useSocket) {
            try {
                $this->client->disconnect();
                $this->info("Destructor called: closing packet connection");
            } catch (\Exception $e) {
                $this->error("Failed to disconnect Socket.IO client", [
                    'error' => $e->getMessage()
                ]);
            }
        }
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
        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? json_encode($context) : '';
        $logMessage = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextString
        );

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        echo $logMessage;

        if ($this->useSocket) {
            $this->sendUpdate($logMessage, 0, $context);
        }
    }
}
