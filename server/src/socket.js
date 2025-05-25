const { generateTestCase } = require('./ai/aiAdapter');
const axios = require('axios');

exports.onConnection = (socket) => {
  console.log('Client connected:', socket.id);

  socket.on('message', (data) => {
    console.log('message: ' + JSON.stringify(data))
  });

  socket.on('analyze_repo', async (gitUrl) => {
    console.log("Analyzing repo: " + gitUrl);
    try {
      const request = await axios.post('http://engine:8080/analyze', {
        url: gitUrl,
      });
      
      socket.emit('server-response', {result: request.data});
      // const openai = await generateTestCase('openai', request.data)
      // const anthropic = await generateTestCase('anthropic', request.data)
      // const gemini = await generateTestCase('gemini', request.data)

      // socket.emit('message', {
      //   openai,
      //   anthropic,
      //   gemini,
      // });
      // socket.emit('message', {
      //   result: request.data,
      //   success: true,
      // });

    } catch (error) {
      socket.emit('server-response', {error: error.message});
    }
  });
}