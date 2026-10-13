(function ($, LF) {
  'use strict';
  const t = LF.t;
  const BADGE = { connected: 'text-bg-success', error: 'text-bg-danger', disabled: 'text-bg-secondary', not_configured: 'text-bg-light border' };
  const FIELDS = {
    whatsapp_cloud: {
      config: [['waba_id', t('WhatsApp Business Account ID')], ['phone_number_id', t('Phone number ID')], ['api_version', t('Graph API version'), 'v24.0']],
      secrets: [['access_token', t('Permanent access token (system user)')], ['app_secret', t('App secret')]],
    },
    openai: { config: [['embedding_model', t('Embedding model'), 'text-embedding-3-small'], ['base_url', t('Base URL (optional)'), 'https://api.openai.com/v1']], secrets: [['api_key', t('API key')]] },
    deepseek: { config: [['default_model', t('Default model'), 'deepseek-v4-flash'], ['base_url', t('Base URL (optional)'), 'https://api.deepseek.com']], secrets: [['api_key', t('API key')]] },
  };
  const ICON = { whatsapp_cloud: 'whatsapp', openai: 'stars', deepseek: 'cpu', meta_marketing: 'megaphone' };

  function card(i) {
    const f = FIELDS[i.key];
    const head = `<div class="d-flex align-items-start mb-2"><h2 class="h6 mb-0"><i class="bi bi-${ICON[i.key]} me-1"></i>${LF.esc(i.name)}</h2>
      <span class="badge ${BADGE[i.status] || 'text-bg-light border'} ms-auto">${LF.esc(t(LF.fmt.label(i.status)))}</span></div>`;
    if (!f) {
      return `<div class="col-xl-4 col-lg-6"><div class="card h-100"><div class="card-body">${head}<p class="small text-muted">${t('Not built yet')} (${LF.esc(i.available_in)}). ${t('Campaign spend can be entered manually on the Campaigns page.')}</p>
        <ul class="small mb-0">${i.requirements.map(x => `<li>${LF.esc(x)}</li>`).join('')}</ul></div></div></div>`;
    }
    const cfg = f.config.map(([k, label, ph]) => `<div class="col-md-6"><label class="form-label small" for="${i.key}-${k}">${LF.esc(label)}</label>
      <input class="form-control form-control-sm" id="${i.key}-${k}" name="config[${k}]" value="${LF.esc(i.config[k] || '')}" placeholder="${LF.esc(ph || '')}" autocomplete="off"></div>`).join('');
    const sec = f.secrets.map(([k, label]) => `<div class="col-md-6"><label class="form-label small" for="${i.key}-${k}">${LF.esc(label)}</label>
      <input class="form-control form-control-sm" type="password" id="${i.key}-${k}" name="secrets[${k}]" autocomplete="new-password" placeholder="${i.secrets[k].set ? t('Saved') + ' ' + (i.secrets[k].hint || '') + ' — ' + t('leave blank to keep') : t('Not set')}"></div>`).join('');
    const wa = i.key === 'whatsapp_cloud' ? `
      <div class="col-12"><hr class="my-1"><div class="small-caps mb-1">${t('Webhook (paste into Meta App → WhatsApp → Configuration)')}</div>
        ${i.webhook_url ? `<div class="input-group input-group-sm mb-1"><span class="input-group-text">${t('Callback URL')}</span><input class="form-control" readonly value="${LF.esc(i.webhook_url)}" id="whUrl"><button class="btn btn-light" type="button" data-copy="#whUrl">${t('Copy')}</button></div>
        <div class="input-group input-group-sm mb-1"><span class="input-group-text">${t('Verify token')}</span><input class="form-control" readonly value="••••••••" id="whTok"><button class="btn btn-light" type="button" id="revealTok">${t('Reveal')}</button></div>
        <div class="small text-muted">${t('Subscribe to the "messages" field. Requests are authenticated with your app secret (X-Hub-Signature-256).')}</div>
        <button class="btn btn-sm btn-link px-0" type="button" id="rotateWh">${t('Rotate URL & token')}</button>` : `<div class="small text-muted">${t('Save once to generate the webhook URL and verify token.')}</div>`}</div>
      ${i.config.display_phone_number ? `<div class="col-12 small"><i class="bi bi-telephone"></i> ${LF.esc(i.config.display_phone_number)} · ${LF.esc(i.config.verified_name || '')}</div>` : ''}
      <div class="col-12 small text-muted">${t('Last webhook')}: ${i.last_webhook_at ? LF.fmt.rel(i.last_webhook_at) : t('never')} · ${t('Failed in 24h')}: ${i.webhook_failures_24h} · ${t('Templates')}: ${i.templates}
        ${i.status === 'connected' ? ` · <button class="btn btn-sm btn-link p-0" type="button" id="syncTpl">${t('Sync templates')}</button>` : ''}</div>` : '';
    return `<div class="col-xl-${i.key === 'whatsapp_cloud' ? 12 : 6}"><div class="card h-100"><form class="card-body" data-int="${i.key}" novalidate autocomplete="off">${head}
      <div class="row g-2">${cfg}${sec}${wa}
        ${i.last_error ? `<div class="col-12"><div class="alert alert-danger py-1 small mb-0">${t('Last error')} (${LF.fmt.rel(i.last_error_at)}): ${LF.esc(i.last_error)}</div></div>` : ''}
        <div class="col-12 small text-muted">${t('Verified')}: ${i.verified_at ? LF.fmt.dateTime(i.verified_at) : t('never')}</div>
        <div class="col-12"><details><summary class="small">${t('Requirements')}</summary><ul class="small mt-1 mb-0">${i.requirements.map(x => `<li>${LF.esc(x)}</li>`).join('')}</ul></details></div>
        <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm" type="submit">${t('Save')}</button>
          <button class="btn btn-light btn-sm" type="button" data-test="${i.key}"><i class="bi bi-plug"></i> ${t('Test connection')}</button>
          ${i.status === 'disabled' ? `<button class="btn btn-light btn-sm ms-auto" type="button" data-enable="${i.key}">${t('Enable')}</button>` : (i.status !== 'not_configured' ? `<button class="btn btn-link btn-sm text-danger ms-auto" type="button" data-disable="${i.key}">${t('Disable')}</button>` : '')}</div>
      </div></form></div></div>`;
  }

  function load() {
    LF.api('GET', 'integrations/status').then(r => { $('#intRoot').html(r.data.map(card).join('')); });
  }
  $(document).on('submit', '[data-int]', function (e) {
    e.preventDefault();
    const $f = $(this), key = $f.data('int'), d = LF.formData($f);
    Object.keys(d.config || {}).forEach(k => { if (d.config[k] === null) d.config[k] = ''; });
    const $b = $f.find('[type="submit"]'); LF.busy($b, true);
    LF.api('PUT', 'integrations/' + key, d, { form: $f }).then(() => { LF.toast(t('Saved — run "Test connection" to verify')); load(); }).always(() => LF.busy($b, false));
  });
  $(document).on('click', '[data-test]', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', `integrations/${$b.data('test')}/test`).then(r => { LF.toast(t('Connected') + (r.data.detail && r.data.detail.models_available ? ` · ${r.data.detail.models_available} ${t('models')}` : '')); load(); }, load).always(() => LF.busy($b, false));
  });
  $(document).on('click', '[data-disable]', function () { LF.api('PUT', 'integrations/' + $(this).data('disable'), { enabled: false }).then(load); });
  $(document).on('click', '[data-enable]', function () { LF.api('PUT', 'integrations/' + $(this).data('enable'), { enabled: true }).then(load); });
  $(document).on('click', '[data-copy]', function () { const v = $($(this).data('copy')).val(); navigator.clipboard && navigator.clipboard.writeText(v).then(() => LF.toast(t('Copied'))); });
  $(document).on('click', '#revealTok', () => LF.api('POST', 'integrations/whatsapp_cloud/verify-token').then(r => $('#whTok').val(r.data.verify_token)));
  $(document).on('click', '#rotateWh', () => LF.confirm({ title: t('Rotate webhook'), body: t('The old URL stops working immediately. Update it in Meta right after.'), danger: true, confirmText: t('Rotate') })
    .then(() => LF.api('POST', 'integrations/whatsapp_cloud/rotate-webhook').then(load)));
  $(document).on('click', '#syncTpl', function () { const $b = $(this); LF.api('POST', 'integrations/whatsapp_cloud/templates/sync').then(r => { LF.toast(r.data.synced + ' ' + t('templates synced')); load(); }); });
  load();
})(jQuery, window.LF);
