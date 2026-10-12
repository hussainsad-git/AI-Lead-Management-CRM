(function ($, LF) {
  'use strict';
  const today = LF.localDate(new Date());
  let ym = today.slice(0, 7), items = [];
  const pad = n => String(n).padStart(2, '0');
  const dayKey = iso => LF.localInput(new Date(iso)).slice(0, 10);

  function render() {
    const [y, m] = ym.split('-').map(Number);
    const first = new Date(Date.UTC(y, m - 1, 1));
    const startDow = (first.getUTCDay() + 6) % 7; // Monday first
    const daysInMonth = new Date(Date.UTC(y, m, 0)).getUTCDate();
    $('#cal-title').text(new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(first));
    $('#cal-head').html(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(d => `<div class="cal-head">${d}</div>`).join(''));
    const byDay = {};
    items.forEach(a => { (byDay[dayKey(a.starts_at)] = byDay[dayKey(a.starts_at)] || []).push(a); });
    const cells = [];
    const total = Math.ceil((startDow + daysInMonth) / 7) * 7;
    for (let i = 0; i < total; i++) {
      const d = new Date(Date.UTC(y, m - 1, 1 + i - startDow));
      const key = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
      const other = d.getUTCMonth() !== m - 1;
      cells.push(`<div class="cal-cell ${other ? 'other' : ''} ${key === today ? 'today' : ''}"><span class="cal-day">${d.getUTCDate()}</span>
        ${(byDay[key] || []).map(a => `<button class="cal-event s-${LF.esc(a.status)}" data-appt="${a.id}" title="${LF.esc(a.title + ' · ' + (a.contact || ''))}">${LF.fmt.time(a.starts_at)} ${LF.esc(a.contact || a.title)}</button>`).join('')}</div>`);
    }
    $('#cal-grid').html(cells.join(''));
    $('#cal-list').html(items.length ? items.map(a => `<li class="list-group-item"><button class="btn btn-link p-0 text-start" data-appt="${a.id}"><strong>${LF.fmt.dateTime(a.starts_at)}</strong> · ${LF.esc(a.title)}</button><div class="small text-muted">${LF.esc(a.contact || '')} · ${LF.esc(LF.fmt.label(a.status))}</div></li>`).join('') : `<li class="list-group-item">${LF.empty('calendar', 'No appointments this month.')}</li>`);
  }

  function load() {
    const [y, m] = ym.split('-').map(Number);
    const from = `${ym}-01`, to = `${ym}-${pad(new Date(Date.UTC(y, m, 0)).getUTCDate())}`;
    const q = { from, to };
    if ($('#cal-user').val()) q.assigned_user_id = $('#cal-user').val();
    LF.api('GET', 'appointments', q).then(r => { items = r.data; render(); });
  }
  const shift = n => { const [y, m] = ym.split('-').map(Number); const d = new Date(Date.UTC(y, m - 1 + n, 1)); ym = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}`; load(); };
  $('#cal-prev').on('click', () => shift(-1)); $('#cal-next').on('click', () => shift(1)); $('#cal-today').on('click', () => { ym = today.slice(0, 7); load(); });
  $('#cal-user').on('change', load);
  $(document).on('click', '[data-appt]', function () {
    const a = items.find(x => x.id === $(this).data('appt'));
    LF.confirm({ title: a.title, confirmText: 'Save', html: `<p class="small mb-2"><a href="/leads/${a.lead_id}">${LF.esc(a.contact || 'Open lead')}</a> · ${LF.fmt.dateTime(a.starts_at)}${a.location ? ' · ' + LF.esc(a.location) : ''}<br>${LF.esc(a.assignee ? a.assignee.name : '')}</p>
      <label class="form-label" for="ap-st">Status</label><select class="form-select" id="ap-st">${['scheduled', 'completed', 'no_show', 'cancelled'].map(s => `<option value="${s}" ${s === a.status ? 'selected' : ''}>${LF.fmt.label(s)}</option>`).join('')}</select>` })
      .then(() => { const s = $('#ap-st').val(); if (s !== a.status) LF.api('PATCH', 'appointments/' + a.id, { status: s }).then(() => { LF.toast('Updated'); load(); }); });
  });
  LF.lookups().then(lk => {
    if (LF.can('followups.manage_all')) $('#cal-user').html(['<option value="">Everyone</option>', `<option value="${LF.userId}">Me</option>`].concat(lk.users.filter(u => u.id !== LF.userId).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`)).join(''));
    else $('#cal-user').addClass('hidden');
    load();
  });
})(jQuery, window.LF);
