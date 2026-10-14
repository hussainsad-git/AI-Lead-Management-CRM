(function ($, LF) {
  'use strict';
  const leadId = parseInt(document.body.dataset.leadId, 10);
  let D = null, LK = null, tlPage = 1;

  const TL_ICONS = {
    lead_created: ['plus-circle', ''], note: ['sticky', 'tl-note'], call_logged: ['telephone', 'tl-call'], status_changed: ['flag', 'tl-status'],
    assigned: ['person-check', ''], reassigned: ['arrow-left-right', ''], follow_up_scheduled: ['alarm', ''], follow_up_rescheduled: ['alarm', ''],
    follow_up_cancelled: ['x-circle', ''], appointment_scheduled: ['calendar-event', ''], appointment_completed: ['calendar-check', ''],
    appointment_cancelled: ['calendar-x', ''], appointment_no_show: ['calendar-x', 'tl-warn'], lead_updated: ['pencil', ''], contact_updated: ['pencil', ''],
    opt_out: ['slash-circle', 'tl-warn'], consent_granted: ['shield-check', ''], consent_withdrawn: ['shield-x', 'tl-warn'], archived: ['archive', 'tl-warn'],
    contact_merged: ['union', ''], import_update: ['arrow-down-up', ''], whatsapp_in: ['whatsapp', 'tl-call'], whatsapp_out: ['send', ''], ai_qualified: ['stars', 'tl-status'], form_submitted: ['ui-checks', 'tl-call'], web_form_submission: ['globe', 'tl-call'], audience_added: ['diagram-3', ''], audience_removed: ['diagram-3', ''],
  };

  function tlText(a) {
    const m = a.meta || {};
    switch (a.type) {
      case 'lead_created': return 'Lead created' + (m.via ? ` (${LF.esc(m.via)})` : '');
      case 'note': return 'Note';
      case 'call_logged': return `Call: <strong>${LF.esc(m.outcome || '')}</strong>${m.attempt ? ` · attempt ${m.attempt}` : ''}${m.duration_seconds ? ` · ${Math.round(m.duration_seconds / 60)} min (entered)` : ''}${m.imported ? ' · imported' : ''}`;
      case 'status_changed': return `Status: ${LF.esc(m.from || '—')} → <strong>${LF.esc(m.to)}</strong>${m.via && m.via !== 'user' ? ` <span class="text-muted">(${LF.esc(LF.fmt.label(m.via))})</span>` : ''}`;
      case 'assigned': case 'reassigned': return `${m.from ? 'Reassigned from ' + LF.esc(m.from) + ' to ' : 'Assigned to '}<strong>${LF.esc(m.to || 'nobody')}</strong>`;
      case 'follow_up_scheduled': return `Follow-up scheduled for ${LF.fmt.dateTime(m.scheduled_at)}`;
      case 'follow_up_rescheduled': return `Follow-up moved to ${LF.fmt.dateTime(m.to)}`;
      case 'follow_up_cancelled': return 'Follow-up cancelled';
      case 'appointment_scheduled': return `${LF.esc(LF.fmt.label(m.type))} scheduled for ${LF.fmt.dateTime(m.starts_at)}`;
      case 'whatsapp_in': return `<i class="bi bi-whatsapp text-success"></i> ${LF.t('Customer message')}`;
      case 'whatsapp_out': return `${LF.t('WhatsApp reply')} <span class="text-muted">(${LF.esc(LF.fmt.label(m.sender || 'user'))})</span>`;
      case 'audience_added': return `${LF.t('Added to audience')} <strong>${LF.esc(m.segment)}</strong>${m.eligible === false ? ` <span class="text-muted">(${LF.t('not eligible for export')})</span>` : ''}`;
      case 'audience_removed': return `${LF.t('Left audience')} <strong>${LF.esc(m.segment)}</strong>`;
      case 'web_form_submission': return `${LF.t('Website form submitted again')}${m.form ? ': <strong>' + LF.esc(m.form) + '</strong>' : ''}${m.interest ? ` · ${LF.esc(m.interest)}` : ''}`;
      case 'form_submitted': return `${LF.t('Instant Form submitted again')}${m.form ? ': <strong>' + LF.esc(m.form) + '</strong>' : ''}`;
      case 'ai_qualified': return `${LF.t('AI captured')}: ` + Object.entries(m.filled || {}).map(([k, v]) => `${LF.esc(LF.fmt.label(k))} = <strong>${LF.esc(v)}</strong>`).join(', ');
      case 'lead_updated': case 'contact_updated': return 'Updated ' + Object.keys(m.changes || {}).map(k => LF.esc(LF.fmt.label(k.replace('contact.', '')))).join(', ');
      default: return LF.esc(LF.fmt.label(a.type));
    }
  }

  function dl(rows) {
    return '<dl class="dl-grid">' + rows.filter(r => r[1] !== null && r[1] !== undefined && r[1] !== '').map(([k, v]) => `<dt>${LF.esc(k)}</dt><dd>${v}</dd>`).join('') + '</dl>';
  }

  function render() {
    const l = D.lead, c = l.contact, closed = l.status.category !== 'open', dnc = c.do_not_contact;
    document.title = c.display_name + ' · LeadFlow';
    $('#crumb').text('#' + l.id + ' ' + c.display_name);
    const wa = LF.waLink(c.whatsapp || c.phone);
    const canFU = LF.can('followups.manage_own', 'followups.manage_all');
    const canUpd = LF.can('leads.update');
    const statusOpts = LK.statuses.filter(s => s.is_active || s.id === l.status.id).map(s => `<option value="${s.id}" ${s.id === l.status.id ? 'selected' : ''}>${LF.esc(s.name)}</option>`).join('');
    const userOpts = '<option value="">Unassigned</option>' + LK.users.map(u => `<option value="${u.id}" ${l.assignee && u.id === l.assignee.id ? 'selected' : ''}>${LF.esc(u.name)}</option>`).join('');
    const ft = D.first_touch, lt = D.last_touch;

    const html = `
    <div class="lf-page-head">
      <div>
        <h1>${LF.esc(c.display_name)} ${dnc ? '<span class="dnc align-middle">DO NOT CONTACT</span>' : ''} ${l.archived_at ? '<span class="badge text-bg-secondary align-middle">Archived</span>' : ''}</h1>
        <div class="small text-muted">Lead #${l.id} · created ${LF.fmt.dateTime(l.created_at)}${l.source ? ' · ' + LF.esc(l.source.name) : ''}</div>
      </div>
      <div class="lf-actions">
        ${c.phone && !dnc ? `<a class="btn btn-light btn-sm" href="tel:${LF.esc(c.phone)}"><i class="bi bi-telephone"></i> Call</a>` : ''}
        ${D.conversation_id ? `<a class="btn btn-light btn-sm" href="/whatsapp?c=${D.conversation_id}"><i class="bi bi-chat-dots"></i> ${LF.t('Conversation')}</a>` : (wa && !dnc ? `<a class="btn btn-light btn-sm" href="${wa}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>` : '')}
        ${canFU && !closed ? '<button class="btn btn-primary btn-sm" data-act="log-call"><i class="bi bi-journal-check"></i> Log call</button>' : ''}
        ${canFU && !closed && !dnc ? '<button class="btn btn-light btn-sm" data-act="follow-up"><i class="bi bi-alarm"></i> Follow-up</button>' : ''}
        ${canFU && !closed && !dnc ? '<button class="btn btn-light btn-sm" data-act="appt"><i class="bi bi-calendar-plus"></i> Appointment</button>' : ''}
        <div class="dropdown"><button class="btn btn-light btn-sm" data-bs-toggle="dropdown" aria-label="More actions"><i class="bi bi-three-dots"></i></button>
          <ul class="dropdown-menu dropdown-menu-end">
            ${canUpd ? '<li><button class="dropdown-item" data-act="edit"><i class="bi bi-pencil me-2"></i>Edit details</button></li>' : ''}
            <li><a class="dropdown-item" href="/contacts/${c.id}"><i class="bi bi-person-vcard me-2"></i>Open contact</a></li>
            ${LF.can('leads.archive') && !l.archived_at ? '<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" data-act="archive"><i class="bi bi-archive me-2"></i>Archive lead</button></li>' : ''}
          </ul></div>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
          <div class="row g-2">
            <div class="col-12"><label class="form-label small mb-1" for="d-status">Stage</label><select class="form-select form-select-sm" id="d-status" ${canUpd ? '' : 'disabled'}>${statusOpts}</select>
              ${l.lost_reason ? `<div class="small text-muted mt-1">Reason: ${LF.esc(l.lost_reason)}</div>` : ''}</div>
            <div class="col-6"><label class="form-label small mb-1" for="d-priority">Priority</label><select class="form-select form-select-sm" id="d-priority" ${canUpd ? '' : 'disabled'}>${['urgent', 'high', 'normal', 'low'].map(p => `<option value="${p}" ${p === l.priority ? 'selected' : ''}>${LF.fmt.label(p)}</option>`).join('')}</select></div>
            <div class="col-6"><label class="form-label small mb-1" for="d-assignee">Assigned to</label>${LF.can('leads.assign') ? `<select class="form-select form-select-sm" id="d-assignee">${userOpts}</select>` : `<div class="form-control form-control-sm bg-light">${LF.esc(l.assignee ? l.assignee.name : 'Unassigned')}</div>`}</div>
          </div>
          <hr>
          ${dl([
            ['Next follow-up', l.next_follow_up_at ? `<span class="${new Date(l.next_follow_up_at) < new Date() ? 'overdue' : ''}">${LF.fmt.dateTime(l.next_follow_up_at)}</span>` : '—'],
            ['Contact attempts', `${l.contact_attempts}${l.unanswered_attempts ? ` (${l.unanswered_attempts} unanswered in a row)` : ''}`],
            ['First contacted', l.first_contacted_at ? LF.fmt.dateTime(l.first_contacted_at) : 'Not yet'],
            ['First response', l.first_responded_at ? LF.fmt.dateTime(l.first_responded_at) : 'None recorded'],
            ['Last contacted', l.last_contacted_at ? LF.fmt.rel(l.last_contacted_at) : null],
            ['Qualified', l.qualified_at ? LF.fmt.date(l.qualified_at) : null],
            ['Converted', l.converted_at ? LF.fmt.date(l.converted_at) : null],
          ])}
        </div></div>

        ${l.score_label || l.ai_summary ? `<div class="card mb-3"><div class="card-header d-flex align-items-center">${LF.t('Lead score')}${l.score_label ? `<span class="score-pill score-${LF.esc(l.score_label)} ms-auto">${LF.esc(LF.t(l.score_label.replace('_', ' ')))} · ${l.score}</span>` : ''}</div><div class="card-body">
          ${(l.score_factors || []).map(f => `<div class="factor"><span>${LF.esc(f.label)}</span><span class="${f.points >= 0 ? 'pos' : 'neg'}">${f.points > 0 ? '+' : ''}${f.points}</span></div>`).join('')}
          ${l.ai_summary ? `<div class="small mt-2"><span class="small-caps">${LF.t('AI summary')}</span><div dir="auto">${LF.esc(l.ai_summary)}</div></div>` : ''}</div></div>` : ''}
        <div class="card mb-3"><div class="card-header d-flex">Contact${canUpd && LF.can('contacts.update') ? '<button class="btn btn-sm btn-link ms-auto p-0" data-act="edit">Edit</button>' : ''}</div><div class="card-body">
          ${dl([
            ['Type', LF.fmt.label(c.type)], ['Name', LF.esc(c.name)], ['Company', LF.esc(c.company_name)],
            ['Phone', c.phone ? LF.phoneLink(c.phone) + (c.phone_raw && c.phone_raw !== c.phone ? `<div class="cell-muted">entered as ${LF.esc(c.phone_raw)}</div>` : '') : null],
            ['WhatsApp', LF.esc(c.whatsapp)], ['Email', c.email ? `<a href="mailto:${LF.esc(c.email)}">${LF.esc(c.email)}</a>` : null],
            ['City', LF.esc(c.city)], ['Prefers', LF.esc(LF.fmt.label(c.preferred_contact_method))],
          ])}
        </div></div>

        <div class="card mb-3"><div class="card-header">Inquiry</div><div class="card-body">
          ${dl([
            ['Interested in', LF.esc(l.product_interest)], ['Budget', l.budget_amount ? LF.fmt.money(l.budget_amount, l.budget_currency) : null],
            ['Deal value', l.deal_value ? LF.fmt.money(l.deal_value, l.deal_currency) : null], ['Location', LF.esc(l.location)],
            ['Tags', (l.tags || []).map(t => `<span class="tag">${LF.esc(t.name)}</span>`).join('') || null],
            ['Source notes', l.source_notes ? `<span class="text-pre">${LF.esc(l.source_notes)}</span>` : null],
          ]) || '<div class="small text-muted">No inquiry details yet.</div>'}
        </div></div>

        <div class="card mb-3"><div class="card-header">Attribution</div><div class="card-body">
          ${ft ? dl([
            ['Platform', LF.esc(LF.fmt.label(ft.platform))], ['Campaign', LF.esc(ft.campaign_name_snapshot)], ['Ad set', LF.esc(ft.ad_set_name_snapshot)],
            ['Ad', LF.esc(ft.ad_name_snapshot)], ['Campaign ID', LF.esc(ft.external_campaign_id)], ['Captured via', LF.esc(LF.fmt.label(ft.captured_via))],
            ['Completeness', `<span class="badge ${ft.completeness === 'complete' ? 'text-bg-success' : 'text-bg-light border'}">${LF.esc(ft.completeness)}</span>`],
            ['Last touch', lt && lt.id !== ft.id && lt.campaign_name_snapshot !== ft.campaign_name_snapshot ? LF.esc(lt.campaign_name_snapshot || '—') : null],
          ]) : '<div class="small text-muted">No campaign attribution recorded. Only the lead source is known.</div>'}
        </div></div>

        <div class="card mb-3"><div class="card-header d-flex">Consent${LF.can('consent.manage') ? '<div class="ms-auto d-flex gap-2"><button class="btn btn-sm btn-link p-0" data-act="consent">Record</button>' + (dnc ? '' : '<button class="btn btn-sm btn-link p-0 text-danger" data-act="optout">Opt out</button>') + '</div>' : ''}</div><div class="card-body">
          <div class="consent-grid">${Object.entries(D.consent).map(([ch, v]) => `<div class="consent-item"><div class="text-muted">${LF.esc(LF.fmt.label(ch))}</div><div class="v-${LF.esc(v)}">${LF.esc(LF.fmt.label(v))}</div></div>`).join('')}</div>
          ${D.consent_records.length ? `<div class="small text-muted mt-2">Latest: ${LF.esc(LF.fmt.label(D.consent_records[0].channel))} ${LF.esc(D.consent_records[0].status)} (${LF.esc(LF.fmt.label(D.consent_records[0].source))}) ${LF.fmt.date(D.consent_records[0].recorded_at)}</div>` : ''}
        </div></div>

        ${D.other_leads.length || D.hidden_other_leads ? `<div class="card mb-3"><div class="card-header">Other inquiries from this contact</div><ul class="list-group list-group-flush">
          ${D.other_leads.map(o => `<li class="list-group-item d-flex"><a href="/leads/${o.id}">Lead #${o.id}</a><span class="ms-auto small text-muted">${LF.esc(o.status)} · ${LF.fmt.date(o.created_at)}</span></li>`).join('')}
          ${D.hidden_other_leads ? `<li class="list-group-item small text-muted">${D.hidden_other_leads} more owned by other users</li>` : ''}</ul></div>` : ''}
      </div>

      <div class="col-lg-8">
        <div class="card">
          <div class="card-header p-0 border-0"><ul class="nav nav-tabs px-2 pt-2" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-tl" role="tab">Timeline</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-calls" role="tab">Calls &amp; follow-ups <span class="badge text-bg-light">${D.call_tasks.length}</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-appts" role="tab">Appointments <span class="badge text-bg-light">${D.appointments.length}</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-hist" role="tab">Stage history</button></li>
          </ul></div>
          <div class="card-body tab-content">
            <div class="tab-pane fade show active" id="tab-tl" role="tabpanel">
              ${canUpd ? `<form id="noteForm" class="mb-3"><label class="visually-hidden" for="noteBody">Add internal note</label><textarea class="form-control mb-2" id="noteBody" name="body" rows="2" maxlength="5000" placeholder="Add an internal note (not visible to the customer)…"></textarea><div class="text-end"><button class="btn btn-sm btn-primary" type="submit">Add note</button></div></form>` : ''}
              <ul class="timeline" id="timeline"></ul><div class="text-center"><button class="btn btn-sm btn-light hidden" id="tlMore">Load older</button></div>
            </div>
            <div class="tab-pane fade" id="tab-calls" role="tabpanel">${renderTasks()}</div>
            <div class="tab-pane fade" id="tab-appts" role="tabpanel">${renderAppts()}</div>
            <div class="tab-pane fade" id="tab-hist" role="tabpanel">${D.status_history.length ? '<table class="table table-sm"><thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead><tbody>' +
              D.status_history.map(h => `<tr><td class="text-nowrap">${LF.fmt.dateTime(h.at)}</td><td>${LF.esc(h.from || '—')}</td><td>${LF.esc(h.to)}</td><td>${LF.esc(h.by || LF.fmt.label(h.via))}</td><td>${LF.esc(h.reason || '')}</td></tr>`).join('') + '</tbody></table>' : LF.empty('flag', 'No stage changes yet.')}</div>
          </div>
        </div>
      </div>
    </div>`;
    $('#leadRoot').html(html);
    tlPage = 1; loadTimeline(false);
  }

  function renderTasks() {
    if (!D.call_tasks.length) return LF.empty('telephone', 'No calls or follow-ups yet.');
    return '<div class="table-wrap"><table class="table table-sm align-middle"><thead><tr><th>When</th><th>Purpose</th><th>Status / outcome</th><th>Assignee</th><th>Notes</th><th></th></tr></thead><tbody>' + D.call_tasks.map(t => {
      const open = t.status === 'pending' || t.status === 'missed';
      const st = t.status === 'completed' ? `<span class="badge text-bg-light border">${LF.esc(t.outcome ? t.outcome.name : 'Done')}</span>` :
        t.status === 'pending' ? (t.is_overdue ? '<span class="overdue">Overdue</span>' : '<span class="badge text-bg-primary">Pending</span>') :
        `<span class="badge text-bg-secondary">${LF.esc(LF.fmt.label(t.status))}</span>`;
      return `<tr><td class="text-nowrap">${LF.fmt.dateTime(t.completed_at || t.scheduled_at)}</td><td>${LF.esc(t.purpose)} <span class="cell-muted">#${t.attempt_number}</span></td><td>${st}</td>
        <td>${LF.esc(t.assignee ? t.assignee.name : '')}</td><td class="small">${LF.esc(t.notes || t.cancel_reason || '')}</td>
        <td class="text-end text-nowrap">${open ? `<button class="btn btn-sm btn-light" data-task-log="${t.id}">Log outcome</button> <button class="btn btn-sm btn-light" data-task-cancel="${t.id}" aria-label="Cancel follow-up"><i class="bi bi-x"></i></button>` : ''}</td></tr>`;
    }).join('') + '</tbody></table></div>';
  }

  function renderAppts() {
    if (!D.appointments.length) return LF.empty('calendar', 'No appointments.');
    return '<ul class="list-group list-group-flush">' + D.appointments.map(a => `<li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
      <div><strong>${LF.esc(a.title)}</strong> <span class="badge text-bg-light border">${LF.esc(LF.fmt.label(a.type))}</span><div class="small text-muted">${LF.fmt.dateTime(a.starts_at)}${a.location ? ' · ' + LF.esc(a.location) : ''} · ${LF.esc(a.assignee ? a.assignee.name : '')}</div></div>
      <div class="ms-auto">${a.status === 'scheduled' ? `<select class="form-select form-select-sm" data-appt="${a.id}" aria-label="Appointment status"><option value="scheduled" selected>Scheduled</option><option value="completed">Completed</option><option value="no_show">No-show</option><option value="cancelled">Cancelled</option></select>` : `<span class="badge text-bg-secondary">${LF.esc(LF.fmt.label(a.status))}</span>`}</div></li>`).join('') + '</ul>';
  }

  function loadTimeline(append) {
    LF.api('GET', `leads/${leadId}/timeline`, { page: tlPage, per_page: 30 }).then(r => {
      const items = r.data.map(a => {
        const [icon, cls] = TL_ICONS[a.type] || ['dot', ''];
        return `<li><span class="tl-icon ${cls}"><i class="bi bi-${icon}"></i></span><div>${tlText(a)}</div>
          <div class="tl-meta">${LF.esc(a.user ? a.user.name : 'System')} · <span title="${LF.esc(LF.fmt.dateTime(a.occurred_at))}">${LF.fmt.rel(a.occurred_at)}</span>${a.lead_id ? '' : ' · contact-level'}</div>
          ${a.body ? `<div class="tl-body">${LF.esc(a.body)}</div>` : ''}</li>`;
      }).join('');
      if (append) $('#timeline').append(items); else $('#timeline').html(items || '<li class="text-muted small">No activity yet.</li>');
      $('#tlMore').toggleClass('hidden', r.meta.page >= r.meta.last_page);
    });
  }

  function load() {
    return $.when(LF.lookups(), LF.api('GET', 'leads/' + leadId)).then((lk, r) => { LK = lk; D = r.data; render(); });
  }

  // ---------- events ----------
  $(document).on('change', '#d-status', function () {
    const s = LK.statuses.find(x => String(x.id) === this.value);
    const prev = D.lead.status.id;
    const go = reason => LF.api('POST', `leads/${leadId}/status`, { lead_status_id: s.id, reason: reason || null }).then(() => { LF.toast('Stage changed to ' + s.name); load(); }, () => $('#d-status').val(prev));
    if (s.requires_reason !== 'none' || s.category !== 'open') {
      LF.confirm({ title: 'Move to ' + s.name, body: s.semantic === 'do_not_contact' ? 'This opts the contact out of all channels and cancels pending follow-ups.' : 'This closes the lead and cancels pending follow-ups.',
        reason: s.requires_reason !== 'none' || s.category === 'won', reasonRequired: s.requires_reason !== 'none', reasonLabel: s.requires_reason === 'opt_out_reason' ? 'Opt-out reason' : (s.category === 'won' ? 'Note (optional)' : 'Lost reason'), danger: s.category === 'lost', confirmText: 'Move' })
        .then(go, () => $('#d-status').val(prev));
    } else go();
  });
  $(document).on('change', '#d-priority', function () { LF.api('PATCH', 'leads/' + leadId, { priority: this.value }).then(() => { LF.toast('Priority updated'); load(); }); });
  $(document).on('change', '#d-assignee', function () { LF.api('POST', `leads/${leadId}/assign`, { assigned_user_id: this.value ? parseInt(this.value, 10) : null }).then(() => { LF.toast('Assignment updated'); load(); }, load); });

  $(document).on('click', '[data-act]', function () {
    const act = $(this).data('act'), l = D.lead;
    if (act === 'log-call') LF.callModal.open({ leadId, context: l.contact.display_name + (l.contact.phone ? ' · ' + l.contact.phone : ''), onSaved: load });
    if (act === 'follow-up') LF.followUpModal.open({ leadId, onSaved: load });
    if (act === 'appt') LF.apptModal.open({ leadId, onSaved: load });
    if (act === 'archive') LF.confirm({ title: 'Archive lead', body: 'Archived leads are hidden from lists and pending follow-ups are cancelled.', reason: true, reasonRequired: true, danger: true, confirmText: 'Archive' })
      .then(reason => LF.api('POST', `leads/${leadId}/archive`, { reason }).then(() => { LF.toast('Lead archived'); load(); }));
    if (act === 'consent') { $('#consentForm')[0].reset(); LF.clearErrors($('#consentForm')); bootstrap.Modal.getOrCreateInstance(document.getElementById('consentModal')).show(); }
    if (act === 'optout') LF.confirm({ title: 'Opt out of all contact', body: 'Records a withdrawal for every channel, adds a suppression, and cancels pending follow-ups. Use “Record” for a single channel.', reason: true, reasonRequired: true, reasonLabel: 'Reason / how they asked', danger: true, confirmText: 'Opt out' })
      .then(reason => LF.api('POST', `contacts/${l.contact.id}/opt-out`, { channel: 'all', reason }).then(() => { LF.toast('Contact opted out'); load(); }));
    if (act === 'edit') {
      const $f = $('#editForm'); $f[0].reset(); LF.clearErrors($f);
      $('.contact-edit').toggleClass('hidden', !LF.can('contacts.update'));
      LF.fillFromLookups($f).then(() => {
        const c = l.contact;
        $('#e-name').val(c.name); $('#e-company').val(c.company_name); $('#e-phone').val(c.phone_raw || c.phone); $('#e-wa').val(c.whatsapp_raw || c.whatsapp);
        $('#e-email').val(c.email); $('#e-city').val(c.city); $('#e-pref').val(c.preferred_contact_method || ''); $('#e-region').val(c.country_code || LF.country);
        $('#e-source').val(l.source ? l.source.id : ''); $('#e-interest').val(l.product_interest); $('#e-location').val(l.location);
        $('#e-budget').val(l.budget_amount); $('#e-deal').val(l.deal_value); $('#e-srcnotes').val(l.source_notes);
        $('#e-tags').val((l.tags || []).map(t => t.name).join(', '));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
      });
    }
  });

  $('#editForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]'), data = LF.formData($f);
    data.tags = (data.tags_csv || '').split(',').map(s => s.trim()).filter(Boolean); delete data.tags_csv;
    if (!LF.can('contacts.update')) delete data.contact;
    else ['name', 'company_name', 'email', 'city'].forEach(k => { if (data.contact[k] === null) data.contact[k] = ''; });
    LF.busy($btn, true);
    LF.api('PATCH', 'leads/' + leadId, data, { form: $f }).then(() => { bootstrap.Modal.getInstance(document.getElementById('editModal')).hide(); LF.toast('Saved'); load(); }).always(() => LF.busy($btn, false));
  });

  $('#consentForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]');
    LF.busy($btn, true);
    LF.api('POST', `contacts/${D.lead.contact.id}/consents`, LF.formData($f), { form: $f }).then(() => { bootstrap.Modal.getInstance(document.getElementById('consentModal')).hide(); LF.toast('Consent recorded'); load(); }).always(() => LF.busy($btn, false));
  });

  $(document).on('submit', '#noteForm', function (e) {
    e.preventDefault();
    const body = $('#noteBody').val().trim();
    if (!body) return;
    LF.api('POST', `leads/${leadId}/activities`, { body }).then(() => { $('#noteBody').val(''); tlPage = 1; loadTimeline(false); });
  });
  $(document).on('click', '#tlMore', () => { tlPage++; loadTimeline(true); });
  $(document).on('click', '[data-task-log]', function () { LF.callModal.open({ leadId, taskId: $(this).data('task-log'), context: D.lead.contact.display_name, onSaved: load }); });
  $(document).on('click', '[data-task-cancel]', function () {
    const id = $(this).data('task-cancel');
    LF.confirm({ title: 'Cancel follow-up', reason: true, reasonRequired: true, confirmText: 'Cancel follow-up', danger: true }).then(reason => LF.api('POST', `call-tasks/${id}/cancel`, { reason }).then(load));
  });
  $(document).on('change', '[data-appt]', function () {
    LF.api('PATCH', 'appointments/' + $(this).data('appt'), { status: this.value }).then(() => { LF.toast('Appointment updated'); load(); });
  });

  load().fail(err => { if (err && err.status === 404) $('#leadRoot').html(LF.empty('question-circle', 'Lead not found or not shared with you.')); });
})(jQuery, window.LF);
