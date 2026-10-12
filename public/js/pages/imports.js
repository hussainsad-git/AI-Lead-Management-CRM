(function ($, LF) {
  'use strict';
  const $root = $('#importRoot');
  let job = null, pollTimer = null, rowFilter = 'error', rowPage = 1, waited = 0;
  $('.import-only').toggleClass('hidden', !LF.can('imports.run'));
  $('.export-only').toggleClass('hidden', !LF.can('leads.export'));

  const ACTION_LABEL = { create: 'New contact + lead', new_lead: 'New lead on existing contact', update: 'Fill blanks on existing contact', skip: 'Skip', review: 'Needs review', error: 'Error' };
  const ACTION_CLASS = { create: 'text-bg-success', new_lead: 'text-bg-primary', update: 'text-bg-info', skip: 'text-bg-secondary', review: 'text-bg-warning', error: 'text-bg-danger' };

  function steps(n) { $('#steps .step').each(function () { const s = $(this).data('step'); $(this).toggleClass('active', s === n).toggleClass('done', s < n); }); }

  function setJob(j) {
    job = j;
    history.replaceState(null, '', j ? '/imports?job=' + j.id : '/imports');
    clearTimeout(pollTimer);
    if (!j) return renderUpload();
    if (j.status === 'uploaded') return renderMapping();
    if (j.status === 'validating' || j.status === 'processing') return renderWorking();
    if (j.status === 'validated') {
      if (job && waited) { const pl = j.summary.planned; rowFilter = pl.error ? 'error' : (pl.review ? 'review' : 'create'); }
      waited = 0;
      return renderReview();
    }
    if (!['validating', 'processing'].includes(j.status)) waited = 0;
    return renderDone();
  }

  function refresh() { return LF.api('GET', 'imports/' + job.id).then(r => setJob(r.data)); }

  function renderUpload() {
    steps(1);
    if (!LF.can('imports.run')) { $root.html(''); return; }
    $root.html(`<div class="card"><div class="card-body">
      <form id="uploadForm" class="row g-3 align-items-end">
        <div class="col-md-7"><label class="form-label" for="imp-file">Spreadsheet (.xlsx or .csv)</label><input class="form-control" type="file" id="imp-file" name="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
        <div class="col-md-5"><button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload &amp; preview</button></div>
        <div class="col-12 small text-muted">The first row must contain column headings. Nothing is imported until you review the dry run and confirm. Invalid rows are reported, never silently dropped.</div>
      </form></div></div>`);
  }

  function renderMapping() {
    steps(2);
    LF.lookups().then(lk => {
      const fields = job.fields || [];
      const opts = v => '<option value="">— Ignore column —</option>' + fields.map(f => `<option value="${f.key}" ${f.key === v ? 'selected' : ''}>${LF.esc(f.label)}</option>`).join('');
      const o = job.options || {};
      const rows = job.headers.map((h, i) => `<tr><td><strong>${LF.esc(h)}</strong></td><td class="sample-cell">${(job.sample || []).slice(0, 3).map(r => LF.esc(r[i] || '')).filter(Boolean).join(' · ')}</td>
        <td><select class="form-select form-select-sm" data-col="${i}" aria-label="Map ${LF.esc(h)}">${opts(job.mapping[String(i)] || '')}</select></td></tr>`).join('');
      $root.html(`<div class="card"><div class="card-header d-flex align-items-center">${LF.esc(job.file)} <span class="text-muted fw-normal ms-2">${LF.fmt.num(job.total_rows)} data rows</span>
          ${job.sheets && job.sheets.length > 1 ? `<select class="form-select form-select-sm w-auto ms-auto" id="sheetSel" aria-label="Worksheet">${job.sheets.map(s => `<option ${s === job.sheet ? 'selected' : ''}>${LF.esc(s)}</option>`).join('')}</select>` : ''}</div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label" for="o-dup">When a row matches an existing contact</label><select class="form-select form-select-sm" id="o-dup">
              <option value="review" ${o.duplicate_strategy === 'review' ? 'selected' : ''}>Flag for my review</option>
              <option value="new_lead" ${o.duplicate_strategy === 'new_lead' ? 'selected' : ''}>Add as a new inquiry on that contact</option>
              <option value="update_contact" ${o.duplicate_strategy === 'update_contact' ? 'selected' : ''}>Only fill blank contact fields</option>
              <option value="skip" ${o.duplicate_strategy === 'skip' ? 'selected' : ''}>Skip the row</option></select></div>
            <div class="col-md-2"><label class="form-label" for="o-region">Phone country</label><select class="form-select form-select-sm" id="o-region">${lk.phone_regions.map(r => `<option ${r === o.default_region ? 'selected' : ''}>${r}</option>`).join('')}</select></div>
            <div class="col-md-2"><label class="form-label" for="o-date">Dates are</label><select class="form-select form-select-sm" id="o-date"><option value="dmy" ${o.date_order !== 'mdy' ? 'selected' : ''}>Day/Month/Year</option><option value="mdy" ${o.date_order === 'mdy' ? 'selected' : ''}>Month/Day/Year</option></select></div>
            <div class="col-md-2"><label class="form-label" for="o-src">Default source</label><select class="form-select form-select-sm" id="o-src">${lk.sources.filter(s => s.is_active).map(s => `<option value="${s.id}" ${s.id === o.default_source_id ? 'selected' : ''}>${LF.esc(s.name)}</option>`).join('')}</select></div>
            <div class="col-md-2"><label class="form-label" for="o-user">Default owner</label><select class="form-select form-select-sm" id="o-user"><option value="">Unassigned</option>${(LF.can('leads.assign') ? lk.users : lk.users.filter(u => u.id === LF.userId)).map(u => `<option value="${u.id}" ${u.id === o.default_assignee_id ? 'selected' : ''}>${LF.esc(u.name)}</option>`).join('')}</select></div>
          </div>
          <div class="table-wrap"><table class="table table-sm map-table align-middle"><thead><tr><th>Column in your file</th><th>Sample values</th><th>LeadFlow field</th></tr></thead><tbody>${rows}</tbody></table></div>
          <p class="small text-muted mb-0">Map at least a phone, WhatsApp or email column. Status, source and owner values must match names in LeadFlow (case-insensitive). Call-outcome text like “not answered” or “busy” is recognised.</p>
        </div>
        <div class="card-footer bg-white d-flex gap-2"><button class="btn btn-light" id="cancelJob">Start over</button><button class="btn btn-primary ms-auto" id="validateBtn">Validate (dry run)</button></div></div>`);
    });
  }

  function renderWorking() {
    steps(job.status === 'validating' ? 3 : 4);
    $root.html(`<div class="card"><div class="card-body">${LF.loading(job.status === 'validating' ? `Validating ${LF.fmt.num(job.total_rows)} rows…` : 'Importing rows…')}
      <p class="small text-muted text-center mb-0">This runs in the background; you can leave this page and come back from Import history.</p>
      ${waited > 40 ? '<div class="alert alert-warning small mt-3 mb-0">Still waiting. If this never progresses, the queue worker is not running — ask your administrator to check <code>php artisan queue:work</code> (see the deployment guide).</div>' : ''}</div></div>`);
    waited++;
    pollTimer = setTimeout(refresh, 1500);
  }

  function renderReview() {
    steps(3);
    const p = job.summary.planned;
    const actionable = p.create + p.new_lead + p.update;
    const tiles = Object.keys(ACTION_LABEL).map(k => `<div class="col-6 col-md-4 col-xl-2"><button class="card kpi w-100 text-start border ${rowFilter === k ? 'border-primary' : ''}" data-filter="${k}">
      <div class="kpi-label">${ACTION_LABEL[k]}</div><div class="kpi-value">${LF.fmt.num(p[k])}</div></button></div>`).join('');
    $root.html(`<div class="row g-2 mb-3">${tiles}</div>
      ${p.review ? `<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2"><span><strong>${p.review}</strong> row(s) need a decision. Rows left in review are not imported.</span>
        <div class="ms-auto d-flex gap-1"><button class="btn btn-sm btn-light" data-resolve="new_lead">Add all as new inquiries</button><button class="btn btn-sm btn-light" data-resolve="update">Fill blanks only</button><button class="btn btn-sm btn-light" data-resolve="skip">Skip all</button></div></div>` : ''}
      <div class="card"><div class="card-header d-flex align-items-center">Rows: ${LF.esc(ACTION_LABEL[rowFilter])}<a class="ms-auto small fw-normal" href="/api/v1/imports/${job.id}/report">Download report of rows not imported (CSV)</a></div>
        <div class="table-wrap"><table class="table table-sm align-middle"><thead><tr><th>Row</th><th>Planned</th><th>Messages</th><th>Data</th><th></th></tr></thead><tbody id="rowsBody"></tbody></table></div>
        <div class="card-footer bg-white" id="rowsPager"></div></div>
      <div class="d-flex gap-2 mt-3"><button class="btn btn-light" id="backToMap">Back to mapping</button>
        <button class="btn btn-primary ms-auto" id="commitBtn" ${actionable ? '' : 'disabled'}>Import ${LF.fmt.num(actionable)} row${actionable === 1 ? '' : 's'}</button></div>`);
    loadRows();
  }

  function loadRows() {
    LF.api('GET', `imports/${job.id}/rows`, { action: rowFilter, page: rowPage, per_page: 25 }).then(r => {
      $('#rowsBody').html(r.data.length ? r.data.map(row => {
        const preview = Object.entries(row.raw).filter(([, v]) => v !== '').slice(0, 4).map(([k, v]) => `<span class="text-muted">${LF.esc(k)}:</span> ${LF.esc(v)}`).join(' · ');
        return `<tr><td>${row.row_number}</td><td><span class="badge ${ACTION_CLASS[row.action]}">${LF.esc(ACTION_LABEL[row.action])}</span>${row.result ? `<div class="small">${LF.esc(row.result)}</div>` : ''}</td>
          <td class="small">${row.messages.map(LF.esc).join('<br>')}</td><td class="small">${preview}</td>
          <td class="text-nowrap">${row.action === 'review' ? `<select class="form-select form-select-sm" data-row="${row.id}" aria-label="Decision for row ${row.row_number}"><option value="">Decide…</option>${row.matched_contact_id ? '<option value="new_lead">New inquiry on contact</option><option value="update">Fill blanks only</option>' : ''}<option value="create">Create as separate contact</option><option value="skip">Skip</option></select>` : ''}
          ${row.lead_id ? `<a href="/leads/${row.lead_id}">Lead #${row.lead_id}</a>` : ''}</td></tr>`;
      }).join('') : `<tr><td colspan="5">${LF.empty('check2', 'No rows in this group.')}</td></tr>`);
      $('#rowsPager').html(LF.pager(r.meta));
    });
  }

  function renderDone() {
    steps(5);
    $root.html(`<div class="card"><div class="card-body">
      <h2 class="h5">${job.status === 'completed' ? '<i class="bi bi-check-circle text-success"></i> Import complete' : '<i class="bi bi-exclamation-octagon text-danger"></i> Import stopped'}</h2>
      ${job.failure_message ? `<div class="alert alert-danger">${LF.esc(job.failure_message)}</div>` : ''}
      <div class="row g-2 my-2">
        <div class="col-6 col-md-3"><div class="card kpi"><div class="kpi-label">Created</div><div class="kpi-value">${LF.fmt.num(job.created)}</div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi"><div class="kpi-label">Updated contacts</div><div class="kpi-value">${LF.fmt.num(job.updated)}</div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi"><div class="kpi-label">Skipped / unresolved</div><div class="kpi-value">${LF.fmt.num(job.skipped)}</div></div></div>
        <div class="col-6 col-md-3"><div class="card kpi ${job.errors ? 'kpi-alert' : ''}"><div class="kpi-label">Errors</div><div class="kpi-value">${LF.fmt.num(job.errors)}</div></div></div>
      </div>
      <div class="d-flex gap-2 flex-wrap"><a class="btn btn-light" href="/api/v1/imports/${job.id}/report"><i class="bi bi-download"></i> Report of rows not imported</a>
        <a class="btn btn-light" href="/leads">View leads</a><button class="btn btn-primary ms-auto" id="newImport">Import another file</button></div>
    </div></div>`);
    loadHistory();
  }

  function loadHistory() {
    if (!LF.can('imports.run')) return;
    LF.api('GET', 'imports', null, { silent: true }).then(r => {
      $('#historyBody').html(r.data.length ? r.data.map(j => `<tr><td>${j.id}</td><td>${LF.esc(j.file)}</td><td>${LF.esc(j.user || '')}</td><td><span class="badge text-bg-light border">${LF.esc(j.status)}</span></td>
        <td class="text-end">${j.total_rows}</td><td class="text-end">${j.created}</td><td class="text-end">${j.updated}</td><td class="text-end">${j.skipped}</td><td class="text-end">${j.errors}</td><td class="small">${LF.fmt.dateTime(j.created_at)}</td>
        <td><button class="btn btn-sm btn-link p-0" data-open="${j.id}">Open</button></td></tr>`).join('') : `<tr><td colspan="11">${LF.empty('clock-history', 'No imports yet.')}</td></tr>`);
    });
  }

  // ---------- events ----------
  $root.on('submit', '#uploadForm', function (e) {
    e.preventDefault();
    const file = $('#imp-file')[0].files[0];
    if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    const $btn = $(this).find('[type="submit"]'); LF.busy($btn, true);
    LF.api('POST', 'imports', fd, { form: $(this) }).then(r => { setJob(r.data); loadHistory(); }).always(() => LF.busy($btn, false));
  });
  $root.on('change', '#sheetSel', function () { LF.api('POST', `imports/${job.id}/sheet`, { sheet: this.value }).then(() => refresh()); });
  $root.on('click', '#validateBtn', function () {
    const mapping = {};
    $root.find('[data-col]').each(function () { if (this.value) mapping[$(this).data('col')] = this.value; });
    const options = { duplicate_strategy: $('#o-dup').val(), default_region: $('#o-region').val(), date_order: $('#o-date').val(), default_source_id: parseInt($('#o-src').val(), 10) || null, default_assignee_id: parseInt($('#o-user').val(), 10) || null };
    LF.busy($(this), true);
    LF.api('POST', `imports/${job.id}/configure`, { mapping, options }).then(r => { rowFilter = r.data.summary.planned.error ? 'error' : (r.data.summary.planned.review ? 'review' : 'create'); return refresh(); }, () => LF.busy($('#validateBtn'), false));
  });
  $root.on('click', '[data-filter]', function () { rowFilter = $(this).data('filter'); rowPage = 1; renderReview(); });
  $root.on('click', '#rowsPager [data-page]', function () { rowPage = $(this).data('page'); loadRows(); });
  $root.on('click', '[data-resolve]', function () { LF.api('POST', `imports/${job.id}/resolve`, { action: $(this).data('resolve'), all_review: true }).then(() => refresh()); });
  $root.on('change', '[data-row]', function () { if (this.value) LF.api('POST', `imports/${job.id}/resolve`, { action: this.value, row_ids: [$(this).data('row')] }).then(() => refresh()); });
  $root.on('click', '#backToMap', () => { job.status = 'uploaded'; renderMapping(); });
  $root.on('click', '#cancelJob, #newImport', () => setJob(null));
  $root.on('click', '#commitBtn', function () {
    const p = job.summary.planned;
    LF.confirm({ title: 'Import rows', confirmText: 'Import', html: `<p>Import <strong>${LF.fmt.num(p.create + p.new_lead + p.update)}</strong> rows now?</p><p class="small text-muted mb-0">${p.error} error row(s), ${p.skip} skipped and ${p.review} unresolved row(s) will not be imported and remain in the downloadable report.</p>` })
      .then(() => LF.api('POST', `imports/${job.id}/commit`).then(() => refresh()));
  });
  $('#historyBody').on('click', '[data-open]', function () { LF.api('GET', 'imports/' + $(this).data('open')).then(r => { rowFilter = 'error'; setJob(r.data); window.scrollTo(0, 0); }); });
  $('[data-export-all]').on('click', function () { LF.toast('Preparing export…'); LF.download('leads/export', { format: $(this).data('export-all'), filters: {} }); });

  const qid = new URLSearchParams(window.location.search).get('job');
  if (qid && LF.can('imports.run')) LF.api('GET', 'imports/' + qid).then(r => setJob(r.data), () => setJob(null)); else setJob(null);
  loadHistory();
})(jQuery, window.LF);
