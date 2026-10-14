(function ($, LF) {
  'use strict';
  const t = LF.t, esc = LF.esc;
  let meta = null, segs = [], editing = null;
  const fieldOf = k => meta.fields.find(x => x.key === k);
  const opt = (v, l, sel) => `<option value="${esc(v)}" ${sel ? 'selected' : ''}>${esc(l)}</option>`;

  function ruleSummary(r) {
    const f = fieldOf(r.field);
    if (!f) return esc(r.field);
    const op = meta.operators[f.type][r.op] || r.op;
    let v = r.value;
    if (f.type === 'enum') v = (v || []).map(x => t(((f.options || []).find(o => String(o.value) === String(x)) || { label: x }).label)).join(', ');
    if (f.type === 'bool') v = v ? t('yes') : t('no');
    if (f.type === 'days') v = v + ' ' + t('days');
    return `${esc(t(f.label))} ${esc(t(op))}${['empty', 'not_empty'].includes(r.op) ? '' : ' <b>' + esc(v) + '</b>'}`;
  }
  function defSummary(d) {
    return (d.groups || []).map(g => '(' + g.rules.map(ruleSummary).join(g.match === 'any' ? ` <i>${t('or')}</i> ` : ` <i>${t('and')}</i> `) + ')').join(d.match === 'any' ? ` <i>${t('OR')}</i> ` : ` <i>${t('AND')}</i> `);
  }
  function skippedText(sk) {
    const parts = Object.entries(sk || {}).filter(([, n]) => n > 0).map(([k, n]) => `${n} ${t(meta.reasons[k] || k).toLowerCase()}`);
    return parts.length ? parts.join(' · ') : '—';
  }

  function load() {
    return LF.api('GET', 'audiences').then(r => {
      segs = r.data;
      const aud = segs.filter(s => s.kind === 'audience'), ex = segs.filter(s => s.kind === 'exclusion');
      $('#segBody').html(aud.length ? aud.map(s => `<tr>
        <td class="text-wrap"><a href="#" class="fw-semibold" data-prev="${s.id}">${esc(s.name)}</a>${s.description ? `<div class="small text-muted">${esc(s.description)}</div>` : ''}<div class="small text-muted">${defSummary(s.definition)}</div></td>
        <td class="text-end">${s.last_count === null ? '—' : LF.fmt.num(s.last_count)}</td>
        <td class="text-end fw-semibold">${s.last_eligible === null ? '—' : LF.fmt.num(s.last_eligible)}</td>
        <td class="small text-wrap">${skippedText(s.skipped)}</td>
        <td class="small">${s.meta_audience_id ? `<i class="bi bi-meta text-primary"></i> ${s.last_synced_at ? t('synced') + ' ' + LF.fmt.rel(s.last_synced_at) : t('created')}${s.last_job && s.last_job.status === 'failed' ? ` <span class="text-danger">· ${t('last sync failed')}</span>` : ''}${s.auto_sync ? ' · ' + t('auto') : ''}` : '—'}</td>
        <td class="small">${s.last_counted_at ? LF.fmt.rel(s.last_counted_at) : '—'}</td>
        <td class="text-end text-nowrap">
          <button class="btn btn-light btn-sm" data-refresh="${s.id}" title="${esc(t('Recount'))}" aria-label="${esc(t('Recount'))}"><i class="bi bi-arrow-clockwise"></i></button>
          <button class="btn btn-light btn-sm" data-edit="${s.id}" title="${esc(t('Edit'))}" aria-label="${esc(t('Edit'))}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-light btn-sm" data-del="${s.id}" title="${esc(t('Delete'))}" aria-label="${esc(t('Delete'))}"><i class="bi bi-trash"></i></button></td></tr>`).join('')
        : `<tr><td colspan="7">${LF.empty('diagram-3', t('No segments yet.'))}</td></tr>`);
      $('#exList').html(ex.length ? ex.map(s => `<div class="d-flex align-items-center gap-2 mb-1"><a href="#" data-prev="${s.id}">${esc(s.name)}</a><span class="text-muted ms-auto">${s.last_count === null ? '—' : LF.fmt.num(s.last_count)}</span>
        <button class="btn btn-light btn-sm py-0" data-edit="${s.id}" aria-label="${esc(t('Edit'))}"><i class="bi bi-pencil"></i></button><button class="btn btn-light btn-sm py-0" data-del="${s.id}" aria-label="${esc(t('Delete'))}"><i class="bi bi-trash"></i></button></div>`).join('')
        : `<div class="text-muted">${esc(t('No exclusion lists. Create a segment of type "Exclusion list" to subtract people from audiences.'))}</div>`);
    });
  }

  // ---------- builder ----------
  function valueInput(f, op, value) {
    if (f.type === 'enum') {
      const vals = (value || []).map(String);
      return `<select class="form-select form-select-sm r-val" multiple size="3" aria-label="${esc(t('Values'))}">${(f.options || []).map(o => opt(o.value, t(o.label), vals.includes(String(o.value)))).join('')}</select>`;
    }
    if (f.type === 'bool') return `<select class="form-select form-select-sm r-val" aria-label="${esc(t('Value'))}">${opt('true', t('yes'), value !== false)}${opt('false', t('no'), value === false)}</select>`;
    if (f.type === 'number' || f.type === 'days') return `<div class="input-group input-group-sm"><input type="number" min="0" step="any" class="form-control r-val" value="${esc(value ?? '')}" aria-label="${esc(t('Value'))}">${f.type === 'days' ? `<span class="input-group-text">${t('days')}</span>` : ''}</div>`;
    return `<input class="form-control form-control-sm r-val ${['empty', 'not_empty'].includes(op) ? 'hidden' : ''}" maxlength="120" value="${esc(value ?? '')}" aria-label="${esc(t('Value'))}">`;
  }
  function ruleRow(r) {
    r = r || { field: meta.fields[0].key };
    const f = fieldOf(r.field), ops = meta.operators[f.type], op = r.op || Object.keys(ops)[0];
    return `<div class="row g-2 mb-2 align-items-start rule-row">
      <div class="col-md-4"><select class="form-select form-select-sm r-field" aria-label="${esc(t('Field'))}">${meta.fields.map(x => opt(x.key, t(x.label), x.key === r.field)).join('')}</select></div>
      <div class="col-md-3"><select class="form-select form-select-sm r-op" aria-label="${esc(t('Operator'))}">${Object.entries(ops).map(([k, l]) => opt(k, t(l), k === op)).join('')}</select></div>
      <div class="col-md-4">${valueInput(f, op, r.value)}</div>
      <div class="col-md-1 text-end"><button type="button" class="btn btn-light btn-sm r-del" aria-label="${esc(t('Remove'))}"><i class="bi bi-x-lg"></i></button></div></div>`;
  }
  function groupBox(g) {
    g = g || { match: 'all', rules: [null] };
    return `<div class="border rounded p-2 mb-2 group-box">
      <div class="d-flex align-items-center gap-2 mb-2"><span class="small-caps">${t('Group')}</span>
        <select class="form-select form-select-sm w-auto g-match" aria-label="${esc(t('Match'))}">${opt('all', t('all conditions'), g.match !== 'any')}${opt('any', t('any condition'), g.match === 'any')}</select>
        <button type="button" class="btn btn-light btn-sm ms-auto g-del" aria-label="${esc(t('Remove group'))}"><i class="bi bi-x-lg"></i></button></div>
      <div class="rules">${(g.rules || [null]).map(ruleRow).join('')}</div>
      <button type="button" class="btn btn-light btn-sm g-add"><i class="bi bi-plus"></i> ${t('Add condition')}</button></div>`;
  }
  function openEditor(s) {
    editing = s;
    const $f = $('#segForm'); LF.clearErrors($f);
    $('#segTitle').text(s ? t('Edit segment') : t('New segment'));
    $('#s-name').val(s ? s.name : ''); $('#s-desc').val(s ? s.description || '' : '');
    $('#s-kind').val(s ? s.kind : 'audience');
    $('#s-match').val(s ? s.definition.match || 'all' : 'all');
    $('#groupList').html(((s && s.definition.groups) || [null]).map(groupBox).join(''));
    $('#s-consent').html(Object.entries(meta.consent_policies).map(([k, l]) => opt(k, t(l), (s ? s.consent_policy : 'not_withdrawn') === k)).join(''));
    const excl = s ? s.exclusion_segment_ids : meta.exclusion_segments.filter(e => segs.find(x => x.id === e.id && x.template_key === 'dnc_exclusions')).map(e => e.id);
    $('#s-excl').html(meta.exclusion_segments.filter(e => !s || e.id !== s.id).map(e => opt(e.id, e.name, excl.includes(e.id))).join('') || `<option disabled>${t('No exclusion lists yet')}</option>`);
    $('#s-auto').prop('checked', !!(s && s.auto_sync)).prop('disabled', !meta.meta.connected);
    $('#estimate').text(t('Click "Estimate" to see counts before saving.'));
    syncKind();
    bootstrap.Modal.getOrCreateInstance($('#segModal')[0]).show();
  }
  function syncKind() { $('#audienceOnly').toggleClass('hidden', $('#s-kind').val() === 'exclusion'); }
  $('#s-kind').on('change', syncKind);
  $('#addGroup').on('click', () => $('#groupList').append(groupBox()));
  $('#groupList').on('click', '.g-add', function () { $(this).siblings('.rules').append(ruleRow()); });
  $('#groupList').on('click', '.g-del', function () { $(this).closest('.group-box').remove(); });
  $('#groupList').on('click', '.r-del', function () { $(this).closest('.rule-row').remove(); });
  $('#groupList').on('change', '.r-field', function () { $(this).closest('.rule-row').replaceWith(ruleRow({ field: this.value })); });
  $('#groupList').on('change', '.r-op', function () { $(this).closest('.rule-row').find('input.r-val').toggleClass('hidden', ['empty', 'not_empty'].includes(this.value)); });

  function collect() {
    const groups = $('#groupList .group-box').map(function () {
      const rules = $(this).find('.rule-row').map(function () {
        const $r = $(this), field = $r.find('.r-field').val(), f = fieldOf(field);
        let value = $r.find('.r-val').val();
        if (f.type === 'bool') value = value === 'true';
        return { field, op: $r.find('.r-op').val(), value };
      }).get();
      return { match: $(this).find('.g-match').val(), rules };
    }).get();
    return {
      name: $('#s-name').val(), description: $('#s-desc').val(), kind: $('#s-kind').val(),
      definition: { match: $('#s-match').val(), groups },
      consent_policy: $('#s-consent').val(), exclusion_segment_ids: ($('#s-excl').val() || []).map(Number), auto_sync: $('#s-auto').prop('checked'),
    };
  }
  $('#estBtn').on('click', function () {
    const $b = $(this); LF.busy($b, true);
    const d = collect(); if (!d.name) d.name = 'estimate';
    LF.api('POST', 'audiences/estimate', d, { form: $('#segForm') }).then(r => {
      const e = r.data;
      $('#estimate').html(`<b>${LF.fmt.num(e.members)}</b> ${t('leads match')} · <b>${LF.fmt.num(e.eligible)}</b> ${t('eligible for export / Meta')}${Object.values(e.skipped || {}).some(n => n) ? ` · ${t('left out')}: ${skippedText(e.skipped)}` : ''}`);
    }).always(() => LF.busy($b, false));
  });
  $('#segForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $b = $f.find('[type=submit]'); LF.busy($b, true);
    LF.api(editing ? 'PATCH' : 'POST', 'audiences' + (editing ? '/' + editing.id : ''), collect(), { form: $f })
      .then(() => { bootstrap.Modal.getInstance($('#segModal')[0]).hide(); LF.toast(t('Segment saved')); loadAll(); })
      .always(() => LF.busy($b, false));
  });

  // ---------- list actions ----------
  $('#newSeg').on('click', () => openEditor(null));
  $(document).on('click', '[data-edit]', function () { openEditor(segs.find(s => s.id === $(this).data('edit'))); });
  $(document).on('click', '[data-refresh]', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', `audiences/${$b.data('refresh')}/refresh`).then(r => { LF.toast(`${r.data.members} ${t('members')} · ${r.data.eligible} ${t('eligible')}`); load(); }).always(() => LF.busy($b, false));
  });
  $(document).on('click', '[data-del]', function () {
    const s = segs.find(x => x.id === $(this).data('del'));
    LF.confirm({ title: t('Delete segment?'), body: s.name + (s.meta_audience_id ? ' — ' + t('the Meta audience stays in Ads Manager; delete it there if no longer needed.') : ''), confirmText: t('Delete'), danger: true })
      .then(() => LF.api('DELETE', 'audiences/' + s.id).then(() => { LF.toast(t('Segment deleted')); loadAll(); }));
  });
  $(document).on('click', '[data-prev]', function (e) {
    e.preventDefault();
    const id = $(this).data('prev');
    $('#prevBody').html(LF.loading()); $('#prevActions').empty();
    bootstrap.Modal.getOrCreateInstance($('#prevModal')[0]).show();
    LF.api('GET', 'audiences/' + id).then(r => {
      const s = r.data;
      $('#prevTitle').text(s.name);
      $('#prevBody').html(`<div class="small mb-2">${defSummary(s.definition)}</div>
        <div class="small mb-2"><b>${LF.fmt.num(s.last_count || 0)}</b> ${t('members')} · <b>${LF.fmt.num(s.last_eligible || 0)}</b> ${t('eligible')} · ${t('left out')}: ${skippedText(s.skipped)}</div>
        ${s.preview.length ? `<table class="table table-sm small"><thead><tr><th>${t('Lead')}</th><th>${t('Name')}</th><th>${t('Phone')}</th><th>${t('Email')}</th><th>${t('City')}</th></tr></thead><tbody>${s.preview.map(p => `<tr><td><a href="/leads/${p.lead_id}">#${p.lead_id}</a></td><td>${esc(p.name)}</td><td>${esc(p.phone || '—')}</td><td>${esc(p.email || '—')}</td><td>${esc(p.city || '')}</td></tr>`).join('')}</tbody></table><div class="small text-muted">${t('First rows only; identifiers are masked here.')}</div>` : `<div class="text-muted small">${t('Nobody is eligible right now.')}</div>`}`);
      const acts = [];
      if (meta.can_export) acts.push(`<a class="btn btn-light btn-sm" href="/api/v1/audiences/${s.id}/export"><i class="bi bi-download"></i> ${t('Export CSV')}</a><a class="btn btn-light btn-sm" href="/api/v1/audiences/${s.id}/export?hashed=1"><i class="bi bi-shield-lock"></i> ${t('Meta-ready hashed CSV')}</a>`);
      if (s.kind === 'audience') acts.push(`<button class="btn btn-primary btn-sm ms-auto" type="button" data-sync="${s.id}" ${meta.meta.connected ? '' : 'disabled'}><i class="bi bi-meta"></i> ${s.meta_audience_id ? t('Sync to Meta now') : t('Create Meta audience & sync')}</button>${meta.meta.connected ? '' : `<span class="small text-muted">${t('Connect Meta on Integrations to upload')}</span>`}`);
      $('#prevActions').html(acts.join(''));
    });
  });
  $(document).on('click', '[data-sync]', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', `audiences/${$b.data('sync')}/sync`).then(() => { LF.toast(t('Sync queued — see Retargeting for progress')); bootstrap.Modal.getInstance($('#prevModal')[0]).hide(); load(); }).always(() => LF.busy($b, false));
  });

  function loadAll() { LF.api('GET', 'audiences/meta').then(r => { meta = r.data; load(); }); }
  loadAll();
})(jQuery, window.LF);
