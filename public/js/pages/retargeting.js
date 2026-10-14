(function ($, LF) {
  'use strict';
  const t = LF.t, esc = LF.esc;
  let page = 1, reasons = {};
  const STATUS = { success: 'text-bg-success', failed: 'text-bg-danger', running: 'text-bg-info', queued: 'text-bg-secondary' };
  const KIND = { meta_replace: 'Meta sync', csv_export: 'CSV export', meta_create: 'Meta audience created' };

  function loadAudiences() {
    LF.api('GET', 'audiences/meta-status').then(r => {
      $('#metaBanner').html(r.meta.connected ? '' : `<div class="alert alert-warning small mb-0">${t('Meta Marketing API is not connected. Segments can still be exported as CSV; uploads to Meta need the connection on Integrations.')}</div>`);
      $('#audBody').html(r.data.length ? r.data.map(a => `<tr>
        <td class="fw-semibold">${esc(a.name)}</td>
        <td class="small">${esc(a.meta_audience_name || '')}<div class="text-muted">ID ${esc(a.meta_audience_id)}</div></td>
        <td class="small">${a.remote ? (a.remote.lower === null ? '—' : `${LF.fmt.num(a.remote.lower)} – ${LF.fmt.num(a.remote.upper)}`) : (a.error ? `<span class="text-danger">${esc(a.error)}</span>` : `<span class="text-muted">${t('not connected')}</span>`)}</td>
        <td class="small">${a.remote ? esc(a.remote.delivery || '—') + (a.remote.operation && a.remote.operation !== 'Normal' ? ` · ${esc(a.remote.operation)}` : '') : '—'}</td>
        <td class="small">${a.last_synced_at ? LF.fmt.rel(a.last_synced_at) : t('never')}</td>
        <td class="text-end"><button class="btn btn-primary btn-sm" data-sync="${a.segment_id}" ${r.meta.connected ? '' : 'disabled'}><i class="bi bi-arrow-repeat"></i> ${t('Sync now')}</button></td></tr>`).join('')
        : `<tr><td colspan="6">${LF.empty('bullseye', t('No Meta audiences yet. Open a segment on Audience Segments and choose "Create Meta audience & sync".'))}</td></tr>`);
    });
  }
  function loadJobs() {
    LF.api('GET', 'audiences/jobs', { page, per_page: 20 }).then(r => {
      $('#jobBody').html(r.data.length ? r.data.map(j => `<tr>
        <td class="small" title="${esc(LF.fmt.dateTime(j.created_at))}">${LF.fmt.rel(j.created_at)}</td>
        <td class="small">${esc(j.segment || '—')}</td>
        <td class="small">${esc(t(KIND[j.kind] || j.kind))}</td>
        <td><span class="badge ${STATUS[j.status] || 'text-bg-light'}">${esc(t(LF.fmt.label(j.status)))}</span>${j.error ? `<div class="small text-danger text-wrap">${esc(j.error)}</div>` : ''}</td>
        <td class="text-end">${LF.fmt.num(j.members)}</td><td class="text-end">${LF.fmt.num(j.eligible)}</td><td class="text-end">${LF.fmt.num(j.uploaded)}</td>
        <td class="small text-wrap">${Object.entries(j.skipped || {}).filter(([, n]) => n > 0).map(([k, n]) => `${n} ${esc(t(reasons[k] || k).toLowerCase())}`).join(' · ') || '—'}</td>
        <td class="small">${esc(j.by || t('scheduler'))}</td></tr>`).join('')
        : `<tr><td colspan="9">${LF.empty('clock-history', t('No syncs or exports yet.'))}</td></tr>`);
      $('#jobPager').html(LF.pager(r.meta));
      if (r.data.some(j => ['queued', 'running'].includes(j.status))) setTimeout(() => { loadJobs(); loadAudiences(); }, 5000);
    });
  }
  $('#jobPager').on('click', '[data-page]', function () { page = Number($(this).data('page')); loadJobs(); });
  $('#jobsReload').on('click', () => { loadJobs(); loadAudiences(); });
  $(document).on('click', '[data-sync]', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', `audiences/${$b.data('sync')}/sync`).then(() => { LF.toast(t('Sync queued')); loadJobs(); }).always(() => LF.busy($b, false));
  });
  LF.api('GET', 'audiences/meta').then(r => { reasons = r.data.reasons; loadAudiences(); loadJobs(); });
})(jQuery, window.LF);
