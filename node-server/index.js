import express from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import dashboardRouter from './routes/dashboard.js';

dotenv.config();

const app = express();
app.use(cors());
app.use(express.json());

// Simple health endpoint
app.get('/health', (req, res) => res.json({ ok: true, time: new Date().toISOString() }));

app.use('/api', dashboardRouter);

// Serve static dashboard frontend
app.use(express.static(new URL('./public', import.meta.url).pathname));
app.get('*', (req, res) => {
  res.sendFile(new URL('./public/index.html', import.meta.url).pathname);
});

const port = Number(process.env.PORT || 0);

const server = app.listen(port, () => {
  const actualPort = server.address()?.port;
  console.log(`Server listening on http://localhost:${actualPort}`);
});

server.on('error', (err) => {
  console.error('Server failed to start:', err);
  process.exit(1);
});
