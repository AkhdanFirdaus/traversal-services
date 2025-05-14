require('dotenv').config();

const express = require('express');
const http = require('http');
const { Server } = require("socket.io");

const { generateTestCase } = require('./ai/aiAdapter');
const axios = require('axios');

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
    try {
      const request = await axios.post('http://0.0.0.0:8080/analyze', {
        url: gitUrl,
      });

      const openai = await generateTestCase('openai', request.data)
      const anthropic = await generateTestCase('anthropic', request.data)
      const gemini = await generateTestCase('gemini', request.data)

      socket.emit('message', {
        openai,
        anthropic,
        gemini,
      });

    } catch (error) {
      socket.emit('message', {error: error.message});
    }
  });
});

server.listen(3000, () => {
  console.log('Socket.IO server running on http://localhost:3000');
});