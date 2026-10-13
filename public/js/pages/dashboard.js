(function ($, LF) {
  'use strict';
  const charts = {};
  const palette = ['#2563eb', '#0ea5e9', '#14b8a6', '#6366f1', '#a855f7', '#f59e0b', '#ef4444', '#64748b', '#22c55e', '#ec4899', '#84cc16', '#0f172a'];
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.font.size = 11;
  Chart.defaults.color = '#64748b';
  Chart.defaults.plugins.legend.labels.boxWidth = 10;

  function kpi(label, value, sub, opts = {}) {
    return `<div class="col-6 col-md-4 col-xl-2"><div class="card kpi ${opts.alert ? 'kpi-alert' : ''}">
      <div class="kpi-label">${LF.esc(label)}</div><div class="kpi-value ${opts.small ? 'kpi-unavailable' : ''}">${value}</div>
      ${sub ? `<div class="kpi-sub">${sub}</div>` : ''}${opts.href ? `<a class="stretched-link" href="${opts.href}" aria-label="${LF.esc(label)}"></a>` : ''}</div></div>`;
  }

  function adCards(ad, cur) {
    if (ad.status !== 'available') {
      const msg = ad.status === 'restricted' ? 'Not available for your role' : 'Data unavailable';
      const sub = LF.esc(ad.message);
      return ['Advertising spend', 'Cost per lead', 'Cost per qualified lead', 'Customer acquisition cost']
        .map(l => kpi(l, msg, l === 'Advertising spend' ? sub : '', { small: true })).join('');
    }
    const na = v => v === null ? '<span class="kpi-unavailable">Not calculable</span>' : LF.fmt.money(v, cur);
    return kpi('Advertising spend', LF.fmt.money(ad.spend, cur), LF.esc(ad.source)) +
      kpi('Cost per lead', na(ad.cost_per_lead), LF.fmt.num(ad.attributed_leads) + ' attributed leads') +
      kpi('Cost per qualified lead', na(ad.cost_per_qualified_lead), '') +
      kpi('Customer acquisition cost', na(ad.customer_acquisition_cost), 'Ad spend only');
  }

  function render(d) {
    const s = d.summary, cur = d.currency;
    $('#dashScope').text(d.scope === 'organization' ? 'Organization' : d.scope === 'team' ? 'My team' : 'My leads');
    const rangeLabel = d.range.from ? `${LF.esc(d.range.from)} → ${LF.esc(d.range.to)}` : 'All time';
    const html = `
      <div class="small text-muted mb-2">Cohort: leads created ${rangeLabel} (${LF.esc(d.range.timezone)}). Queue figures are current.</div>
      <div class="row g-2 mb-2">
        ${kpi('Leads in range', LF.fmt.num(s.new_leads_in_range), `Total: ${LF.fmt.num(s.total_leads)}`, { href: '/leads' })}
        ${kpi('New today', LF.fmt.num(s.new_today), `Week ${LF.fmt.num(s.new_this_week)} · Month ${LF.fmt.num(s.new_this_month)}`)}
        ${kpi('Awaiting first contact', LF.fmt.num(s.awaiting_first_contact), 'Open, never contacted', { alert: s.awaiting_first_contact > 0, href: '/leads?contacted=no&status_category=open' })}
        ${kpi('Overdue follow-ups', LF.fmt.num(s.overdue_follow_ups), 'Past scheduled time', { alert: s.overdue_follow_ups > 0, href: '/follow-ups?view=overdue' })}
        ${kpi('Contacted', LF.fmt.num(s.contacted), `Contact rate ${LF.fmt.pct(s.contact_rate)}`)}
        ${kpi('Responded', LF.fmt.num(s.responded), `Response rate ${LF.fmt.pct(s.response_rate)}`)}
        ${kpi('Unresponsive', LF.fmt.num(s.unresponsive), 'Contacted, no response yet')}
        ${kpi('Qualified', LF.fmt.num(s.qualified), '')}
        ${kpi('Appointments', LF.fmt.num(s.appointments_scheduled), 'Booked in range')}
        ${kpi('Converted', LF.fmt.num(s.converted), `Conversion ${LF.fmt.pct(s.conversion_rate)}`)}
        ${kpi('Lost', LF.fmt.num(s.lost), 'Lost / not interested / DNC')}
        ${kpi('Response of contacted', LF.fmt.pct(s.response_rate_of_contacted), 'Responded ÷ contacted')}
      </div>
      <div class="row g-2 mb-3">${adCards(d.advertising, cur)}</div>
      ${d.conversations ? `<div class="row g-2 mb-3">
        ${kpi(LF.t('WhatsApp conversations'), LF.fmt.num(d.conversations.new), LF.fmt.num(d.conversations.whatsapp_leads) + ' ' + LF.t('leads captured'), { href: '/whatsapp' })}
        ${kpi(LF.t('Awaiting a human'), LF.fmt.num(d.conversations.awaiting_human), LF.t('AI handed off'), { alert: d.conversations.awaiting_human > 0, href: '/whatsapp?view=handoff' })}
        ${kpi(LF.t('AI replies'), LF.fmt.num(d.conversations.ai_replies), LF.fmt.num(d.conversations.auto_replies) + ' ' + LF.t('instant auto-replies'))}
        ${kpi(LF.t('AI resolution rate'), d.conversations.ai_resolution_rate === null ? '<span class="kpi-unavailable">—</span>' : d.conversations.ai_resolution_rate + '%', LF.t('no human needed'))}
        ${kpi(LF.t('Median first response'), d.conversations.median_first_response_min === null ? '<span class="kpi-unavailable">—</span>' : d.conversations.median_first_response_min + ' ' + LF.t('min'), LF.t('new conversations'))}
        ${kpi(LF.t('Messages received'), LF.fmt.num(d.conversations.inbound), '')}
      </div>` : ''}
      ${d.advertising.notes && d.advertising.notes.length ? `<div class="lf-notice mb-3">${d.advertising.notes.map(LF.esc).join('<br>')}</div>` : ''}
      <div class="row g-3">
        <div class="col-xl-8"><div class="card h-100"><div class="card-header">Lead acquisition <span class="text-muted fw-normal small">per ${LF.esc(d.charts.acquisition.granularity)}</span></div><div class="card-body"><div class="chart-box"><canvas id="chAcq" aria-label="Lead acquisition over time" role="img"></canvas></div></div></div></div>
        <div class="col-xl-4"><div class="card h-100"><div class="card-header">Funnel</div><div class="card-body"><div class="chart-box"><canvas id="chFunnel" aria-label="Sales funnel" role="img"></canvas></div></div></div></div>
        <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header">Leads by source</div><div class="card-body"><div class="chart-box chart-sm"><canvas id="chSource" role="img" aria-label="Leads by source"></canvas></div></div></div></div>
        <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header">Leads by campaign <span class="text-muted fw-normal small">first touch</span></div><div class="card-body"><div class="chart-box chart-sm"><canvas id="chCampaign" role="img" aria-label="Leads by campaign"></canvas></div></div></div></div>
        <div class="col-md-12 col-xl-4"><div class="card h-100"><div class="card-header">Status distribution</div><div class="card-body"><div class="chart-box chart-sm"><canvas id="chStatus" role="img" aria-label="Status distribution"></canvas></div></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header">Spend vs qualified leads by campaign</div><div class="card-body" id="spendBox"></div></div></div>
        <div class="col-xl-6"><div class="card h-100"><div class="card-header d-flex">Overdue follow-ups<a class="ms-auto small fw-normal" href="/follow-ups?view=overdue">View all</a></div><div class="card-body p-0" id="overdueBox"></div></div></div>
        <div class="col-12"><div class="card"><div class="card-header">Salesperson performance <span class="text-muted fw-normal small">leads created in range</span></div><div class="card-body p-0 table-wrap" id="peopleBox"></div></div></div>
      </div>
      <details class="mt-3 small text-muted"><summary>How these numbers are calculated</summary><ul class="mt-2">${Object.values(d.definitions).map(x => `<li>${LF.esc(x)}</li>`).join('')}</ul></details>`;
    $('#dashBody').html(html);

    Object.values(charts).forEach(c => c.destroy());
    const empty = (id, txt) => $('#' + id).closest('.card-body').html(LF.empty('bar-chart', txt));
    const acq = d.charts.acquisition;
    if (acq.values.some(v => v > 0)) {
      charts.acq = new Chart(document.getElementById('chAcq'), { type: 'bar', data: { labels: acq.labels, datasets: [{ label: 'Leads', data: acq.values, backgroundColor: '#2563eb', borderRadius: 3, maxBarThickness: 28 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
    } else empty('chAcq', 'No leads in this range.');
    const f = d.charts.funnel;
    if (f[0].value > 0) {
      charts.funnel = new Chart(document.getElementById('chFunnel'), { type: 'bar', data: { labels: f.map(x => x.label), datasets: [{ data: f.map(x => x.value), backgroundColor: ['#1e3a8a', '#1d4ed8', '#2563eb', '#3b82f6', '#60a5fa', '#16a34a'], borderRadius: 3 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.raw + (f[0].value ? ` (${Math.round(c.raw / f[0].value * 100)}%)` : '') } } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });
    } else empty('chFunnel', 'No leads in this range.');
    const doughnut = (id, rows, key) => {
      if (!rows.length) return empty(id, 'No data.');
      charts[key] = new Chart(document.getElementById(id), { type: 'doughnut', data: { labels: rows.map(r => r.label), datasets: [{ data: rows.map(r => r.value), backgroundColor: palette, borderWidth: 1 }] },
        options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } } });
    };
    doughnut('chSource', d.charts.by_source, 'src');
    doughnut('chCampaign', d.charts.by_campaign, 'camp');
    doughnut('chStatus', d.charts.status_distribution, 'st');

    const ad = d.advertising;
    if (ad.status === 'available' && ad.per_campaign.length) {
      $('#spendBox').html('<div class="chart-box"><canvas id="chSpend" role="img" aria-label="Spend versus qualified leads"></canvas></div><div class="table-wrap mt-2"><table class="table table-sm table-dense small"><thead><tr><th>Campaign</th><th class="text-end">Spend</th><th class="text-end">Leads</th><th class="text-end">CPL</th><th class="text-end">Qualified</th><th class="text-end">CPQL</th></tr></thead><tbody>' +
        ad.per_campaign.map(c => `<tr><td><span class="trunc" title="${LF.esc(c.name)}">${LF.esc(c.name)}</span></td><td class="text-end">${LF.fmt.money(c.spend, cur)}</td><td class="text-end">${c.leads}</td><td class="text-end">${c.cpl === null ? '<span class="cell-muted">n/a</span>' : LF.fmt.money(c.cpl, cur)}</td><td class="text-end">${c.qualified}</td><td class="text-end">${c.cpql === null ? '<span class="cell-muted">n/a</span>' : LF.fmt.money(c.cpql, cur)}</td></tr>`).join('') + '</tbody></table></div>');
      charts.spend = new Chart(document.getElementById('chSpend'), { data: { labels: ad.per_campaign.map(c => c.name),
        datasets: [{ type: 'bar', label: 'Spend (' + cur + ')', data: ad.per_campaign.map(c => c.spend), backgroundColor: '#93c5fd', yAxisID: 'y' },
          { type: 'line', label: 'Qualified leads', data: ad.per_campaign.map(c => c.qualified), borderColor: '#16a34a', backgroundColor: '#16a34a', yAxisID: 'y1' }] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, position: 'left' }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } } } } });
    } else {
      $('#spendBox').html(LF.empty('cash-coin', ad.status === 'restricted' ? 'Not available for your role.' : 'No ad spend recorded for this period.'));
    }

    $('#overdueBox').html(d.overdue_preview.length ? '<ul class="list-group list-group-flush">' + d.overdue_preview.map(t => `<li class="list-group-item d-flex align-items-center gap-2">
        <div class="flex-grow-1"><a class="lf-row-link" href="/leads/${t.lead_id}">${LF.esc(t.contact)}</a><div class="small text-muted">${LF.esc(t.purpose)} · ${LF.esc(t.assignee || 'Unassigned')}</div></div>
        ${LF.priorityBadge(t.priority)}<span class="overdue small">${LF.fmt.rel(t.scheduled_at)}</span></li>`).join('') + '</ul>' : LF.empty('check2-circle', 'Nothing overdue. Nice.'));

    const p = d.charts.salespeople;
    $('#peopleBox').html(p.length ? `<table class="table table-hover"><thead><tr><th>Salesperson</th><th class="text-end">Leads</th><th class="text-end">Contacted</th><th class="text-end">Responded</th><th class="text-end">Qualified</th><th class="text-end">Converted</th><th class="text-end">Conv. rate</th><th class="text-end">Calls logged</th></tr></thead><tbody>` +
      p.map(r => `<tr><td>${LF.esc(r.name)}</td><td class="text-end">${r.leads}</td><td class="text-end">${r.contacted}</td><td class="text-end">${r.responded}</td><td class="text-end">${r.qualified}</td><td class="text-end">${r.converted}</td><td class="text-end">${r.leads ? Math.round(r.converted / r.leads * 1000) / 10 + '%' : '—'}</td><td class="text-end">${r.calls_logged}</td></tr>`).join('') + '</tbody></table>' : LF.empty('people', 'No assigned leads in this range.'));
  }

  function load() {
    const range = $('#dashRange').val();
    const params = { range };
    if (range === 'custom') { params.from = $('#dashFrom').val(); params.to = $('#dashTo').val(); if (!params.from || !params.to) return; }
    try { localStorage.setItem('lf.dash.range', range); } catch (e) { /* storage unavailable */ }
    $('#dashBody').css('opacity', .55);
    LF.api('GET', 'dashboard', params).then(r => render(r.data)).always(() => $('#dashBody').css('opacity', 1));
  }

  try { const saved = localStorage.getItem('lf.dash.range'); if (saved && saved !== 'custom') $('#dashRange').val(saved); } catch (e) { /* ignore */ }
  $('#dashRange').on('change', function () { $('#dashCustom').toggleClass('hidden', this.value !== 'custom'); if (this.value !== 'custom') load(); });
  $('#dashApply').on('click', load);
  $('#dashTo').val(LF.localDate(new Date())); $('#dashFrom').val(LF.localDate(new Date(Date.now() - 29 * 86400e3)));
  load();
})(jQuery, window.LF);
