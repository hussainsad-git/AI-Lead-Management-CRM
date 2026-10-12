(function ($, LF) {
  'use strict';
  LF.api('GET', 'integrations/status').then(r => {
    $('#intRoot').html(r.data.map(i => `<div class="col-lg-4"><div class="card h-100"><div class="card-body">
      <div class="d-flex align-items-start mb-2"><h2 class="h6 mb-0">${LF.esc(i.name)}</h2><span class="badge text-bg-secondary ms-auto">Not configured</span></div>
      <p class="small text-muted">Adapter, setup screen and webhook endpoint arrive in ${LF.esc(i.available_in)}. Until then no data is received from this service.</p>
      <div class="small-caps mb-1">You will need</div><ul class="small mb-0">${i.requirements.map(x => `<li>${LF.esc(x)}</li>`).join('')}</ul>
      <dl class="dl-grid mt-3"><dt>Last success</dt><dd>${i.last_success_at ? LF.fmt.dateTime(i.last_success_at) : 'Never'}</dd><dt>Last error</dt><dd>${LF.esc(i.last_error || '—')}</dd></dl>
    </div></div></div>`).join(''));
  });
})(jQuery, window.LF);
