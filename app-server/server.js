require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require("socket.io");
const fs = require('fs');
const path = require('path');
const { exec } = require('child_process');

const reportPath = path.resolve('/app/logs/final_report.json');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: "*" }
});

io.on('connection', (socket) => {
  console.log('Client connected:', socket.id);

  socket.on('message', (data) => {
    console.log('message: ' + JSON.stringify(data))
  });

  socket.on('analyze_repo', async (gitUrl) => {
    console.log("Analyzing repo: " + gitUrl);
    const jobId = Date.now();
    try {
      const request = await fetch('http://0.0.0.0:8080/analyze', {url: gitUrl})
      const response = await request.json();
      socket.emit('message', response);
    } catch (error) {
      socket.emit('message', {error: error.message});
    }
  });
});

server.listen(3000, () => {
  console.log('Socket.IO server running on http://localhost:3000');
});