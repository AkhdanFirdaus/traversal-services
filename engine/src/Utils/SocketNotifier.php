<?php

declare(strict_types=1);

namespace App\Utils;

use ElephantIO\Client as ElephantIOClient;
use ElephantIO\Engine\SocketIO\Version4X; // Atau versi lain sesuai server Anda
use ElephantIO\Exception\SocketException;
use Psr\Log\LoggerInterface as PsrLoggerInterface; // Impor PSR-3 LoggerInterface
use Throwable;

class SocketNotifier
{
    private ?ElephantIOClient $client = null;
    // Logger sekarang diharapkan menjadi PsrLoggerInterface (MyProject\Utils\Logger sudah mengimplementasikannya)
    private ?PsrLoggerInterface $logger;
    private string $progressEventName;
    private bool $isConnected = false;
    private ?string $socketIoServerUrl;

    // Ubah type hint $logger menjadi PsrLoggerInterface
    public function __construct(?string $socketIoServerUrl, string $progressEventName = 'pipeline_progress', ?PsrLoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->progressEventName = $progressEventName;
        $this->socketIoServerUrl = $socketIoServerUrl;

        if (empty($this->socketIoServerUrl)) {
            $this->logger?->warning("SocketNotifier: Socket.IO server URL is not configured. Real-time updates will be disabled.");
            return;
        }

        $this->connect();
    }

    private function connect(): void
    {
        if ($this->isConnected || empty($this->socketIoServerUrl)) {
            return;
        }

        try {
            $this->client = new ElephantIOClient(
                new Version4X($this->socketIoServerUrl, [
                    // Opsi konteks jika diperlukan
                ]),
                $this->logger // Langsung teruskan logger Anda (yang kini PSR-3 compliant)
            );

            $this->client->connect();
            $this->isConnected = true;
            $this->logger?->info("SocketNotifier: Successfully connected to Socket.IO server at {url}", ['url' => $this->socketIoServerUrl]);
        } catch (SocketException $e) {
            $this->logger?->error("SocketNotifier: Failed to connect to Socket.IO server at {url}. Error: {errorMessage}", [
                'url' => $this->socketIoServerUrl,
                'errorMessage' => $e->getMessage()
            ]);
            $this->client = null;
            $this->isConnected = false;
        } catch (Throwable $e) {
            $this->logger?->error("SocketNotifier: An unexpected error occurred during Socket.IO connection. Error: {errorMessage}", [
                'errorMessage' => $e->getMessage()
            ]);
            $this->client = null;
            $this->isConnected = false;
        }
    }

    public function emitProgress(string $stage, mixed $data = [], ?string $repoUrl = null, ?string $taskId = null): void
    {
        if (!$this->isConnected) {
            $this->logger?->debug("SocketNotifier: Not connected, attempting to reconnect before emitting for stage: {stage}", ['stage' => $stage]);
            $this->connect();
            if (!$this->isConnected) {
                 $this->logger?->warning("SocketNotifier: Still not connected, skipping emit for stage: {stage}", ['stage' => $stage]);
                return;
            }
        }

        if (!$this->client) {
            $this->logger?->error("SocketNotifier: Client is null, cannot emit progress for stage: {stage}", ['stage' => $stage]);
            return;
        }

        $payload = [
            'taskId' => $taskId,
            'repoUrl' => $repoUrl,
            'timestamp' => date('Y-m-d H:i:sP'),
            'stage' => $stage,
            'data' => $data,
        ];

        try {
            $this->client->emit($this->progressEventName, $payload);
            $this->logger?->debug("SocketNotifier: Emitted progress for stage: {stage}", ['stage' => $stage, 'payload_summary' => array_slice($payload, 0, 3)]);
        } catch (SocketException $e) {
            $this->logger?->error("SocketNotifier: Failed to emit progress for stage {stage}. Error: {errorMessage}", [
                'stage' => $stage,
                'errorMessage' => $e->getMessage()
            ]);
            $this->isConnected = false;
        } catch (Throwable $e) {
            $this->logger?->error("SocketNotifier: An unexpected error occurred during emit for stage {stage}. Error: {errorMessage}", [
                'stage' => $stage,
                'errorMessage' => $e->getMessage()
            ]);
        }
    }

    public function close(): void
    {
        if ($this->client instanceof ElephantIOClient) {
            try {
                $this->logger?->debug("SocketNotifier: Attempting to close Socket.IO connection.");
                $this->client->disconnect();
                $this->logger?->info("SocketNotifier: Socket.IO connection closed successfully via client method.");
            } catch (SocketException $e) {
                 $this->logger?->error("SocketNotifier: SocketException while closing Socket.IO connection: {errorMessage}", ['errorMessage' => $e->getMessage()]);
            } catch (Throwable $e) {
                 $this->logger?->error("SocketNotifier: Unexpected error while closing Socket.IO connection: {errorMessage}", ['errorMessage' => $e->getMessage()]);
            }
        } elseif ($this->client !== null) {
            $this->logger?->warning("SocketNotifier: close() called, but client is not an ElephantIOClient instance. Type: {clientType}", ['clientType' => get_class($this->client)]);
        } else {
            $this->logger?->debug("SocketNotifier: close() called, but client was already null.");
        }

        $this->isConnected = false;
        $this->client = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

// Tidak perlu Psr_Log_LoggerInterface_Placeholder atau Psr3LoggerAdapter lagi
// Tidak perlu class_alias lagi
