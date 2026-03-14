const express = require('express');
const bodyParser = require('body-parser');
const { WebSocketServer } = require('ws');

const PORT = process.env.PORT || 3001;
const app = express();
app.use(bodyParser.json());

// Simple health
app.get('/health', (req, res) => res.json({ ok: true, ts: Date.now() }));

// Broadcast endpoint: PHP will POST here when attendance changes
let wss;
app.post('/broadcast', (req, res) => {
  const payload = req.body || { ts: Date.now() };
  const message = JSON.stringify({ type: 'dashboard:update', payload });
  if (wss && wss.clients) {
    wss.clients.forEach(client => {
      if (client.readyState === 1) client.send(message);
    });
  }
  res.json({ ok: true });
});

const server = app.listen(PORT, () => {
  console.log(`QR WS relay listening on http://localhost:${PORT}`);
});

wss = new WebSocketServer({ server });
wss.on('connection', (ws) => {
  console.log('WS client connected');
  ws.on('message', (msg) => {
    // echo or debug
    try { console.log('WS recv:', msg.toString()); } catch(e){}
  });
});

process.on('SIGINT', () => process.exit());
