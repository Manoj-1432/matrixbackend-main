<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>Select Fitting Slot — Matrix Mobile Tyres</title>
  <style nonce="{{ $nonce }}">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #f3f4f6;
      color: #111827;
      min-height: 100vh;
    }

    /* ── panel ── */
    .panel {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 1rem;
      padding: 2rem;
      max-width: 860px;
      margin: 2rem auto;
      box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    }
    .panel-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #111827;
      margin-bottom: 1.75rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }
    .panel-title svg { color: #6b7280; }

    /* ── two-column layout ── */
    .layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2rem;
      align-items: flex-start;
    }
    @media (max-width: 600px) {
      .layout { grid-template-columns: 1fr; }
    }

    /* ── section label ── */
    .section-label {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #6b7280;
      margin-bottom: 0.85rem;
    }

    /* ── date grid ── */
    .date-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 0.5rem;
    }
    .date-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 0.55rem 0.25rem;
      border-radius: 0.6rem;
      border: 1.5px solid #e5e7eb;
      background: #fff;
      cursor: pointer;
      font-family: inherit;
      transition: border-color 0.13s, background 0.13s;
      gap: 0.05rem;
    }
    .date-btn:hover:not(:disabled):not(.selected) {
      border-color: #9ca3af;
      background: #f9fafb;
    }
    .date-btn.selected {
      background: #16a34a;
      border-color: #16a34a;
      color: #fff;
    }
    .date-btn.today-btn {
      opacity: 0.38;
      cursor: not-allowed;
      background: #f3f4f6;
      border-color: #e5e7eb;
    }
    .date-btn:disabled {
      opacity: 0.38;
      cursor: not-allowed;
    }
    .date-day  { font-size: 0.65rem; font-weight: 700; letter-spacing: 0.06em; color: inherit; }
    .date-num  { font-size: 1rem;    font-weight: 800; color: inherit; line-height: 1.1; }
    .date-mon  { font-size: 0.65rem; font-weight: 600; color: inherit; }
    .date-btn.selected .date-day,
    .date-btn.selected .date-mon { color: rgba(255,255,255,0.8); }
    .date-btn.today-btn .date-day,
    .date-btn.today-btn .date-num,
    .date-btn.today-btn .date-mon { color: #9ca3af; }

    /* ── slots ── */
    .slot-heading {
      font-size: 1rem;
      font-weight: 700;
      color: #111827;
      margin-bottom: 1rem;
    }
    .slots-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
      gap: 0.55rem;
    }
    .slot-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 0.6rem 0.5rem;
      border-radius: 0.6rem;
      border: 1.5px solid #e5e7eb;
      background: #fff;
      cursor: pointer;
      font-family: inherit;
      transition: border-color 0.13s, background 0.13s;
      gap: 0.1rem;
    }
    .slot-btn:hover:not(:disabled):not(.selected) { border-color: #9ca3af; background: #f9fafb; }
    .slot-btn.selected { border-color: #16a34a; background: #f0fdf4; }
    .slot-btn:disabled { opacity: 0.35; cursor: not-allowed; background: #f3f4f6; }
    .slot-start { font-size: 0.95rem; font-weight: 700; color: #111827; }
    .slot-end   { font-size: 0.75rem; color: #6b7280; }
    .slot-btn.selected .slot-start { color: #15803d; }
    .slot-full  { font-size: 0.62rem; color: #ef4444; font-weight: 600; margin-top: 0.1rem; }
    .slot-placeholder {
      text-align: center;
      color: #9ca3af;
      font-size: 0.82rem;
      padding: 1.5rem 0;
    }

    /* ── same-day notice ── */
    .notice {
      margin-top: 1.5rem;
      display: flex;
      align-items: flex-start;
      gap: 0.6rem;
      padding: 0.85rem 1rem;
      border-radius: 0.75rem;
      background: #fff7ed;
      border: 1px solid #fed7aa;
    }
    .notice svg { flex-shrink: 0; margin-top: 0.1rem; }
    .notice p { font-size: 0.8rem; line-height: 1.55; color: #9a3412; }
    .notice a { color: #ea580c; font-weight: 600; text-decoration: none; }

    /* ── footer buttons ── */
    .footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 2rem;
      padding-top: 1.25rem;
      border-top: 1px solid #f3f4f6;
    }
    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.65rem 1.1rem;
      border-radius: 0.65rem;
      border: 1.5px solid #e5e7eb;
      background: #fff;
      color: #374151;
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: border-color 0.13s;
    }
    .btn-back:hover { border-color: #9ca3af; }
    .btn-continue {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.65rem 1.4rem;
      border-radius: 0.65rem;
      border: none;
      background: #374151;
      color: #fff;
      font-size: 0.88rem;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      transition: background 0.13s, opacity 0.13s;
    }
    .btn-continue:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-continue:hover:not(:disabled) { background: #111827; }

    /* spinner */
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
      width: 1rem; height: 1rem;
      border: 2px solid #e5e7eb;
      border-top-color: #6b7280;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
      display: inline-block;
    }
  </style>
</head>
<body>

<div class="panel">
  <div class="panel-title">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Select Fitting Slot
  </div>

  <div class="layout">

    <!-- LEFT: date grid -->
    <div>
      <p class="section-label">Select a date</p>
      <div class="date-grid" id="date-grid"></div>

      <!-- same-day notice -->
      <div class="notice">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <p>
          <strong>Need same-day fitting?</strong><br>
          Online bookings require at least 1 day's notice. Contact us via
          <a href="tel:{{ config('workatmo.whatsapp_phone') }}">call</a> or
          <a href="https://wa.me/{{ preg_replace('/\D+/', '', config('workatmo.whatsapp_phone')) }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>.
        </p>
      </div>
    </div>

    <!-- RIGHT: slots -->
    <div>
      <p class="slot-heading" id="slot-heading">Slots</p>
      <div id="slots-container">
        <p class="slot-placeholder">Select a date to see available slots.</p>
      </div>
    </div>
  </div>

  <div class="footer">
    <button class="btn-back" onclick="history.back()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
      Back
    </button>
    <button class="btn-continue" id="continue-btn" disabled>
      Continue
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  </div>
</div>

<script>
  const TODAY      = '{{ \Carbon\Carbon::today()->toDateString() }}';
  const MIN_DATE   = '{{ $minDate }}';
  const PHONE      = '{{ config("workatmo.whatsapp_phone") }}';
  const DAYS_AHEAD = 20;
  const DAY_ABBR   = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
  const MON_ABBR   = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
  const DAY_NAMES  = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];

  let allSlots       = [];
  let occupiedKeys   = new Set();
  let selectedDate   = null;
  let selectedSlotId = null;

  function fmt12(t) {
    const [h, m] = t.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2,'0')} ${h >= 12 ? 'PM' : 'AM'}`;
  }

  /* ── build date grid ── */
  function buildDateGrid() {
    const grid = document.getElementById('date-grid');
    const base = new Date(TODAY + 'T00:00:00');

    for (let i = 0; i <= DAYS_AHEAD; i++) {
      const d    = new Date(base);
      d.setDate(d.getDate() + i);
      const iso  = d.toISOString().split('T')[0];
      const isToday = iso === TODAY;

      const btn  = document.createElement('button');
      btn.className = 'date-btn' + (isToday ? ' today-btn' : '');
      btn.disabled  = isToday;
      btn.dataset.date = iso;
      btn.innerHTML = `
        <span class="date-day">${DAY_ABBR[d.getDay()]}</span>
        <span class="date-num">${d.getDate()}</span>
        <span class="date-mon">${MON_ABBR[d.getMonth()]}</span>
      `;
      if (!isToday) {
        btn.addEventListener('click', () => selectDate(iso, btn));
      }
      grid.appendChild(btn);
    }
  }

  function selectDate(iso, btn) {
    document.querySelectorAll('.date-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedDate   = iso;
    selectedSlotId = null;
    document.getElementById('continue-btn').disabled = true;
    loadSlots(iso);
  }

  /* ── load slots for a date ── */
  async function loadSlots(date) {
    const container = document.getElementById('slots-container');
    const heading   = document.getElementById('slot-heading');
    const [y, m, d] = date.split('-');
    heading.textContent = `Slot for ${d}-${m}-${y}`;
    container.innerHTML = '<p class="slot-placeholder"><span class="spinner"></span> Loading…</p>';

    try {
      const res  = await fetch(`/api/public/slots/occupancy?from=${date}&to=${date}`);
      const json = await res.json();
      occupiedKeys = new Set((json.data?.occupancy ?? []).map(o => `${o.slot_id}_${o.date}`));
    } catch (_) { occupiedKeys = new Set(); }

    const dayName  = DAY_NAMES[new Date(date + 'T00:00:00').getDay()];
    const daySlots = allSlots.filter(s => s.day === dayName);

    if (!daySlots.length) {
      container.innerHTML = '<p class="slot-placeholder">No slots available on this day.</p>';
      return;
    }

    const grid = document.createElement('div');
    grid.className = 'slots-grid';

    daySlots.forEach(slot => {
      const booked = occupiedKeys.has(`${slot.id}_${date}`);
      const btn    = document.createElement('button');
      btn.className = 'slot-btn' + (booked ? '' : '');
      btn.disabled  = booked;
      btn.dataset.slotId = slot.id;
      btn.innerHTML = `
        <span class="slot-start">${fmt12(slot.start_time)}</span>
        <span class="slot-end">${fmt12(slot.end_time)}</span>
        ${booked ? '<span class="slot-full">Fully booked</span>' : ''}
      `;
      if (!booked) {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          selectedSlotId = slot.id;
          document.getElementById('continue-btn').disabled = false;
        });
      }
      grid.appendChild(btn);
    });

    container.innerHTML = '';
    container.appendChild(grid);
  }

  /* ── continue ── */
  document.getElementById('continue-btn').addEventListener('click', () => {
    if (!selectedSlotId || !selectedDate) return;
    const params = new URLSearchParams({ slot_id: selectedSlotId, fitting_date: selectedDate });
    window.location.href = '/checkout?' + params.toString();
  });

  /* ── init ── */
  (async () => {
    try {
      const res  = await fetch('/api/public/slots');
      const json = await res.json();
      allSlots   = json.data?.slots ?? [];
    } catch (_) {}
    buildDateGrid();
  })();
</script>
</body>
</html>
