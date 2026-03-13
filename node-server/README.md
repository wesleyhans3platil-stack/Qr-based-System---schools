# QR Attendance Node Dashboard

This directory contains a **Node.js-based dashboard** for the QR Attendance system.
It provides a real-time dashboard UI and a JSON API endpoint for attendance stats.

## How to run locally

1) Copy the env example and update with your database credentials:

```bash
cp .env.example .env
# edit .env with your MySQL credentials
```

2) Install dependencies:

```bash
npm install
```

3) Start the server:

```bash
npm start
```

4) Open the dashboard in your browser (URL printed in the console).

## Deployment (Railway-compatible)

This project is configured to run on Railway (or any host that provides `PORT`):

- Railway will set `PORT` automatically.
- The app listens on `process.env.PORT || 0` so it will work in any environment.

### Deploy steps (Railway):
1. Push this repo to GitHub
2. Create a new Railway project → Connect GitHub repo
3. Set environment variables in Railway:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_USER`
   - `DB_PASSWORD`
   - `DB_NAME`
4. Railway will build and run `npm start`.

## API

| Endpoint | Method | Description |
|---------|--------|-------------|
| `/api/dashboard` | GET | Returns dashboard stats + breakdown data |

The frontend (in `public/`) polls `/api/dashboard` and updates live.
