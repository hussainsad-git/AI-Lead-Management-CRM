(function ($, LF) {
  'use strict';
  const params = new URLSearchParams(window.location.search);
  let view = params.get('view') || 'today', page = 1, rows = [];

  function load() {
    $('#fuTabs .nav-link').removeClass('active').attr('aria-selected', 'false').filter(`[data-view="${view}"]`).addClass('active').attr('aria-selected', 'true');
    history.replaceState(null, '', '/follow-ups?view=' + view);
    $('#fu-body').html(`<tr><td colspan="8">${LF.loading()}</td></tr>`);
    const q = { view, page, per_page: 25 };
    if ($('#fu-filter-user').val()) q.assigned_user_id = $('#fu-filter-user').val();
    if ($('#fu-filter-prio').val()) q['priority[]'] = $('#fu-filter-prio').val();
    LF.api('GET', 'call-tasks', q).then(r => {
      rows = r.data;
      Object.entries(r.meta.counts).forEach(([k, v]) => $(`[data-count="${k}"]`).text(v || ''));
      const open = ['overdue', 'today', 'upcoming', 'missed'].includes(view);
      $('#fu-body').html(rows.length ? rows.map(t => {
        const c = t.lead && t.lead.contact ? t.lead.contact : {};
        return `<tr><td><a class="lf-row-link" href="/leads/${t.lead_id}">${LF.esc(c.name || 'Lead #' + t.lead_id)}</a>${c.do_not_contact ? ' <span class="dnc">DNC</span>' : ''}<div class="cell-muted">${t.lead && t.lead.status ? LF.esc(t.lead.status.name) : ''}</div></td>
          <td>${c.do_not_contact ? '<span class="cell-muted">—</span>' : LF.phoneLink(c.phone || c.whatsapp)}</td>
          <td class="text-nowrap"><span class="${t.is_overdue ? 'overdue' : ''}">${LF.fmt.dateTime(t.completed_at || t.scheduled_at)}</span><div class="cell-muted">${LF.fmt.rel(t.completed_at || t.scheduled_at)}</div></td>
          <td>${LF.esc(t.purpose)} <span class="cell-muted">#${t.attempt_number}</span>${t.notes ? `<div class="cell-muted">${LF.esc(t.notes)}</div>` : ''}</td>
          <td>${LF.priorityBadge(t.priority)}</td><td>${LF.esc(t.assignee ? t.assignee.name : '')}</td>
          <td>${t.outcome ? LF.esc(t.outcome.name) : LF.esc(LF.fmt.label(t.status))}${t.cancel_reason ? `<div class="cell-muted">${LF.esc(t.cancel_reason)}</div>` : ''}</td>
          <td class="text-end text-nowrap">${open ? `<button class="btn btn-sm btn-primary" data-log="${t.id}">Log outcome</button>
            <div class="btn-group"><button class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-label="More"></button><ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" data-resched="${t.id}">Reschedule</button></li><li><button class="dropdown-item text-danger" data-cancel="${t.id}">Cancel</button></li></ul></div>` : ''}</td></tr>`;
      }).join('') : `<tr><td colspan="8">${LF.empty('check2-circle', view === 'overdue' ? 'Nothing overdue.' : 'Nothing here.')}</td></tr>`);
      $('#fu-pager').html(LF.pager(r.meta));
    });
  }

  LF.lookups().then(lk => {
    if (LF.can('followups.manage_all')) {
      $('#fu-filter-user').html(['<option value="">Everyone</option>', `<option value="${LF.userId}">Me</option>`].concat(lk.users.filter(u => u.id !== LF.userId).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`)).join(''));
    } else $('#fu-filter-user').addClass('hidden');
    load();
  });
  $('#fuTabs').on('click', '[data-view]', function () { view = $(this).data('view'); page = 1; load(); });
  $('#fu-filter-user, #fu-filter-prio').on('change', () => { page = 1; load(); });
  $('#fu-pager').on('click', '[data-page]', function () { page = $(this).data('page'); load(); });
  $(document).on('click', '[data-log]', function () {
    const t = rows.find(r => r.id === $(this).data('log'));
    LF.callModal.open({ leadId: t.lead_id, taskId: t.id, context: (t.lead.contact.name || '') + ' · ' + t.purpose, onSaved: load });
  });
  $(document).on('click', '[data-cancel]', function () {
    const id = $(this).data('cancel');
    LF.confirm({ title: 'Cancel follow-up', reason: true, reasonRequired: true, danger: true, confirmText: 'Cancel follow-up' }).then(reason => LF.api('POST', `call-tasks/${id}/cancel`, { reason }).then(load));
  });
  $(document).on('click', '[data-resched]', function () {
    const id = $(this).data('resched');
    LF.confirm({ title: 'Reschedule', html: `<label class="form-label" for="rs-at">New time</label><input class="form-control" type="datetime-local" id="rs-at" value="${LF.tomorrowAt(11)}">`, confirmText: 'Reschedule' })
      .then(() => LF.api('POST', `call-tasks/${id}/reschedule`, { scheduled_at: $('#rs-at').val() }).then(() => { LF.toast('Rescheduled'); load(); }));
  });
})(jQuery, window.LF);
