<?php

namespace Utils;

use ElephantIO\Client;
use ElephantIO\Engine\SocketIO\Version4X;

class SocketNotifier
{
    private ?Client $client = null;

    public function __construct(private Logger $logger, private string $roomName)
    {
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        try {
            $host = $_ENV['SOCKET_HOST'] ?? 'localhost';
            $port = $_ENV['SOCKET_PORT'] ?? 3000;
            
            $this->client = new Client(new Version4X(
                "http://{$host}:{$port}", [],
            ), $this->logger);
            
            $this->client->connect();
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize Socket.IO client", [
                'error' => $e->getMessage()
            ]);
            $this->client = null;
        }
    }

    public function sendUpdate(string $message, int $progress, array $data = []): void
    {
        if ($this->client === null) {
            $this->logger->warning("Socket.IO client not initialized, skipping update", [
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
            $this->logger->error("Failed to send Socket.IO update", [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }

    public function disconnect(): void
    {
        try {
            $this->client->disconnect();
            $this->logger->info("Destructor called: closing packet connection");
        } catch (\Exception $e) {
            $this->logger->error("Failed to disconnect Socket.IO client", [
                'error' => $e->getMessage()
            ]);
        }
    }

    // public function __destruct()
    // {
    //     if ($this->client !== null) {
    //         try {
    //             $this->client->disconnect();
    //         } catch (\Exception $e) {
    //             $this->logger->error("Failed to close Socket.IO connection", [
    //                 'error' => $e->getMessage()
    //             ]);
    //         }
    //     }
    // }
} 