(function ($, LF) {
  'use strict';
  const t = LF.t, esc = LF.esc;
  let meta = null, rules = [], editing = null, exPage = 1, testRule = null;

  const PARAM_LABELS = {
    team_id: 'Team', user_id: 'Person', user_ids: 'People', purpose: 'Task title', in_minutes: 'Due in (minutes)',
    priority: 'Priority', message: 'Message', tag: 'Tag', status_id: 'Stage', template_id: 'Template', params: 'Template variables (separate with |)',
  };
  const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
  const triggerOf = k => meta.triggers.find(x => x.key === k) || { label: k };
  const fieldOf = k => meta.fields.find(x => x.key === k);
  const actionOf = k => meta.actions.find(x => x.key === k) || { label: k, params: {} };

  // ---------- summaries ----------
  function condSummary(c) {
    const f = fieldOf(c.field);
    if (!f) return esc(c.field);
    const op = meta.operators[f.type][c.op] || c.op;
    let v = c.value;
    if (f.type === 'enum') v = (v || []).map(x => ((f.options || []).find(o => String(o.value) === String(x)) || { label: x }).label).join(', ');
    if (f.type === 'bool') v = v ? t('yes') : t('no');
    return `${esc(t(f.label))} ${esc(t(op))}${['empty', 'not_empty'].includes(c.op) ? '' : ' <b>' + esc(v) + '</b>'}`;
  }
  function actSummary(a) {
    let extra = '';
    if (a.type === 'assign_user') extra = (meta.users.find(u => u.id === a.user_id) || {}).name;
    if (a.type === 'assign_round_robin' && a.team_id) extra = (meta.teams.find(x => x.id === a.team_id) || {}).name;
    if (a.type === 'create_task') extra = `${a.purpose} · +${a.in_minutes || 0} min`;
    if (a.type === 'set_priority') extra = a.priority;
    if (a.type === 'add_tag') extra = a.tag;
    if (a.type === 'move_stage') extra = (meta.open_statuses.find(s => s.id === a.status_id) || {}).name;
    if (a.type === 'send_whatsapp_template') extra = (meta.templates.find(x => x.id === a.template_id) || { name: '#' + a.template_id }).name;
    return esc(t(actionOf(a.type).label)) + (extra ? ` <span class="text-muted">(${esc(extra)})</span>` : '');
  }

  // ---------- rules list ----------
  function loadRules() {
    return LF.api('GET', 'automation/rules').then(r => {
      rules = r.data;
      $('#exRule').html(`<option value="">${t('All rules')}</option>` + rules.map(x => `<option value="${x.id}">${esc(x.name)}</option>`).join(''));
      if (!rules.length) { $('#ruleBody').html(`<tr><td colspan="8">${LF.empty('lightning-charge', t('No rules yet. Create one to automate assignment, reminders and escalation.'))}</td></tr>`); return; }
      $('#ruleBody').html(rules.map(x => {
        const tr = triggerOf(x.trigger);
        const when = esc(t(tr.label)) + (tr.kind === 'timed' ? ` <b>${x.delay_minutes}</b> ${esc(t('min'))}` : '');
        const conds = (x.conditions.rules || []).map(condSummary).join(x.conditions.match === 'any' ? ` <i>${t('or')}</i> ` : ` <i>${t('and')}</i> `);
        return `<tr>
          <td><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-toggle-rule="${x.id}" ${x.is_active ? 'checked' : ''} aria-label="${esc(t('Active'))}"></div></td>
          <td class="text-wrap"><div class="fw-semibold">${esc(x.name)}</div>${x.description ? `<div class="small text-muted">${esc(x.description)}</div>` : ''}</td>
          <td class="text-wrap small">${when}${conds ? `<div class="text-muted">${t('if')} ${conds}</div>` : ''}</td>
          <td class="text-wrap small">${x.actions.map(actSummary).join('<br>')}</td>
          <td class="text-end">${LF.fmt.num(x.run_count)}</td>
          <td class="text-end">${x.error_count ? `<span class="text-danger">${LF.fmt.num(x.error_count)}</span>` : '0'}</td>
          <td class="small">${x.last_run_at ? LF.fmt.rel(x.last_run_at) : '—'}</td>
          <td class="text-end text-nowrap">
            <button class="btn btn-light btn-sm" data-test="${x.id}" title="${esc(t('Test'))}" aria-label="${esc(t('Test'))}"><i class="bi bi-play-circle"></i></button>
            <button class="btn btn-light btn-sm" data-edit="${x.id}" title="${esc(t('Edit'))}" aria-label="${esc(t('Edit'))}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-light btn-sm" data-del="${x.id}" title="${esc(t('Delete'))}" aria-label="${esc(t('Delete'))}"><i class="bi bi-trash"></i></button>
          </td></tr>`;
      }).join(''));
    });
  }

  $('#ruleBody').on('change', '[data-toggle-rule]', function () {
    const $c = $(this);
    LF.api('PATCH', 'automation/rules/' + $c.data('toggle-rule'), { is_active: this.checked })
      .then(() => { LF.toast(this.checked ? t('Rule enabled') : t('Rule paused')); loadRules(); }, () => $c.prop('checked', !this.checked));
  });
  $('#ruleBody').on('click', '[data-del]', function () {
    const r = rules.find(x => x.id === $(this).data('del'));
    LF.confirm({ title: t('Delete rule?'), body: r.name + ' — ' + t('execution history for this rule is deleted too.'), confirmText: t('Delete'), danger: true })
      .then(() => LF.api('DELETE', 'automation/rules/' + r.id).then(() => { LF.toast(t('Rule deleted')); loadRules(); loadExecutions(); }));
  });
  $('#ruleBody').on('click', '[data-edit]', function () { openEditor(rules.find(x => x.id === $(this).data('edit'))); });
  $('#newRule').on('click', () => openEditor(null));

  // ---------- builder ----------
  const opt = (v, l, sel) => `<option value="${esc(v)}" ${sel ? 'selected' : ''}>${esc(l)}</option>`;

  function valueInput(f, op, value) {
    if (!f) return '';
    if (f.type === 'enum') {
      const vals = (value || []).map(String);
      return `<select class="form-select form-select-sm c-val" multiple size="3" aria-label="${esc(t('Values'))}">${(f.options || []).map(o => opt(o.value, o.label, vals.includes(String(o.value)))).join('')}</select>`;
    }
    if (f.type === 'bool') return `<select class="form-select form-select-sm c-val" aria-label="${esc(t('Value'))}">${opt('true', t('yes'), value !== false)}${opt('false', t('no'), value === false)}</select>`;
    if (f.type === 'number') return `<input type="number" step="any" class="form-control form-control-sm c-val" value="${esc(value ?? '')}" aria-label="${esc(t('Value'))}">`;
    return `<input class="form-control form-control-sm c-val ${['empty', 'not_empty'].includes(op) ? 'hidden' : ''}" maxlength="120" value="${esc(value ?? '')}" aria-label="${esc(t('Value'))}">`;
  }
  function condRow(c) {
    c = c || { field: meta.fields[0].key };
    const f = fieldOf(c.field);
    const ops = meta.operators[f.type];
    const op = c.op || Object.keys(ops)[0];
    return `<div class="row g-2 mb-2 align-items-start cond-row">
      <div class="col-md-4"><select class="form-select form-select-sm c-field" aria-label="${esc(t('Field'))}">${meta.fields.map(x => opt(x.key, t(x.label), x.key === c.field)).join('')}</select></div>
      <div class="col-md-2"><select class="form-select form-select-sm c-op" aria-label="${esc(t('Operator'))}">${Object.entries(ops).map(([k, l]) => opt(k, t(l), k === op)).join('')}</select></div>
      <div class="col-md-5 c-val-wrap">${valueInput(f, op, c.value)}</div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-light btn-sm c-del" aria-label="${esc(t('Remove'))}"><i class="bi bi-x-lg"></i></button></div></div>`;
  }
  function paramInput(name, spec, value) {
    const optional = spec.endsWith('?'), kind = spec.replace('?', '');
    const lbl = `<label class="form-label small mb-0">${esc(t(PARAM_LABELS[name] || name))}${optional ? ' <span class="text-muted">(' + t('optional') + ')</span>' : ''}</label>`;
    let input;
    const sel = (items, blank) => `<select class="form-select form-select-sm a-param" data-p="${name}">${blank !== undefined ? opt('', blank, !value) : ''}${items.map(i => opt(i.id, i.name + (i.language ? ' · ' + i.language : ''), String(i.id) === String(value))).join('')}</select>`;
    switch (kind) {
      case 'team': input = sel(meta.teams, t('Whole assignment pool')); break;
      case 'user': input = sel(meta.users, t('Choose…')); break;
      case 'users': input = `<select class="form-select form-select-sm a-param" data-p="${name}" multiple size="3">${meta.users.map(u => opt(u.id, u.name, (value || []).includes(u.id))).join('')}</select>`; break;
      case 'open_status': input = sel(meta.open_statuses, t('Choose…')); break;
      case 'template': input = meta.templates.length ? sel(meta.templates, t('Choose…')) : `<div class="small text-warning">${esc(t('No approved WhatsApp templates. Sync templates on the Integrations page.'))}</div>`; break;
      case 'priority': input = `<select class="form-select form-select-sm a-param" data-p="${name}">${optional ? opt('', t('Lead priority'), !value) : ''}${PRIORITIES.map(p => opt(p, t(LF.fmt.label(p)), p === value)).join('')}</select>`; break;
      case 'number': input = `<input type="number" min="0" max="43200" class="form-control form-control-sm a-param" data-p="${name}" value="${esc(value ?? 0)}">`; break;
      default: input = `<input class="form-control form-control-sm a-param" data-p="${name}" maxlength="191" value="${esc(value ?? '')}">`;
    }
    return `<div class="col-md">${lbl}${input}</div>`;
  }
  function actRow(a) {
    a = a || { type: meta.actions[0].key };
    const def = actionOf(a.type);
    return `<div class="row g-2 mb-2 align-items-end act-row border-bottom pb-2">
      <div class="col-md-3"><label class="form-label small mb-0">${esc(t('Action'))}</label><select class="form-select form-select-sm a-type">${meta.actions.map(x => opt(x.key, t(x.label), x.key === a.type)).join('')}</select></div>
      ${Object.entries(def.params).map(([n, s]) => paramInput(n, s, a[n])).join('')}
      <div class="col-auto"><button type="button" class="btn btn-light btn-sm a-del" aria-label="${esc(t('Remove'))}"><i class="bi bi-x-lg"></i></button></div></div>`;
  }
  function syncTrigger() {
    const tr = triggerOf($('#r-trigger').val());
    $('#delayWrap').toggleClass('hidden', tr.kind !== 'timed');
    if (tr.kind === 'timed') $('#r-delay-label').text(t('Delay') + ' — ' + t(tr.delay_label || 'minutes'));
  }
  function syncNoCond() { $('#noCond').toggleClass('hidden', $('#condList .cond-row').length > 0); }

  function openEditor(rule) {
    editing = rule;
    const $f = $('#ruleForm');
    LF.clearErrors($f);
    $('#ruleTitle').text(rule ? t('Edit rule') : t('New rule'));
    $('#r-name').val(rule ? rule.name : '');
    $('#r-desc').val(rule ? rule.description || '' : '');
    $('#r-trigger').html(meta.triggers.map(x => opt(x.key, t(x.label), rule && rule.trigger === x.key)).join(''));
    $('#r-delay').val(rule && rule.delay_minutes ? rule.delay_minutes : 60);
    $('#r-match').val(rule ? rule.conditions.match || 'all' : 'all');
    $('#condList').html(((rule && rule.conditions.rules) || []).map(condRow).join(''));
    $('#actList').html(((rule && rule.actions) || [null]).map(actRow).join(''));
    $('#r-active').prop('checked', rule ? rule.is_active : true);
    syncTrigger(); syncNoCond();
    bootstrap.Modal.getOrCreateInstance($('#ruleModal')[0]).show();
  }

  $('#r-trigger').on('change', syncTrigger);
  $('#addCond').on('click', () => { $('#condList').append(condRow()); syncNoCond(); });
  $('#addAct').on('click', () => $('#actList').append(actRow()));
  $('#condList').on('click', '.c-del', function () { $(this).closest('.cond-row').remove(); syncNoCond(); });
  $('#actList').on('click', '.a-del', function () { $(this).closest('.act-row').remove(); });
  $('#condList').on('change', '.c-field', function () { $(this).closest('.cond-row').replaceWith(condRow({ field: this.value })); });
  $('#condList').on('change', '.c-op', function () {
    const $row = $(this).closest('.cond-row');
    $row.find('input.c-val').toggleClass('hidden', ['empty', 'not_empty'].includes(this.value));
  });
  $('#actList').on('change', '.a-type', function () { $(this).closest('.act-row').replaceWith(actRow({ type: this.value })); });

  function collect() {
    const conds = $('#condList .cond-row').map(function () {
      const $r = $(this), field = $r.find('.c-field').val(), f = fieldOf(field), $v = $r.find('.c-val');
      let value = $v.val();
      if (f.type === 'bool') value = value === 'true';
      return { field, op: $r.find('.c-op').val(), value };
    }).get();
    const actions = $('#actList .act-row').map(function () {
      const a = { type: $(this).find('.a-type').val() };
      $(this).find('.a-param').each(function () {
        let v = $(this).val();
        if (Array.isArray(v)) v = v.map(Number);
        else if (['team_id', 'user_id', 'status_id', 'template_id', 'in_minutes'].includes(this.dataset.p)) v = v === '' ? null : Number(v);
        a[this.dataset.p] = v;
      });
      return a;
    }).get();
    return {
      name: $('#r-name').val(), description: $('#r-desc').val(), trigger: $('#r-trigger').val(),
      delay_minutes: Number($('#r-delay').val() || 0), conditions: { match: $('#r-match').val(), rules: conds },
      actions, is_active: $('#r-active').prop('checked'),
    };
  }

  $('#ruleForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $b = $f.find('[type=submit]');
    LF.busy($b, true);
    LF.api(editing ? 'PATCH' : 'POST', 'automation/rules' + (editing ? '/' + editing.id : ''), collect(), { form: $f })
      .then(() => { bootstrap.Modal.getInstance($('#ruleModal')[0]).hide(); LF.toast(t('Rule saved')); loadRules(); })
      .always(() => LF.busy($b, false));
  });

  // ---------- tester ----------
  $('#ruleBody').on('click', '[data-test]', function () {
    testRule = rules.find(x => x.id === $(this).data('test'));
    $('#testTitle').text(t('Test') + ': ' + testRule.name);
    $('#testQ').val(''); $('#testLeads').empty(); $('#testResult').empty();
    bootstrap.Modal.getOrCreateInstance($('#testModal')[0]).show();
  });
  let searchTimer = null;
  $('#testQ').on('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { $('#testLeads').empty(); return; }
    searchTimer = setTimeout(() => LF.api('GET', 'leads', { q, per_page: 8 }, { silent: true }).then(r => {
      $('#testLeads').html(r.data.length ? r.data.map(l => `<button type="button" class="list-group-item list-group-item-action" data-test-lead="${l.id}">#${l.id} · ${esc(l.contact ? l.contact.display_name : '')} <span class="text-muted small">${esc(l.status ? l.status.name : '')}</span></button>`).join('')
        : `<div class="small text-muted">${esc(t('No matching leads.'))}</div>`);
    }), 250);
  });
  $('#testLeads').on('click', '[data-test-lead]', function () {
    $('#testResult').html(LF.loading(t('Evaluating…')));
    LF.api('POST', `automation/rules/${testRule.id}/test`, { lead_id: $(this).data('test-lead') }).then(r => {
      const d = r.data;
      const rows = d.checks.length ? d.checks.map(c => `<tr><td>${c.passed ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>'}</td><td>${condSummary(c)}</td><td class="text-muted">${esc(t('actual'))}: ${esc(c.actual === null ? '—' : (typeof c.actual === 'boolean' ? (c.actual ? t('yes') : t('no')) : c.actual))}</td></tr>`).join('')
        : `<tr><td colspan="3" class="text-muted">${esc(t('No conditions.'))}</td></tr>`;
      $('#testResult').html(`<div class="alert ${d.would_run ? 'alert-success' : 'alert-secondary'} py-2">${d.would_run ? t('This rule would run for') : t('This rule would NOT run for')} <b>${esc(d.lead.name)}</b>.</div>
        <table class="table table-sm small">${rows}</table><div class="small text-muted">${esc(t(d.note))}</div>`);
    }, () => $('#testResult').empty());
  });

  // ---------- executions ----------
  const STATUS_CLS = { success: 'text-bg-success', partial: 'text-bg-warning', failed: 'text-bg-danger', skipped: 'text-bg-secondary', running: 'text-bg-info' };
  function loadExecutions() {
    LF.api('GET', 'automation/executions', { page: exPage, per_page: 20, rule_id: $('#exRule').val() || undefined, status: $('#exStatus').val() || undefined }).then(r => {
      $('#exBody').html(r.data.length ? r.data.map(e => `<tr>
        <td class="small" title="${esc(LF.fmt.dateTime(e.created_at))}">${LF.fmt.rel(e.created_at)}</td>
        <td class="small">${esc(e.rule || '—')}</td>
        <td class="small">${e.lead_id ? `<a href="/leads/${e.lead_id}">${esc(e.lead || '#' + e.lead_id)}</a>` : '—'}</td>
        <td><span class="badge ${STATUS_CLS[e.status] || 'text-bg-light'}">${esc(t(LF.fmt.label(e.status)))}</span></td>
        <td class="small text-wrap">${(e.results || []).map(x => `${x.ok ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>'} ${esc(t(actionOf(x.action).label))}${x.detail ? ': ' + esc(x.detail) : ''}`).join('<br>')}${e.error && !(e.results || []).length ? esc(e.error) : ''}</td></tr>`).join('')
        : `<tr><td colspan="5">${LF.empty('clock-history', t('No executions yet. Rules run as events happen; timed rules are checked every 5 minutes.'))}</td></tr>`);
      $('#exPager').html(LF.pager(r.meta));
    });
  }
  $('#exPager').on('click', '[data-page]', function () { exPage = Number($(this).data('page')); loadExecutions(); });
  $('#exRule, #exStatus').on('change', () => { exPage = 1; loadExecutions(); });
  $('#exReload').on('click', () => { loadRules(); loadExecutions(); });

  LF.api('GET', 'automation/meta').then(r => {
    meta = r.data;
    $('#placeholders').text(t('Placeholders for titles and messages') + ': ' + meta.placeholders.join(' '));
    loadRules(); loadExecutions();
  });
})(jQuery, window.LF);
