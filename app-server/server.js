require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require("socket.io");
const fs = require('fs');
const path = require('path');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: "*" }
});

io.on('connection', (socket) => {
  console.log('Client connected:', socket.id);

  socket.on('message', (data) => {
    console.log('message: ' + JSON.stringify(data))
  })
});

server.listen(3000, () => {
  console.log('Socket.IO server running on http://localhost:3000');
});