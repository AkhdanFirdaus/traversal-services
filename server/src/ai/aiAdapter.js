require('dotenv').config();

const axios = require('axios');
const OpenAI = require('openai');

const { buildTestPrompt } = require('./prompts');

const HEADERS = {
  openai: {
    'Authorization': `Bearer ${process.env.OPENAI_API_KEY}`,
    'Content-Type': 'application/json'
  },
  anthropic: {
    'x-api-key': process.env.ANTHROPIC_API_KEY,
    'Content-Type': 'application/json'
  },
  gemini: {
    'Content-Type': 'application/json'
  }
};

async function askOpenAI(prompt) {
  const client = new OpenAI({
    apiKey: process.env['OPENAI_API_KEY'],
  });
  const res = await client.responses.create({
    model: 'gpt-4o',
    instructions: prompt,
    input: 'You are a security-focused AI assistant. Given the following PHP vulnerability context, generate a PHPUnit-compatible test case that attempts to exploit or validate the identified issue.',
  });
  return res.output_text;
}

async function askAnthropic(prompt) {
  const res = await axios.post('https://api.anthropic.com/v1/messages', {
    model: 'claude-3-opus-20240229',
    messages: [{ role: 'user', content: prompt }],
    max_tokens: 1024
  }, { headers: HEADERS.anthropic });
  return res.data.content[0].text.trim();
}

async function askGemini(prompt) {
  const res = await axios.post(`https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=${process.env.GOOGLE_GEMINI_API_KEY}`, {
    contents: [{
      role: 'user',
      parts: [{ text: prompt }]
    }]
  }, { headers: HEADERS.gemini });
  return res.data.candidates[0].content.parts[0].text.trim();
}

exports.generateTestCase = async function (agent, vulnReport) {
  const prompt = buildTestPrompt(vulnReport);

  switch (agent.toLowerCase()) {
    case 'openai':
    case 'chatgpt':
      return await askOpenAI(prompt);
    case 'anthropic':
    case 'claude':
      return await askAnthropic(prompt);
    case 'gemini':
    case 'google':
      return await askGemini(prompt);
    default:
      throw new Error(`Unknown agent: ${agent}`);
  }
};
