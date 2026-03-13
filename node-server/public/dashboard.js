const STATUS = document.getElementById('status');
const stats = {
  schools: document.getElementById('statSchools'),
  students: document.getElementById('statStudents'),
  absent: document.getElementById('statAbsent'),
  teachers: document.getElementById('statTeachers'),
};

let intervalId = null;
let lastTs = null;

const setStatus = (text, isError = false) => {
  STATUS.textContent = text;
  STATUS.style.color = isError ? '#b91c1c' : '#555';
};

const safeNumber = (v) => (typeof v === 'number' ? v : Number(v) || 0);

const render = (payload) => {
  const s = payload.stats || {};
  stats.schools.textContent = safeNumber(s.total_schools);
  stats.students.textContent = safeNumber(s.timed_in_today);
  stats.absent.textContent = safeNumber(s.absent_today);
  stats.teachers.textContent = `${safeNumber(s.teachers_in)}/${safeNumber(s.total_teachers)}`;
};

const renderSchoolControls = (schools = [], selectedSchool) => {
  const select = document.getElementById('schoolSelect');
  select.innerHTML = '<option value="">All schools</option>' +
    schools.map(s => `
      <option value="${s.id}" ${String(s.id) === String(selectedSchool) ? 'selected' : ''}>
        ${s.name}
      </option>
    `).join('');
};

const renderBreakdown = (list = []) => {
  const container = document.getElementById('breakdownContainer');
  if (!container) return;
  if (!Array.isArray(list) || list.length === 0) {
    container.innerHTML = '<div class="card"><h2>School Breakdown</h2><p class="small">No data available.</p></div>';
    return;
  }

  container.innerHTML = `
    <div class="card">
      <h2>School Breakdown</h2>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="text-align:left;">
              <th style="padding:8px;">School</th>
              <th style="padding:8px;">Enrolled</th>
              <th style="padding:8px;">Present</th>
              <th style="padding:8px;">Absent</th>
              <th style="padding:8px;">Rate</th>
              <th style="padding:8px;">Teachers Present</th>
            </tr>
          </thead>
          <tbody>
            ${list.map(item => `
              <tr>
                <td style="padding:8px;">${item.name}</td>
                <td style="padding:8px;">${item.enrolled}</td>
                <td style="padding:8px;">${item.present}</td>
                <td style="padding:8px;">${item.absent}</td>
                <td style="padding:8px;">${item.rate}%</td>
                <td style="padding:8px;">${item.teachers_present}/${item.total_teachers}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    </div>
  `;
};

const renderFlagged = (list = []) => {
  const container = document.getElementById('flaggedContainer');
  if (!container) return;

  if (!Array.isArray(list) || list.length === 0) {
    container.innerHTML = `
      <div class="card">
        <h2>Flagged (2-day absence)</h2>
        <p class="small">No flagged students.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="card">
      <h2>Flagged (2-day absence)</h2>
      <div style="max-height:320px;overflow-y:auto;">
        ${list.map(item => `
          <div style="padding:10px 0; border-bottom:1px solid rgba(0,0,0,0.08);">
            <div style="font-weight:600">${item.name} (${item.lrn})</div>
            <div class="small">${item.school_name} • ${item.grade_name || ''} ${item.section_name || ''}</div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
};

const renderInactive = (list = []) => {
  const container = document.getElementById('inactiveContainer');
  if (!container) return;

  if (!Array.isArray(list) || list.length === 0) {
    container.innerHTML = `
      <div class="card">
        <h2>Inactive Students</h2>
        <p class="small">No inactive students.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="card">
      <h2>Inactive Students</h2>
      <div style="max-height:320px;overflow-y:auto;">
        ${list.map(item => `
          <div style="padding:10px 0; border-bottom:1px solid rgba(0,0,0,0.08);">
            <div style="font-weight:600">${item.name}</div>
            <div class="small">${item.lrn} • ${item.school_name || ''}</div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
};

const fetchDashboard = async (opts = {}) => {
  try {
    const params = new URLSearchParams();
    if (opts.date) params.set('date', opts.date);
    if (opts.school) params.set('school', opts.school);

    const url = '/api/dashboard' + (params.toString() ? `?${params.toString()}` : '');
    const res = await fetch(url, {
      cache: 'no-store',
      headers: {
        'x-admin-id': 'local',
      },
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    if (data.ts && data.ts === lastTs) {
      setStatus('Up to date ✓');
      return;
    }
    lastTs = data.ts;
    render(data);
    renderSchoolControls(data.schools, opts.school);
    renderBreakdown(data.school_breakdown);
    renderFlagged(data.flagged_students);
    renderInactive(data.inactive_students);
    setStatus('Live ✓ ' + new Date().toLocaleTimeString());
  } catch (err) {
    setStatus('Error: ' + err.message, true);
  }
};

const startPolling = (intervalMs) => {
  if (intervalId) clearInterval(intervalId);
  if (!intervalMs || intervalMs <= 0) {
    setStatus('Polling paused (manual refresh only)');
    return;
  }
  fetchDashboard();
  intervalId = setInterval(fetchDashboard, intervalMs);
};

const init = () => {
  const select = document.getElementById('intervalSelect');
  const dateInput = document.getElementById('dateInput');
  const schoolSelect = document.getElementById('schoolSelect');

  const applyFilters = () => {
    const date = dateInput.value;
    const school = schoolSelect.value;
    fetchDashboard({ date, school });
  };

  select.addEventListener('change', () => startPolling(Number(select.value)));
  dateInput.addEventListener('change', applyFilters);
  schoolSelect.addEventListener('change', applyFilters);

  // initialize date input to today
  dateInput.value = new Date().toISOString().slice(0, 10);

  startPolling(Number(select.value));
};

init();
