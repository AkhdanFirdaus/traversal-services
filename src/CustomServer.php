<?php

namespace App;

use PHPSocketIO\SocketIO;

class CustomServer {
    private $port;
    private $io;

    public function __construct(int $port = 2021) {
        $this->port = $port;
        $this->io = new SocketIO($this->port);
    }

    public function createServer(): void {
        // Create a Socket.IO server on port 2021
        $this->io->on('connection', function ($socket) {
            echo "New connection established\n";
    
            // Listen for messages from clients
            $socket->on('php-message', function ($data) use ($socket) {
                echo "Received: " . json_encode($data) . "\n";
                $socket->emit('server-response', ['status' => 'ACK']);
            });
    
            $socket->on('disconnect', function () {
                echo "Client disconnected\n";
            });
        });
    }

    public function getServer(): SocketIO {
        return $this->io;
    }
}