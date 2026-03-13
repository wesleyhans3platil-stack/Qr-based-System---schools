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

const port = Number(process.env.PORT || 3000);
app.listen(port, () => {
  console.log(`Server listening on http://localhost:${port}`);
});
