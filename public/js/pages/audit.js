(function ($, LF) {
  'use strict';
  let page = 1;
  const summarize = a => {
    const parts = [];
    if (a.meta) Object.entries(a.meta).slice(0, 6).forEach(([k, v]) => parts.push(`<span class="text-muted">${LF.esc(k)}:</span> ${LF.esc(typeof v === 'object' ? JSON.stringify(v) : v)}`));
    if (a.changes) parts.push(`<details><summary class="small">changes</summary><pre class="small mb-0 text-pre">${LF.esc(JSON.stringify(a.changes, null, 1))}</pre></details>`);
    return parts.join('<br>');
  };
  function load() {
    LF.api('GET', 'audit-logs', { page, action: $('#a-action').val(), per_page: 50 }).then(r => {
      $('#a-body').html(r.data.length ? r.data.map(a => `<tr><td class="text-nowrap small">${LF.fmt.dateTime(a.at)}</td><td>${LF.esc(a.user || 'System')}</td><td><code>${LF.esc(a.action)}</code></td><td class="small">${LF.esc(a.subject || '')}</td><td class="small mw-220">${summarize(a)}</td><td class="small text-muted">${LF.esc(a.ip || '')}</td></tr>`).join('') : `<tr><td colspan="6">${LF.empty('shield-check', 'No audit events.')}</td></tr>`);
      $('#a-pager').html(LF.pager(r.meta));
    });
  }
  $('#a-action').on('change', () => { page = 1; load(); });
  $('#a-pager').on('click', '[data-page]', function () { page = $(this).data('page'); load(); });
  load();
})(jQuery, window.LF);
