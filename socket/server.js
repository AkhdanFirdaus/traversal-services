const express = require('express');
const app = express();
const http = require('http').createServer(app);
const io = require('socket.io')(http, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

// Keep track of connected clients
let connectedClients = new Set();

io.on('connection', (socket) => {
    console.log('Client connected:', socket.id);
    connectedClients.add(socket.id);

    // Handle progress updates from the PHP application
    socket.on('progress_update', (data) => {
        console.log('Progress update:', data);
        // Broadcast the update to all connected clients
        io.emit('progress_update', {
            ...data,
            socketId: socket.id
        });
    });

    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
        connectedClients.delete(socket.id);
    });
});

// Basic health check endpoint
app.get('/health', (req, res) => {
    res.json({
        status: 'healthy',
        connectedClients: Array.from(connectedClients)
    });
});

const PORT = process.env.PORT || 3000;
http.listen(PORT, () => {
    console.log(`Socket.IO server running on port ${PORT}`);
}); 