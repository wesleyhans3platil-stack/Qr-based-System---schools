import express from 'express';
import pool from '../db.js';

const router = express.Router();

// Helper: validate YYYY-MM-DD
const isValidDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);

router.get('/dashboard', async (req, res) => {
  try {
    // NOTE: In production replace with proper auth (session/JWT).
    // For local/demo use we allow the request to proceed even without a header.
    const adminId = req.header('x-admin-id') || 'local';

    const filterDate = isValidDate(req.query.date) ? req.query.date : new Date().toISOString().slice(0, 10);
    const filterSchool = Number(req.query.school || 0);

    const [schoolsRows] = await pool.query('SELECT id, name, code FROM schools WHERE status = ? ORDER BY name;', ['active']);

    const schoolFilterSql = filterSchool > 0 ? ' AND s.id = ? ' : '';
    const schoolFilterParams = filterSchool > 0 ? [filterSchool] : [];

    const [statsRows] = await pool.query(
      'SELECT COUNT(*) AS cnt FROM schools WHERE status = ?;',
      ['active'],
    );
    const totalSchools = Number(statsRows[0]?.cnt ?? 0);

    const [studentsRows] = await pool.query('SELECT COUNT(*) AS cnt FROM students;');
    const totalStudents = Number(studentsRows[0]?.cnt ?? 0);

    const [teachersRows] = await pool.query('SELECT COUNT(*) AS cnt FROM teachers WHERE status = ?;', ['active']);
    const totalTeachers = Number(teachersRows[0]?.cnt ?? 0);

    const [presentRows] = await pool.query(
      'SELECT COUNT(DISTINCT person_id) AS cnt FROM attendance WHERE person_type = ? AND date = ? AND time_in IS NOT NULL;',
      ['student', filterDate],
    );
    const timedInToday = Number(presentRows[0]?.cnt ?? 0);

    const [activeStudentsRows] = await pool.query(
      "SELECT COUNT(*) AS cnt FROM students WHERE status = 'active';",
    );
    const activeStudents = Number(activeStudentsRows[0]?.cnt ?? 0);

    const absentToday = Math.max(0, activeStudents - timedInToday);

    // 2-day flagged students
    const yesterday = new Date(new Date(filterDate).getTime() - 24 * 60 * 60 * 1000)
      .toISOString()
      .slice(0, 10);

    const [flagRows] = await pool.query(
      `SELECT s.id, s.lrn, s.name, sch.name AS school_name, sch.code AS school_code,
              gl.name AS grade_name, sec.name AS section_name
         FROM students s
         LEFT JOIN schools sch ON s.school_id = sch.id
         LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id
         LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.status = 'active'
          AND s.id NOT IN (
              SELECT DISTINCT person_id FROM attendance
               WHERE person_type = 'student' AND date = ?
          )
          AND s.id NOT IN (
              SELECT DISTINCT person_id FROM attendance
               WHERE person_type = 'student' AND date = ?
          )
          ${filterSchool > 0 ? 'AND s.school_id = ?' : ''}
        ORDER BY sch.name, gl.id, s.name
        LIMIT 100;`,
      filterSchool > 0 ? [filterDate, yesterday, filterSchool] : [filterDate, yesterday],
    );

    const flaggedStudents = flagRows || [];

    // School breakdown
    const [breakdownRows] = await pool.query(
      `SELECT s.id, s.name, s.code,
              (SELECT COUNT(*) FROM students st
                 WHERE st.school_id = s.id AND st.status = 'active'
                   AND (DATE(st.created_at) < ? OR st.id IN (
                         SELECT DISTINCT person_id FROM attendance
                          WHERE person_type = 'student' AND date = ? AND time_in IS NOT NULL
                       )
                      )
              ) AS enrolled,
              (SELECT COUNT(DISTINCT a.person_id)
                 FROM attendance a
                 INNER JOIN students st ON a.person_id = st.id AND st.status = 'active'
                WHERE a.person_type = 'student' AND a.school_id = s.id
                  AND a.date = ? AND a.time_in IS NOT NULL
              ) AS present,
              (SELECT COUNT(DISTINCT a.person_id)
                 FROM attendance a
                 INNER JOIN teachers t ON a.person_id = t.id AND t.status = 'active'
                WHERE a.person_type = 'teacher' AND a.school_id = s.id
                  AND a.date = ? AND a.time_in IS NOT NULL
              ) AS teachers_present,
              (SELECT COUNT(*) FROM teachers t
                 WHERE t.school_id = s.id AND t.status = 'active'
              ) AS total_teachers
         FROM schools s
        WHERE s.status = 'active'
          ${schoolFilterSql}
        ORDER BY s.name;
      `,
      [filterDate, filterDate, filterDate, filterDate, ...schoolFilterParams],
    );

    const schoolBreakdown = (breakdownRows || []).map((row) => {
      const enrolled = Number(row.enrolled ?? 0);
      const present = Math.min(Number(row.present ?? 0), enrolled);
      const absent = Math.max(0, enrolled - present);
      const rate = enrolled > 0 ? Math.min(100, Math.round((present / enrolled) * 100)) : 0;
      return {
        ...row,
        enrolled,
        present,
        absent,
        rate,
      };
    });

    const schoolsRanked = [...schoolBreakdown].sort((a, b) => b.rate - a.rate).slice(0, 10);

    const payload = {
      ts: Date.now(),
      stats: {
        total_schools: totalSchools,
        total_students: totalStudents,
        total_teachers: totalTeachers,
        timed_in_today: timedInToday,
        absent_today: absentToday,
        flag_count: flaggedStudents.length,
      },
      schools: schoolsRows,
      school_breakdown: schoolBreakdown,
      schools_ranked: schoolsRanked,
      flagged_students: flaggedStudents,
    };

    res.json(payload);
  } catch (err) {
    console.error('dashboard error', err);
    res.status(500).json({ error: 'Server error' });
  }
});

export default router;
