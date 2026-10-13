(function ($, LF) {
  'use strict';
  const t = LF.t;
  function load() {
    LF.api('GET', 'platform').then(r => {
      const s = r.data.system;
      const card = (k, v, bad) => `<div class="col-6 col-md-3 col-xl-2"><div class="card kpi ${bad ? 'kpi-alert' : ''}"><div class="kpi-label">${LF.esc(k)}</div><div class="kpi-value fs-6">${v}</div></div></div>`;
      $('#sysCards').html([
        card('PHP / Laravel', `${LF.esc(s.php)} / ${LF.esc(s.laravel)}`), card(t('Environment'), LF.esc(s.environment) + (s.debug ? ' · DEBUG' : ''), s.debug && s.environment === 'production'),
        card(t('Queue'), `${LF.esc(s.queue)} · ${s.jobs_pending} ${t('pending')}`, s.oldest_pending_job_min > 5),
        card(t('Failed jobs'), s.jobs_failed, s.jobs_failed > 0), card(t('Webhooks 24h'), `${s.webhooks_24h} · ${s.webhooks_failed_24h} ${t('failed')}`, s.webhooks_failed_24h > 0),
        card('HTTPS / secure cookie', `${s.https ? '✓' : '✗'} / ${s.session_secure ? '✓' : '✗'}`, !s.https || !s.session_secure),
      ].join('') + (s.oldest_pending_job_min > 5 ? `<div class="col-12"><div class="alert alert-warning py-2 small mb-0">${t('Jobs have been waiting')} ${s.oldest_pending_job_min} ${t('min — is the queue worker running?')}</div></div>` : ''));
      $('#o2-ind').html(r.data.industries.map(i => `<option value="${i.key}">${LF.esc(i.label)}</option>`).join(''));
      $('#orgBody').html(r.data.organizations.map(o => `<tr><td><strong>${LF.esc(o.name)}</strong><div class="cell-muted">${LF.esc(o.timezone)} · ${LF.esc(o.currency)} · ${LF.fmt.date(o.created_at)}</div></td>
        <td>${LF.esc(o.industry || '—')}</td><td class="text-end">${o.users}</td><td class="text-end">${LF.fmt.num(o.leads)}</td><td class="text-end">${LF.fmt.num(o.conversations)}</td>
        <td class="text-end">${LF.fmt.num(o.messages_30d)}</td><td class="text-end">${LF.fmt.num(o.ai_calls_30d)}<div class="cell-muted">${LF.fmt.num(o.ai_tokens_30d)} tok</div></td>
        <td>${o.connected.map(c => `<span class="tag">${LF.esc(c)}</span>`).join('') || '<span class="cell-muted">—</span>'}</td>
        <td>${o.is_active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Suspended</span>'}</td>
        <td class="text-end"><button class="btn btn-sm btn-light" data-org="${o.id}" data-active="${o.is_active ? 0 : 1}">${o.is_active ? t('Suspend') : t('Activate')}</button></td></tr>`).join(''));
    });
  }
  $('#newOrg').on('click', () => { $('#orgForm')[0].reset(); LF.clearErrors($('#orgForm')); bootstrap.Modal.getOrCreateInstance(document.getElementById('orgModal')).show(); });
  $('#orgForm').on('submit', function (e) {
    e.preventDefault();
    LF.api('POST', 'platform/organizations', LF.formData($(this)), { form: $(this) }).then(() => { bootstrap.Modal.getInstance(document.getElementById('orgModal')).hide(); LF.toast(t('Business created')); load(); });
  });
  $(document).on('click', '[data-org]', function () {
    const id = $(this).data('org'), active = $(this).data('active') === 1;
    LF.confirm({ title: active ? t('Activate business') : t('Suspend business'), body: active ? '' : t('All its users are signed out and cannot sign in. Webhooks stop being processed. Data is kept.'), danger: !active, confirmText: active ? t('Activate') : t('Suspend') })
      .then(() => LF.api('PATCH', 'platform/organizations/' + id, { is_active: active }).then(load));
  });
  load();
})(jQuery, window.LF);
