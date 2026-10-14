(function ($, LF) {
  'use strict';
  const t = LF.t;
  const charts = {};
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.font.size = 11; Chart.defaults.color = '#64748b';
  const kpi = (label, value, sub) => `<div class="col-6 col-md-4 col-xl-2"><div class="card kpi"><div class="kpi-label">${LF.esc(label)}</div><div class="kpi-value">${value}</div>${sub ? `<div class="kpi-sub">${sub}</div>` : ''}</div></div>`;
  const na = v => v === null || v === undefined ? '<span class="kpi-unavailable">—</span>' : v;

  function params() {
    const p = { range: $('#anRange').val() };
    if (p.range === 'custom') { p.from = $('#anFrom').val(); p.to = $('#anTo').val(); }
    [['source_id', '#anSource'], ['user_id', '#anUser'], ['campaign_id', '#anCampaign'], ['platform', '#anPlatform']].forEach(([k, s]) => { if ($(s).val()) p[k] = $(s).val(); });
    return p;
  }

  function table(id, cols, rows, money) {
    if (!rows.length) return LF.empty('table', t('No data for this period.'));
    return `<table class="table table-sm table-hover table-dense"><thead><tr>${cols.map(c => `<th class="${c[2] ? 'text-end' : ''}">${LF.esc(c[1])}</th>`).join('')}</tr></thead><tbody>` +
      rows.map(r => `<tr>${cols.map(([k, , num]) => {
        let v = r[k];
        if (money && money.includes(k)) v = v === null ? '<span class="cell-muted">n/a</span>' : LF.fmt.money(v, r.currency);
        else if (k.endsWith('_rate')) v = LF.fmt.pct(v);
        else if (v === null || v === undefined) v = '<span class="cell-muted">—</span>';
        else v = LF.esc(v);
        return `<td class="${num ? 'text-end' : ''}">${k === 'campaign' || k === 'source' || k === 'name' ? `<span class="trunc" title="${LF.esc(r[k])}">${v}</span>` : v}</td>`;
      }).join('')}</tr>`).join('') + '</tbody></table>';
  }

  function render(d) {
    const m = d.messaging, a = d.ai, ob = m.outbound_by || {};
    const fb = a.feedback || {};
    const sc = d.scores || {};
    $('#anBody').html(`
      <div class="small text-muted mb-2">${d.range.from ? LF.esc(d.range.from) + ' → ' + LF.esc(d.range.to) : t('All time')} (${LF.esc(d.range.timezone)})</div>
      <h2 class="h6 mt-2">${t('WhatsApp & AI')}</h2>
      <div class="row g-2 mb-3">
        ${kpi(t('New conversations'), LF.fmt.num(m.new_conversations), `${LF.fmt.num(m.whatsapp_leads)} ${t('leads captured')}`)}
        ${kpi(t('Messages in / out'), `${LF.fmt.num(m.inbound)} / ${LF.fmt.num(m.outbound)}`, m.failed ? `<span class="text-danger">${m.failed} ${t('failed')}</span>` : '')}
        ${kpi(t('Median first response'), na(m.median_first_response_min !== null ? m.median_first_response_min + ' ' + t('min') : null), t('new conversations'))}
        ${kpi(t('AI resolution rate'), na(a.resolution_rate !== null ? a.resolution_rate + '%' : null), `${a.ai_conversations} ${t('conversations with AI replies')}`)}
        ${kpi(t('Awaiting a human'), LF.fmt.num(m.awaiting_human), t('handed off, not closed'))}
        ${kpi(t('AI feedback'), `<span class="text-success">${fb.up || 0}</span> / <span class="text-danger">${(fb.down || 0) + (fb.corrected || 0)}</span>`, t('approved / corrected or rejected'))}
      </div>
      <div class="row g-3 mb-3">
        <div class="col-xl-8"><div class="card h-100"><div class="card-header">${t('Message volume')}</div><div class="card-body"><div class="chart-box"><canvas id="chMsg" role="img" aria-label="${t('Message volume')}"></canvas></div></div></div></div>
        <div class="col-xl-4"><div class="card h-100"><div class="card-header">${t('Who replied')}</div><div class="card-body"><div class="chart-box chart-sm"><canvas id="chWho" role="img" aria-label="${t('Who replied')}"></canvas></div>
          <div class="small text-muted mt-2">${t('AI calls')}: ${LF.fmt.num(a.interactions)} · ${t('errors')}: ${a.errors} · ${t('tokens')}: ${LF.fmt.num(a.tokens)}${a.avg_latency_ms ? ' · ' + a.avg_latency_ms + ' ms' : ''}</div></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header">${t('Most-used knowledge')}</div><div class="card-body">${a.top_documents.length ? '<ul class="list-unstyled small mb-0">' + a.top_documents.map(x => `<li class="d-flex border-bottom py-1"><span dir="auto">${LF.esc(x.title)}</span><span class="ms-auto text-muted">${x.uses}×</span></li>`).join('') + '</ul>' : LF.empty('journal', t('Not used yet.'))}</div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header d-flex">${t('Recent unanswered questions')}<a class="ms-auto small fw-normal" href="/ai-assistant">${t('Review & teach')}</a></div><div class="card-body">${a.unanswered.length ? '<ul class="small mb-0">' + a.unanswered.map(q => `<li dir="auto">${LF.esc(q)}</li>`).join('') + '</ul>' : LF.empty('check2', t('None — the assistant answered everything it was asked.'))}</div></div></div>
      </div>
      <h2 class="h6">${t('Leads')}</h2>
      <div class="row g-2 mb-3">${['hot', 'warm', 'cold', 'needs_review'].map(k => kpi(t(LF.fmt.label(k)) + ' ' + t('leads'), LF.fmt.num(sc[k] || 0), t('open leads, current score'))).join('')}</div>
      ${section('sources', t('Lead source performance'), table('src', [['source', t('Source')], ['leads', t('Leads'), 1], ['contacted', t('Contacted'), 1], ['response_rate', t('Response rate'), 1], ['qualified_rate', t('Qualified rate'), 1], ['converted', t('Won'), 1], ['lost', t('Lost'), 1], ['conversion_rate', t('Conversion'), 1], ['avg_first_contact_h', t('First contact (h)'), 1], ['avg_attempts', t('Avg attempts'), 1], ['revenue', t('Revenue'), 1]], d.sources, ['revenue']))}
      ${section('campaigns', t('Campaign performance'), table('camp', [['campaign', t('Campaign')], ['leads', t('Leads'), 1], ['qualified', t('Qualified'), 1], ['converted', t('Won'), 1], ['conversion_rate', t('Conversion'), 1], ['spend', t('Spend'), 1], ['cpl', 'CPL', 1], ['cpql', 'CPQL', 1], ['cac', 'CAC', 1]], d.campaigns, ['spend', 'cpl', 'cpql', 'cac']))}
      ${section('salespeople', t('Salesperson productivity'), table('sp', [['name', t('Salesperson')], ['leads', t('Leads'), 1], ['contacted', t('Contacted'), 1], ['avg_first_contact_h', t('First contact (h)'), 1], ['calls_logged', t('Calls'), 1], ['whatsapp_sent', t('WhatsApp sent'), 1], ['overdue_follow_ups', t('Overdue'), 1], ['qualified', t('Qualified'), 1], ['converted', t('Won'), 1], ['conversion_rate', t('Conversion'), 1]], d.salespeople))}
      <details class="small text-muted"><summary>${t('How these numbers are calculated')}</summary><ul class="mt-2">${Object.values(d.definitions).map(x => `<li>${LF.esc(x)}</li>`).join('')}<li>${t('Spend comes only from recorded campaign spend; n/a means spend or leads are missing, never zero.')}</li></ul></details>`);

    Object.values(charts).forEach(c => c.destroy());
    if (m.series.labels.length) {
      charts.msg = new Chart(document.getElementById('chMsg'), { type: 'bar', data: { labels: m.series.labels, datasets: [
        { label: t('Inbound'), data: m.series.in, backgroundColor: '#22c55e', borderRadius: 2 }, { label: t('Outbound'), data: m.series.out, backgroundColor: '#2563eb', borderRadius: 2 }] },
        options: { maintainAspectRatio: false, scales: { x: { stacked: false, grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } } });
    } else $('#chMsg').closest('.card-body').html(LF.empty('chat', t('No messages in this period.')));
    const who = [['user', t('Agents')], ['ai', t('AI')], ['auto_reply', t('Auto-replies')], ['system', t('System')]].filter(([k]) => ob[k]);
    if (who.length) {
      charts.who = new Chart(document.getElementById('chWho'), { type: 'doughnut', data: { labels: who.map(x => x[1]), datasets: [{ data: who.map(([k]) => ob[k]), backgroundColor: ['#2563eb', '#6366f1', '#f59e0b', '#94a3b8'] }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } } });
    } else $('#chWho').closest('.chart-box').html(LF.empty('chat', t('No replies yet.')));
  }

  function section(key, title, body) {
    return `<div class="card mb-3"><div class="card-header d-flex align-items-center">${LF.esc(title)}<div class="ms-auto d-flex gap-1"><button class="btn btn-sm btn-light" data-export="${key}" data-format="csv"><i class="bi bi-download"></i> CSV</button><button class="btn btn-sm btn-light" data-export="${key}" data-format="xlsx"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</button></div></div><div class="table-wrap">${body}</div></div>`;
  }

  function load() {
    const p = params();
    if (p.range === 'custom' && (!p.from || !p.to)) return;
    $('#anBody').css('opacity', .55);
    LF.api('GET', 'analytics', p).then(r => render(r.data)).always(() => $('#anBody').css('opacity', 1));
  }
  $(document).on('click', '[data-export]', function () {
    const q = new URLSearchParams(Object.assign(params(), { section: $(this).data('export'), format: $(this).data('format') || 'csv' }));
    window.location.href = '/api/v1/analytics/export?' + q.toString();
  });
  $('#anRange').on('change', function () { $('#anFrom, #anTo').toggleClass('hidden', this.value !== 'custom'); load(); });
  $('#anFrom, #anTo, #anSource, #anUser, #anCampaign, #anPlatform').on('change', load);
  $('#anTo').val(LF.localDate(new Date())); $('#anFrom').val(LF.localDate(new Date(Date.now() - 29 * 86400e3)));
  LF.fillFromLookups($('.lf-actions')).then(lk => { if (!lk.campaigns.length) $('#anCampaign').addClass('hidden'); load(); });
})(jQuery, window.LF);
