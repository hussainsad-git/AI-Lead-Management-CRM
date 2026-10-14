(function ($, LF) {
  'use strict';

  const COLUMNS = {
    id: { label: 'ID', sort: 'id', render: l => `<span class="cell-muted">#${l.id}</span>` },
    name: { label: 'Name', sort: 'name', render: l => `<a class="lf-row-link trunc-lg" href="/leads/${l.id}" title="${LF.esc(l.contact.display_name)}">${LF.esc(l.contact.display_name)}</a>${l.contact.do_not_contact ? ' <span class="dnc">DNC</span>' : ''}${l.contact.company_name && l.contact.name ? `<div class="cell-muted trunc">${LF.esc(l.contact.company_name)}</div>` : ''}` },
    phone: { label: 'Phone', render: l => LF.phoneLink(l.contact.phone) },
    whatsapp: { label: 'WhatsApp', render: l => l.contact.whatsapp ? LF.esc(l.contact.whatsapp) : '<span class="cell-muted">—</span>' },
    email: { label: 'Email', render: l => LF.esc(l.contact.email || '') },
    source: { label: 'Source', render: l => LF.esc(l.source ? l.source.name : '—') },
    platform: { label: 'Platform', render: l => LF.esc(l.attribution ? LF.fmt.label(l.attribution.platform) : '') },
    campaign: { label: 'Campaign', render: l => l.attribution && l.attribution.campaign ? `<span class="trunc" title="${LF.esc(l.attribution.campaign)}">${LF.esc(l.attribution.campaign)}</span>` : '<span class="cell-muted">—</span>' },
    ad_set: { label: 'Ad set', render: l => LF.esc(l.attribution ? l.attribution.ad_set || '' : '') },
    ad: { label: 'Ad', render: l => LF.esc(l.attribution ? l.attribution.ad || '' : '') },
    status: { label: 'Status', sort: 'status', render: l => LF.statusBadge(l.status) },
    score: { label: 'Score', sort: 'score', render: l => l.score_label ? `<span class="score-pill score-${LF.esc(l.score_label)}" title="${LF.esc((l.score_factors || []).map(f => f.label + ' ' + (f.points > 0 ? '+' : '') + f.points).join('\n'))}">${LF.esc(LF.t(l.score_label.replace('_', ' ')))} ${l.score ?? ''}</span>` : '<span class="cell-muted">—</span>' },
    priority: { label: 'Priority', sort: 'priority', render: l => LF.priorityBadge(l.priority) },
    assignee: { label: 'Assigned', render: l => l.assignee ? LF.esc(l.assignee.name) : '<span class="cell-muted">Unassigned</span>' },
    attempts: { label: 'Attempts', render: l => `${l.contact_attempts}${l.unanswered_attempts ? ` <span class="cell-muted">(${l.unanswered_attempts} unanswered)</span>` : ''}` },
    last_contacted_at: { label: 'Last contact', sort: 'last_contacted_at', render: l => l.last_contacted_at ? `<span title="${LF.esc(LF.fmt.dateTime(l.last_contacted_at))}">${LF.fmt.rel(l.last_contacted_at)}</span>` : '<span class="cell-muted">Never</span>' },
    next_follow_up_at: { label: 'Next follow-up', sort: 'next_follow_up_at', render: l => l.next_follow_up_at ? `<span class="${new Date(l.next_follow_up_at) < new Date() ? 'overdue' : ''}" title="${LF.esc(LF.fmt.dateTime(l.next_follow_up_at))}">${LF.fmt.rel(l.next_follow_up_at)}</span>` : '<span class="cell-muted">—</span>' },
    created_at: { label: 'Created', sort: 'created_at', render: l => `<span title="${LF.esc(LF.fmt.dateTime(l.created_at))}">${LF.fmt.date(l.created_at)}</span>` },
    last_activity_at: { label: 'Last activity', sort: 'last_activity_at', render: l => LF.fmt.rel(l.last_activity_at) },
    tags: { label: 'Tags', render: l => (l.tags || []).map(t => `<span class="tag">${LF.esc(t.name)}</span>`).join('') },
  };
  const DEFAULT_COLS = ['name', 'phone', 'source', 'campaign', 'status', 'score', 'priority', 'assignee', 'last_contacted_at', 'next_follow_up_at', 'created_at'];

  const state = { page: 1, sort: 'created_at', dir: 'desc', cols: DEFAULT_COLS.slice(), selected: new Set(), allMatching: false, meta: null, rows: [] };
  try { const c = JSON.parse(localStorage.getItem('lf.leads.cols') || 'null'); if (Array.isArray(c) && c.length) state.cols = c.filter(k => COLUMNS[k]); } catch (e) { /* ignore */ }

  const $form = $('#filters');
  $('.export-only').toggleClass('hidden', !LF.can('leads.export'));
  $('.create-only').toggleClass('hidden', !LF.can('leads.create'));
  if (!LF.can('leads.bulk_update')) $('#bulkBar [data-bulk]').not('[data-bulk="export"]').remove();
  if (!LF.can('audiences.manage')) $('#bulkBar [data-bulk="audience_add"]').remove();
  if (!LF.can('leads.assign')) $('[data-bulk="assign"]').remove();
  if (!LF.can('leads.archive')) $('[data-bulk="archive"]').remove();
  if (!LF.can('followups.manage_all')) $('[data-bulk="follow_up"]').remove();
  if (!LF.can('leads.export')) $('[data-bulk="export"]').remove();

  function filters() {
    const raw = LF.formData($form);
    const f = {};
    Object.entries(raw).forEach(([k, v]) => { if (v !== null && v !== '') f[k] = v; });
    if (f.status) {
      if (f.status.startsWith('cat:')) f.status_category = f.status.slice(4); else f.status_ids = [f.status];
      delete f.status;
    }
    ['source_ids', 'assigned_user_ids', 'campaign_ids', 'tag_ids', 'priority', 'score_label'].forEach(k => { if (f[k] && !Array.isArray(f[k])) f[k] = [f[k]]; });
    if (f.archived === 'exclude') delete f.archived;
    return f;
  }

  function syncUrl() {
    const raw = LF.formData($form), p = new URLSearchParams();
    Object.entries(raw).forEach(([k, v]) => { if (v && !(k === 'archived' && v === 'exclude')) p.set(k, v); });
    if (state.sort !== 'created_at' || state.dir !== 'desc') { p.set('sort', state.sort); p.set('dir', state.dir); }
    if (state.page > 1) p.set('page', state.page);
    history.replaceState(null, '', '/leads' + (p.toString() ? '?' + p : ''));
  }

  function renderHead() {
    const th = state.cols.map(k => {
      const c = COLUMNS[k];
      const arrow = c.sort === state.sort ? (state.dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
      return `<th class="${c.sort ? 'sortable' : ''}" ${c.sort ? `data-sort="${c.sort}" tabindex="0" aria-sort="${c.sort === state.sort ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none'}"` : ''}>${LF.esc(c.label)}${arrow}</th>`;
    }).join('');
    $('#leadsTable thead').html(`<tr><th class="text-center" width="36"><input class="form-check-input" type="checkbox" id="selectPage" aria-label="Select all on page"></th>${th}<th></th></tr>`);
  }

  function renderRows() {
    const colspan = state.cols.length + 2;
    if (!state.rows.length) {
      const any = Object.keys(filters()).length;
      $('#leadsTable tbody').html(`<tr><td colspan="${colspan}">${LF.empty('inbox', any ? 'No leads match these filters.' : 'No leads yet. Create one or import your spreadsheet.')}</td></tr>`);
      return;
    }
    $('#leadsTable tbody').html(state.rows.map(l => {
      const wa = LF.waLink(l.contact.whatsapp || l.contact.phone);
      const actions = `<div class="btn-group btn-group-sm">
        ${l.contact.phone && !l.contact.do_not_contact ? `<a class="btn btn-light" href="tel:${LF.esc(l.contact.phone)}" title="Call" aria-label="Call"><i class="bi bi-telephone"></i></a>` : ''}
        ${wa && !l.contact.do_not_contact ? `<a class="btn btn-light" href="${wa}" target="_blank" rel="noopener" title="Open chat in WhatsApp" aria-label="Open in WhatsApp"><i class="bi bi-whatsapp"></i></a>` : ''}
        ${LF.can('followups.manage_own', 'followups.manage_all') && l.status.category === 'open' ? `<button class="btn btn-light" data-log-call="${l.id}" title="Log call" aria-label="Log call"><i class="bi bi-journal-check"></i></button>` : ''}
      </div>`;
      return `<tr><td class="text-center"><input class="form-check-input row-check" type="checkbox" value="${l.id}" ${state.selected.has(l.id) || state.allMatching ? 'checked' : ''} aria-label="Select lead ${l.id}"></td>
        ${state.cols.map(k => `<td>${COLUMNS[k].render(l)}</td>`).join('')}<td class="text-end text-nowrap">${actions}</td></tr>`;
    }).join(''));
  }

  function updateBulkBar() {
    const n = state.allMatching ? (state.meta ? state.meta.total : 0) : state.selected.size;
    $('#bulkBar').toggleClass('show', n > 0);
    $('#bulkCount').text(n + ' selected');
    const pageAll = state.rows.length && state.rows.every(r => state.selected.has(r.id));
    $('#selectPage').prop('checked', state.allMatching || pageAll);
    const showAll = !state.allMatching && pageAll && state.meta && state.meta.total > state.rows.length;
    $('#selectAllMatching').toggleClass('hidden', !showAll && !state.allMatching)
      .text(state.allMatching ? 'Select only this page' : `Select all ${LF.fmt.num(state.meta ? state.meta.total : 0)} matching`);
  }

  function load() {
    $('#leadsTable tbody').html(`<tr><td colspan="${state.cols.length + 2}">${LF.loading()}</td></tr>`);
    const params = Object.assign({}, filters(), { page: state.page, sort: state.sort, dir: state.dir, per_page: 25 });
    syncUrl();
    return LF.api('GET', 'leads', params).then(r => {
      state.rows = r.data; state.meta = r.meta;
      renderRows(); updateBulkBar();
      $('#leadsPager').html(LF.pager(r.meta));
    });
  }

  function renderColumnsMenu() {
    $('#columnsMenu').html('<div class="small-caps mb-1">Visible columns</div>' + Object.entries(COLUMNS).map(([k, c]) =>
      `<div class="form-check"><input class="form-check-input col-toggle" type="checkbox" id="col-${k}" value="${k}" ${state.cols.includes(k) ? 'checked' : ''}><label class="form-check-label" for="col-${k}">${LF.esc(c.label)}</label></div>`).join('') +
      '<button class="btn btn-sm btn-link px-0 mt-1" id="colsReset" type="button">Reset to default</button>');
  }

  function loadViews() {
    LF.api('GET', 'views', { module: 'leads' }, { silent: true }).then(r => {
      const items = r.data.map(v => `<div class="d-flex align-items-center"><button class="dropdown-item flex-grow-1" data-view='${LF.esc(JSON.stringify(v))}'>${LF.esc(v.name)}</button><button class="btn btn-sm btn-link text-danger" data-del-view="${v.id}" aria-label="Delete view ${LF.esc(v.name)}"><i class="bi bi-x"></i></button></div>`).join('');
      $('#viewsMenu').html(`${items || '<div class="small text-muted px-2 pb-2">No saved views yet.</div>'}<hr class="my-1"><button class="dropdown-item" id="saveView"><i class="bi bi-plus"></i> Save current filters…</button>`);
    }, () => {});
  }

  function applyRaw(raw) {
    $form[0].reset();
    Object.entries(raw || {}).forEach(([k, v]) => $form.find(`[name="${k}"]`).val(v));
  }

  // ---------- init ----------
  LF.fillFromLookups($form).then(lk => {
    const st = ['<option value="">All statuses</option>', '<option value="cat:open">All open</option>', '<option value="cat:won">Won</option>', '<option value="cat:lost">Lost / closed</option>', '<optgroup label="Stage">']
      .concat(lk.statuses.map(s => `<option value="${s.id}">${LF.esc(s.name)}</option>`)).concat('</optgroup>');
    $('#f-status').html(st.join(''));
    const users = ['<option value="">Anyone</option>', `<option value="${LF.userId}">Me</option>`, '<option value="unassigned">Unassigned</option>']
      .concat(lk.users.filter(u => u.id !== LF.userId).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`));
    $('#f-user').html(users.join(''));
    if (!lk.campaigns.length) $('#f-campaign').closest('div').addClass('hidden');

    const p = new URLSearchParams(window.location.search);
    const raw = {};
    p.forEach((v, k) => { if (!['sort', 'dir', 'page', 'status_category'].includes(k)) raw[k] = v; });
    if (p.get('status_category')) raw.status = 'cat:' + p.get('status_category');
    applyRaw(raw);
    if (p.get('sort')) { state.sort = p.get('sort'); state.dir = p.get('dir') || 'desc'; }
    state.page = parseInt(p.get('page') || '1', 10);
    if (Object.keys(raw).some(k => !['q', 'status', 'source_ids', 'assigned_user_ids', 'priority', 'follow_up'].includes(k))) $('#moreFilters').addClass('show');
    renderHead(); renderColumnsMenu(); loadViews(); load();
  });

  let qTimer;
  $form.on('input', '#f-q', () => { clearTimeout(qTimer); qTimer = setTimeout(() => { state.page = 1; resetSelection(); load(); }, 350); });
  $form.on('change', 'select, input:not(#f-q)', () => { state.page = 1; resetSelection(); load(); });
  $form.on('submit', e => e.preventDefault());
  $('#resetFilters').on('click', () => { applyRaw({}); state.page = 1; resetSelection(); load(); });

  $('#leadsTable').on('click keydown', 'th.sortable', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    const s = $(this).data('sort');
    state.dir = state.sort === s && state.dir === 'desc' ? 'asc' : 'desc';
    state.sort = s; state.page = 1; renderHead(); load();
  });
  $('#leadsPager').on('click', '[data-page]', function () { state.page = $(this).data('page'); load(); window.scrollTo(0, 0); });

  $(document).on('change', '.col-toggle', function () {
    const k = this.value;
    if (this.checked) state.cols = Object.keys(COLUMNS).filter(c => c === k || state.cols.includes(c));
    else if (state.cols.length > 1) state.cols = state.cols.filter(c => c !== k);
    else this.checked = true;
    try { localStorage.setItem('lf.leads.cols', JSON.stringify(state.cols)); } catch (e) { /* ignore */ }
    renderHead(); renderRows(); updateBulkBar();
  });
  $(document).on('click', '#colsReset', () => { state.cols = DEFAULT_COLS.slice(); try { localStorage.removeItem('lf.leads.cols'); } catch (e) { /* ignore */ } renderColumnsMenu(); renderHead(); renderRows(); });

  // ---------- views ----------
  $('#viewsMenu').on('click', '[data-view]', function () {
    const v = JSON.parse($(this).attr('data-view'));
    applyRaw(v.filters.raw || {});
    if (v.columns && v.columns.length) { state.cols = v.columns.filter(k => COLUMNS[k]); renderColumnsMenu(); }
    if (v.filters.sort) { state.sort = v.filters.sort; state.dir = v.filters.dir; }
    state.page = 1; renderHead(); resetSelection(); load();
  });
  $('#viewsMenu').on('click', '[data-del-view]', function (e) {
    e.stopPropagation();
    LF.api('DELETE', 'views/' + $(this).data('del-view')).then(() => { LF.toast('View deleted'); loadViews(); });
  });
  $('#viewsMenu').on('click', '#saveView', () => {
    LF.confirm({ title: 'Save view', body: 'Saves the current filters, sort and columns for you.', reason: true, reasonRequired: true, reasonLabel: 'View name', confirmText: 'Save' }).then(name => {
      LF.api('POST', 'views', { module: 'leads', name: name.slice(0, 80), filters: { raw: LF.formData($form), sort: state.sort, dir: state.dir }, columns: state.cols })
        .then(() => { LF.toast('View saved'); loadViews(); });
    });
  });

  // ---------- selection ----------
  function resetSelection() { state.selected.clear(); state.allMatching = false; updateBulkBar(); }
  $('#leadsTable').on('change', '.row-check', function () {
    const id = parseInt(this.value, 10);
    if (state.allMatching) { state.allMatching = false; state.rows.forEach(r => state.selected.add(r.id)); }
    this.checked ? state.selected.add(id) : state.selected.delete(id);
    updateBulkBar();
  });
  $('#leadsTable').on('change', '#selectPage', function () {
    state.allMatching = false;
    state.rows.forEach(r => this.checked ? state.selected.add(r.id) : state.selected.delete(r.id));
    $('.row-check').prop('checked', this.checked); updateBulkBar();
  });
  $('#selectAllMatching').on('click', () => { state.allMatching = !state.allMatching; if (!state.allMatching) state.selected.clear(); state.rows.forEach(r => state.selected.add(r.id)); renderRows(); updateBulkBar(); });
  $('#clearSelection').on('click', () => { resetSelection(); renderRows(); });

  function target() { return state.allMatching ? { filters: filters() } : { ids: Array.from(state.selected) }; }

  // ---------- bulk ----------
  let bulkAction = null;
  $('#bulkBar').on('click', '[data-bulk]', function () {
    bulkAction = $(this).data('bulk');
    if (bulkAction === 'export') return doExport('csv', true);
    LF.lookups().then(lk => {
      const n = state.allMatching ? state.meta.total : state.selected.size;
      let html = `<p class="small text-muted mb-2">Applies to <strong>${LF.fmt.num(n)}</strong> lead${n === 1 ? '' : 's'} you can access. Leads that cannot be updated are reported individually.</p>`;
      const titles = { assign: 'Assign leads', status: 'Change status', priority: 'Set priority', follow_up: 'Schedule follow-ups', tags_add: 'Add tags', archive: 'Archive leads', audience_add: 'Add to audience segment' };
      if (bulkAction === 'assign') {
        const opts = lk.users.filter(u => u.accepts_assignments).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`).join('');
        html += `<label class="form-label" for="b-user">Assign to</label><select class="form-select" id="b-user" name="assigned_user_id"><option value="">Unassign</option>${opts}</select>
          <label class="form-label mt-2" for="b-reason">Note</label><input class="form-control" id="b-reason" name="reason" maxlength="255">`;
      } else if (bulkAction === 'status') {
        html += `<label class="form-label" for="b-status">New status</label><select class="form-select" id="b-status" name="lead_status_id">${lk.statuses.filter(s => s.is_active).map(s => `<option value="${s.id}" data-reason="${s.requires_reason}" data-cat="${s.category}">${LF.esc(s.name)}</option>`).join('')}</select>
          <label class="form-label mt-2" for="b-reason">Reason <span class="small text-muted">(required for Lost / Do Not Contact)</span></label><input class="form-control" id="b-reason" name="reason" maxlength="255">
          <div class="form-check mt-2 hidden" id="b-confirm-wrap"><input class="form-check-input" type="checkbox" id="b-confirm" name="confirm" value="1"><label class="form-check-label" for="b-confirm">I understand this closes the selected leads and cancels their pending follow-ups.</label></div>`;
      } else if (bulkAction === 'priority') {
        html += `<label class="form-label" for="b-prio">Priority</label><select class="form-select" id="b-prio" name="priority"><option value="urgent">Urgent</option><option value="high">High</option><option value="normal" selected>Normal</option><option value="low">Low</option></select>`;
      } else if (bulkAction === 'follow_up') {
        html += `<label class="form-label" for="b-at">When</label><input class="form-control" type="datetime-local" id="b-at" name="scheduled_at" value="${LF.tomorrowAt(11)}">
          <label class="form-label mt-2" for="b-reason">Purpose</label><input class="form-control" id="b-reason" name="reason" value="Follow-up call" maxlength="191">
          <div class="form-text">Opted-out or closed leads are skipped and listed.</div>`;
      } else if (bulkAction === 'tags_add') {
        html += `<label class="form-label" for="b-tags">Tags</label><input class="form-control" id="b-tags" name="tags_csv" placeholder="Comma separated">`;
      } else if (bulkAction === 'audience_add') {
        html += `<label class="form-label" for="b-seg">Segment</label><select class="form-select" id="b-seg" name="audience_segment_id"><option>Loading…</option></select>
          <div class="form-text">Added leads stay in the segment even if its rules don't match them. Opted-out people, consent failures and exclusion lists still apply before any export or upload.</div>`;
        LF.api('GET', 'audiences').then(r => $('#b-seg').html(r.data.filter(s => s.kind === 'audience').map(s => `<option value="${s.id}">${LF.esc(s.name)}</option>`).join('') || '<option value="">No segments yet</option>'));
      } else if (bulkAction === 'archive') {
        html += `<div class="alert alert-warning py-2 small">Archived leads are hidden from lists and their pending follow-ups are cancelled. Archiving is not deletion; retention and legal erasure are handled separately.</div>
          <label class="form-label" for="b-reason">Reason</label><input class="form-control" id="b-reason" name="reason" maxlength="255" required>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="b-confirm" name="confirm" value="1"><label class="form-check-label" for="b-confirm">Archive ${LF.fmt.num(n)} lead${n === 1 ? '' : 's'}</label></div>`;
      }
      $('#bulkModalTitle').text(titles[bulkAction]);
      $('#bulkBody').html(html);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkModal')).show();
    });
  });
  $(document).on('change', '#b-status', function () { $('#b-confirm-wrap').toggleClass('hidden', $(this).find(':selected').data('cat') === 'open'); });

  $('#bulkForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]');
    const data = Object.assign({ action: bulkAction }, LF.formData($f), target());
    if (data.tags_csv !== undefined) { data.tags = (data.tags_csv || '').split(',').map(s => s.trim()).filter(Boolean); delete data.tags_csv; }
    data.confirm = !!data.confirm;
    LF.busy($btn, true);
    LF.api('POST', 'leads/bulk', data, { form: $f }).then(r => {
      bootstrap.Modal.getInstance(document.getElementById('bulkModal')).hide();
      const d = r.data;
      LF.toast(`${d.updated} of ${d.matched} updated` + (d.failed_count ? `, ${d.failed_count} skipped` : ''), d.failed_count ? 'warning' : 'success');
      if (d.note) LF.toast(d.note, 'warning');
      if (d.failed_count) {
        LF.confirm({ title: 'Some leads were not updated', html: '<ul class="small mb-0">' + d.failed.map(f => `<li><a href="/leads/${f.id}">#${f.id}</a>: ${LF.esc(f.message)}</li>`).join('') + '</ul>', confirmText: 'OK' }).catch(() => {});
      }
      resetSelection(); load();
    }).always(() => LF.busy($btn, false));
  });

  function doExport(format, selectionOnly) {
    const payload = { format, columns: Object.keys(COLUMNS).filter(c => state.cols.includes(c)).map(c => c === 'attempts' ? 'contact_attempts' : c) };
    if (selectionOnly && !state.allMatching) payload.ids = Array.from(state.selected); else payload.filters = filters();
    LF.toast('Preparing export…');
    LF.download('leads/export', payload);
  }
  $('[data-export]').on('click', function () { doExport($(this).data('export'), false); });

  $(document).on('click', '[data-log-call]', function () {
    const id = $(this).data('log-call');
    const l = state.rows.find(r => r.id === id);
    LF.callModal.open({ leadId: id, context: l ? l.contact.display_name + ' · ' + (l.contact.phone || '') : '', onSaved: load });
  });
  LF.leadModal.onSaved = null;
})(jQuery, window.LF);
