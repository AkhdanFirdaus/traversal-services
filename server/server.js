require('dotenv').config();

const express = require('express');
const http = require('http');
const { Server } = require("socket.io");

const { onConnection } = require('./src/socket');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: "*" }
});

io.on('connection', onConnection);

server.listen(3000, () => {
  console.log('Socket.IO server running on http://engine:3000');
});