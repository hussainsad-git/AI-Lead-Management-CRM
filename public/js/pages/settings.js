(function ($, LF) {
  'use strict';
  let S = null, R = null, itemKind = null, editingItem = null;
  const COLORS = ['primary', 'info', 'teal', 'indigo', 'purple', 'success', 'warning', 'danger', 'secondary', 'dark'];
  const canSettings = LF.can('settings.manage'), canRoles = LF.can('roles.manage');
  $('.settings-only').toggleClass('hidden', !canSettings);
  $('.roles-only').toggleClass('hidden', !(canRoles || LF.can('users.manage')));
  if (!canSettings) { const el = document.getElementById('rolesTab'); if (el) bootstrap.Tab.getOrCreateInstance(el).show(); }

  function loadSettings() {
    if (!canSettings) return;
    LF.api('GET', 'settings').then(r => {
      S = r.data;
      const o = S.organization, st = S.settings;
      $('#o-name').val(o.name); $('#o-cur').val(o.currency);
      $('#o-tz').html(S.timezones.map(t => `<option ${t === o.timezone ? 'selected' : ''}>${LF.esc(t)}</option>`).join(''));
      LF.fillFromLookups($('#orgForm')).then(() => $('#o-country').val(o.default_country));
      $('#o-ident').val(st.required_identifier); $('#o-sla').val(st.first_contact_sla_minutes); $('#o-dup').prop('checked', !!st.allow_duplicate_open_leads);
      $('#o-ret-archive').val(st.retention_archive_after_days || ''); $('#o-ret-wh').val(st.retention_purge_webhooks_days); $('#o-ret-notif').val(st.retention_purge_notifications_days); $('#o-ret-ai').val(st.retention_purge_ai_days); $('#o-ret-auto').val(st.retention_purge_automation_days);
      renderStatuses(); renderSources(); renderOutcomes(); renderBusiness();
    });
  }

  function renderStatuses() {
    $('#statusBody').html(S.statuses.map(s => `<tr data-id="${s.id}"><td class="cursor-pointer text-muted" aria-label="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>
      <td><input class="form-control form-control-sm" value="${LF.esc(s.name)}" data-st="name" aria-label="Stage name"></td>
      <td><select class="form-select form-select-sm" data-st="semantic" aria-label="Meaning">${S.semantics.map(x => `<option value="${x}" ${x === s.semantic ? 'selected' : ''}>${LF.fmt.label(x)}</option>`).join('')}</select></td>
      <td>${LF.esc(s.category)}</td>
      <td><select class="form-select form-select-sm" data-st="color" aria-label="Colour">${COLORS.map(c => `<option ${c === s.color ? 'selected' : ''}>${c}</option>`).join('')}</select></td>
      <td><select class="form-select form-select-sm" data-st="requires_reason" aria-label="Reason required" ${s.semantic === 'do_not_contact' ? 'disabled' : ''}><option value="none" ${s.requires_reason === 'none' ? 'selected' : ''}>No</option><option value="lost_reason" ${s.requires_reason === 'lost_reason' ? 'selected' : ''}>Lost reason</option><option value="opt_out_reason" ${s.requires_reason === 'opt_out_reason' ? 'selected' : ''}>Opt-out reason</option></select></td>
      <td class="text-end">${s.leads_count}</td>
      <td><input class="form-check-input" type="radio" name="defaultStatus" data-st="is_default" ${s.is_default ? 'checked' : ''} ${s.category !== 'open' ? 'disabled' : ''} aria-label="Default stage"></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-st="is_active" ${s.is_active ? 'checked' : ''} aria-label="Active"></div></td></tr>`).join(''));
    new Sortable(document.getElementById('statusBody'), { handle: '.bi-grip-vertical', animation: 120, onEnd: () => {
      LF.api('POST', 'settings/statuses/reorder', { ids: $('#statusBody tr').map((i, el) => $(el).data('id')).get() }).then(() => { LF.toast('Order saved'); LF.lookups(true); });
    } });
  }
  $('#statusBody').on('change', '[data-st]', function () {
    const id = $(this).closest('tr').data('id'), key = $(this).data('st');
    const val = this.type === 'checkbox' || this.type === 'radio' ? this.checked : this.value;
    LF.api('PATCH', 'settings/statuses/' + id, { [key]: val }).then(() => { LF.toast('Stage updated'); LF.lookups(true); loadSettings(); }, () => loadSettings());
  });

  function renderSources() {
    $('#sourceBody').html(S.sources.map(s => `<tr data-id="${s.id}"><td><input class="form-control form-control-sm" value="${LF.esc(s.name)}" data-src="name" aria-label="Source name"></td>
      <td><select class="form-select form-select-sm" data-src="platform" aria-label="Platform"><option value="">—</option>${['facebook', 'instagram', 'meta', 'whatsapp', 'web', 'offline', 'import', 'other'].map(p => `<option ${p === s.platform ? 'selected' : ''}>${p}</option>`).join('')}</select></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-src="is_paid" ${s.is_paid ? 'checked' : ''} aria-label="Paid"></div></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-src="is_active" ${s.is_active ? 'checked' : ''} aria-label="Active"></div></td></tr>`).join(''));
  }
  $('#sourceBody').on('change', '[data-src]', function () {
    const id = $(this).closest('tr').data('id'), key = $(this).data('src');
    LF.api('PATCH', 'settings/sources/' + id, { [key]: this.type === 'checkbox' ? this.checked : (this.value || null) }).then(() => { LF.toast('Source updated'); LF.lookups(true); });
  });

  function renderOutcomes() {
    const sems = '<option value="">Don\'t move</option>' + S.semantics.filter(x => x !== 'custom').map(x => `<option value="${x}">${LF.fmt.label(x)}</option>`).join('');
    $('#outcomeBody').html(S.outcomes.map(o => `<tr data-id="${o.id}"><td><input class="form-control form-control-sm" value="${LF.esc(o.name)}" data-oc="name" aria-label="Outcome name"></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-oc="is_connected" ${o.is_connected ? 'checked' : ''} aria-label="Someone answered"></div></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-oc="creates_callback" ${o.creates_callback ? 'checked' : ''} aria-label="Creates callback"></div></td>
      <td><select class="form-select form-select-sm" data-oc="advances_to_semantic" aria-label="Moves lead to">${sems}</select></td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-oc="is_active" ${o.is_active ? 'checked' : ''} aria-label="Active"></div></td></tr>`).join(''));
    S.outcomes.forEach(o => $(`#outcomeBody tr[data-id="${o.id}"] [data-oc="advances_to_semantic"]`).val(o.advances_to_semantic || ''));
  }
  $('#outcomeBody').on('change', '[data-oc]', function () {
    const id = $(this).closest('tr').data('id'), key = $(this).data('oc');
    LF.api('PATCH', 'settings/outcomes/' + id, { [key]: this.type === 'checkbox' ? this.checked : (this.value || null) }).then(() => { LF.toast('Outcome updated'); LF.lookups(true); loadSettings(); }, () => loadSettings());
  });

  const WLABEL = { recent_inbound: 'Messaged in last 48h', explicit_interest: 'Explicit interest', medium_interest: 'Some interest', budget_stated: 'Budget stated',
    callback_requested: 'Callback requested', appointment_booked: 'Appointment booked', repeated_engagement: '3+ messages', stale_14d: 'Quiet > 14 days', stale_30d: 'Quiet > 30 days',
    unanswered_3: '3+ unanswered calls', unanswered_5: '5+ unanswered calls' };
  function renderBusiness() {
    const o = S.organization;
    $('#b-ind').html(S.industries.map(i => `<option value="${i.key}" ${i.key === o.industry ? 'selected' : ''}>${LF.esc(i.label)}</option>`).join(''));
    $('#b-locale').html(Object.entries(S.locales).map(([k, v]) => `<option value="${k}" ${k === o.default_locale ? 'selected' : ''}>${LF.esc(v.native)}</option>`).join(''));
    $('#b-color').val(o.brand_color || '#2563eb').data('set', !!o.brand_color);
    const days = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };
    $('#b-hours').html(Object.entries(days).map(([d, label]) => { const h = S.business_hours[d];
      return `<span class="small">${LF.t(label)}</span><input class="form-control form-control-sm" type="time" data-h="${d}" data-i="0" value="${h ? h[0] : ''}" ${h ? '' : 'disabled'} aria-label="${label} open">
        <input class="form-control form-control-sm" type="time" data-h="${d}" data-i="1" value="${h ? h[1] : ''}" ${h ? '' : 'disabled'} aria-label="${label} close">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" data-closed="${d}" id="cl-${d}" ${h ? '' : 'checked'}><label class="form-check-label small" for="cl-${d}">${LF.t('closed')}</label></div>`; }).join(''));
    const aa = S.auto_assign || {};
    $('#aa-on').prop('checked', !!aa.enabled);
    $('#aa-team').html(`<option value="">${LF.t('Any team')}</option>` + S.teams.map(x => `<option value="${x.id}" ${x.id === aa.team_id ? 'selected' : ''}>${LF.esc(x.name)}</option>`).join(''));
    $('#aa-users').html(S.users.map(u => `<div class="form-check"><input class="form-check-input" type="checkbox" data-aa="${u.id}" id="aa-${u.id}" ${(aa.user_ids || []).includes(u.id) ? 'checked' : ''}><label class="form-check-label small" for="aa-${u.id}">${LF.esc(u.name)}</label></div>`).join(''));
    $('#b-weights').html(Object.entries(S.scoring_weights).map(([k, v]) => `<div class="col-6 col-md-3 col-xl-2"><label class="form-label small" for="w-${k}">${LF.esc(LF.t(WLABEL[k] || k))}</label>
      <input class="form-control form-control-sm" type="number" min="-50" max="50" id="w-${k}" data-w="${k}" value="${v}" placeholder="${S.scoring_defaults[k]}"></div>`).join(''));
  }
  $(document).on('change', '[data-closed]', function () { $(`[data-h="${$(this).data('closed')}"]`).prop('disabled', this.checked).val(this.checked ? '' : '09:00'); });
  $('#b-color').on('input', function () { $(this).data('set', true); });
  $('#b-color-reset').on('click', () => $('#b-color').val('#2563eb').data('set', false));
  $('#b-logo').on('change', function () {
    const f = this.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('logo', f);
    LF.api('POST', 'settings/logo', fd).then(() => { LF.toast(LF.t('Logo updated')); setTimeout(() => location.reload(), 600); });
  });
  $('#bizForm').on('submit', function (e) {
    e.preventDefault();
    const hours = {};
    ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].forEach(d => {
      hours[d] = $(`[data-closed="${d}"]`).is(':checked') ? null : [$(`[data-h="${d}"][data-i="0"]`).val(), $(`[data-h="${d}"][data-i="1"]`).val()];
    });
    const weights = {}; $('[data-w]').each(function () { if (this.value !== '') weights[$(this).data('w')] = parseInt(this.value, 10); });
    const data = { industry: $('#b-ind').val(), default_locale: $('#b-locale').val(), brand_color: $('#b-color').data('set') ? $('#b-color').val() : null,
      business_hours: hours, scoring_weights: weights,
      auto_assign: { enabled: $('#aa-on').is(':checked'), team_id: $('#aa-team').val() ? parseInt($('#aa-team').val(), 10) : null, user_ids: $('[data-aa]:checked').map((i, el) => parseInt($(el).data('aa'), 10)).get() } };
    LF.api('PATCH', 'settings/business', data, { form: $(this) }).then(() => { LF.toast(LF.t('Business settings saved')); setTimeout(() => location.reload(), 500); });
  });

  $('#orgForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), d = LF.formData($f);
    d.settings.allow_duplicate_open_leads = $('#o-dup').is(':checked');
    d.settings.first_contact_sla_minutes = parseInt(d.settings.first_contact_sla_minutes, 10);
    ['retention_archive_after_days', 'retention_purge_webhooks_days', 'retention_purge_notifications_days', 'retention_purge_ai_days', 'retention_purge_automation_days'].forEach(k => { d.settings[k] = d.settings[k] === '' || d.settings[k] === null ? null : parseInt(d.settings[k], 10); });
    LF.api('PATCH', 'settings/organization', d, { form: $f }).then(() => { LF.toast('Organization settings saved'); LF.lookups(true); });
  });

  function openItem(kind) {
    itemKind = kind;
    const body = {
      status: `<label class="form-label" for="i-name">Name</label><input class="form-control mb-2" id="i-name" name="name" maxlength="120" required>
        <label class="form-label" for="i-sem">Meaning</label><select class="form-select" id="i-sem" name="semantic">${S.semantics.map(x => `<option value="${x}" ${x === 'custom' ? 'selected' : ''}>${LF.fmt.label(x)}</option>`).join('')}</select>
        <div class="form-text">Use "Custom" for an extra open step; closing meanings (Won, Lost, Not interested, Do not contact) close the lead.</div>`,
      source: `<label class="form-label" for="i-name">Name</label><input class="form-control mb-2" id="i-name" name="name" maxlength="120" required>
        <label class="form-label" for="i-plat">Platform</label><select class="form-select" id="i-plat" name="platform"><option value="">—</option>${['facebook', 'instagram', 'meta', 'whatsapp', 'web', 'offline', 'other'].map(p => `<option>${p}</option>`).join('')}</select>`,
      outcome: `<label class="form-label" for="i-name">Name</label><input class="form-control mb-2" id="i-name" name="name" maxlength="120" required>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="i-conn" name="is_connected" value="1"><label class="form-check-label" for="i-conn">Someone answered (a conversation happened)</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" id="i-cb" name="creates_callback" value="1"><label class="form-check-label" for="i-cb">Requires a callback time</label></div>`,
    }[kind];
    $('#itemModalTitle').text('Add ' + kind); $('#itemBody').html(body); LF.clearErrors($('#itemForm'));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('itemModal')).show();
  }
  $('#addStatus').on('click', () => openItem('status')); $('#addSource').on('click', () => openItem('source')); $('#addOutcome').on('click', () => openItem('outcome'));
  $('#itemForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), d = LF.formData($f);
    if (itemKind === 'outcome') { d.is_connected = !!d.is_connected; d.creates_callback = !!d.creates_callback; }
    LF.api('POST', 'settings/' + { status: 'statuses', source: 'sources', outcome: 'outcomes' }[itemKind], d, { form: $f })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide(); LF.toast('Added'); LF.lookups(true); loadSettings(); });
  });

  // ---------- roles ----------
  function loadRoles() {
    LF.api('GET', 'roles').then(r => {
      R = r.data;
      const groups = {};
      R.permissions.forEach(p => (groups[p.group] = groups[p.group] || []).push(p));
      const head = `<thead><tr><th>Permission</th>${R.roles.map(x => `<th class="rot">${LF.esc(x.name)}</th>`).join('')}</tr></thead>`;
      const rows = Object.entries(groups).map(([g, ps]) => `<tr><td colspan="${R.roles.length + 1}" class="small-caps bg-light">${LF.esc(g)}</td></tr>` + ps.map(p => `<tr><td><code class="small">${LF.esc(p.key)}</code><div class="small text-muted">${LF.esc(p.description)}</div></td>
        ${R.roles.map(x => `<td><input class="form-check-input" type="checkbox" data-role="${x.id}" value="${LF.esc(p.key)}" ${x.permissions.includes(p.key) || x.locked ? 'checked' : ''} ${x.locked || !canRoles ? 'disabled' : ''} aria-label="${LF.esc(x.name + ': ' + p.key)}"></td>`).join('')}</tr>`).join('')).join('');
      $('#permMatrix').html(head + '<tbody>' + rows + '</tbody>');
      $('#savePerms').toggleClass('hidden', !canRoles);
    });
  }
  $('#savePerms').on('click', function () {
    const changed = R.roles.filter(x => !x.locked).map(x => ({ role: x, perms: $(`[data-role="${x.id}"]:checked`).map((i, el) => el.value).get() }))
      .filter(c => c.perms.slice().sort().join() !== c.role.permissions.slice().sort().join());
    if (!changed.length) { LF.toast('No changes', 'warning'); return; }
    LF.confirm({ title: 'Save permission changes', body: `Updates ${changed.length} role(s): ${changed.map(c => c.role.name).join(', ')}. Users' access changes immediately and is audited.`, confirmText: 'Save' })
      .then(() => $.when(...changed.map(c => LF.api('PATCH', 'roles/' + c.role.id, { permissions: c.perms }))).then(() => { LF.toast('Permissions saved'); loadRoles(); }, loadRoles));
  });

  loadSettings();
  if (canRoles || LF.can('users.manage')) loadRoles();
})(jQuery, window.LF);
