import express from 'express';
import pool from '../db.js';

const router = express.Router();

// Helper: validate YYYY-MM-DD
const isValidDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);

router.get('/dashboard_data', async (req, res) => {
  try {
    // NOTE: Replace this with real auth (session/JWT) in production
    const adminId = req.header('x-admin-id');
    if (!adminId) {
      return res.status(401).json({ error: 'Unauthorized' });
    }

    const filterDate = isValidDate(req.query.date) ? req.query.date : new Date().toISOString().slice(0, 10);
    const filterSchool = Number(req.query.school || 0);

    const [rowsTotalSchools] = await pool.query(
      'SELECT COUNT(*) AS cnt FROM schools WHERE status = ?;',
      ['active'],
    );
    const totalSchools = Number(rowsTotalSchools[0]?.cnt ?? 0);

    const [rowsTotalStudents] = await pool.query('SELECT COUNT(*) AS cnt FROM students;');
    const totalStudents = Number(rowsTotalStudents[0]?.cnt ?? 0);

    const [rowsTotalTeachers] = await pool.query('SELECT COUNT(*) AS cnt FROM teachers WHERE status = ?;', ['active']);
    const totalTeachers = Number(rowsTotalTeachers[0]?.cnt ?? 0);

    // Example: basic attendance count for today
    const [rowsPresent] = await pool.query(
      'SELECT COUNT(DISTINCT person_id) AS cnt FROM attendance WHERE person_type = ? AND date = ? AND time_in IS NOT NULL;', 
      ['student', filterDate],
    );
    const timedInToday = Number(rowsPresent[0]?.cnt ?? 0);

    return res.json({
      ts: Date.now(),
      stats: {
        total_schools: totalSchools,
        total_students: totalStudents,
        total_teachers: totalTeachers,
        timed_in_today: timedInToday,
      },
    });
  } catch (err) {
    console.error('dashboard_data error', err);
    res.status(500).json({ error: 'Server error' });
  }
});

export default router;
