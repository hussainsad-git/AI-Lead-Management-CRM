(function ($, LF) {
  'use strict';
  const t = LF.t;
  const BADGE = { connected: 'text-bg-success', error: 'text-bg-danger', disabled: 'text-bg-secondary', not_configured: 'text-bg-light border' };
  const FIELDS = {
    whatsapp_cloud: {
      config: [['waba_id', t('WhatsApp Business Account ID')], ['phone_number_id', t('Phone number ID')], ['api_version', t('Graph API version'), 'v25.0']],
      secrets: [['access_token', t('Permanent access token (system user)')], ['app_secret', t('App secret')]],
    },
    openai: { config: [['embedding_model', t('Embedding model'), 'text-embedding-3-small'], ['base_url', t('Base URL (optional)'), 'https://api.openai.com/v1']], secrets: [['api_key', t('API key')]] },
    meta_marketing: {
      config: [['ad_account_id', t('Ad account ID (numbers only)')], ['page_id', t('Facebook Page ID (for Lead Ads)')], ['api_version', t('Graph API version'), 'v25.0'], ['initial_days', t('Days of spend to load on first sync'), '30']],
      secrets: [['access_token', t('System-user access token (ads_read, leads_retrieval)')], ['app_secret', t('App secret')], ['page_access_token', t('Page access token (optional, if the system user lacks Page access)')]],
    },
    deepseek: { config: [['default_model', t('Default model'), 'deepseek-v4-flash'], ['base_url', t('Base URL (optional)'), 'https://api.deepseek.com']], secrets: [['api_key', t('API key')]] },
    twilio_voice: {
      config: [['account_sid', t('Account SID'), 'AC…'], ['from_number', t('Caller ID number (E.164)'), '+1…'], ['agent_prompt', t('Message played to the salesperson before connecting'), t('Connecting you to the customer.')], ['record_calls', t('Record calls (1 = yes; check local consent law)'), '0']],
      secrets: [['auth_token', t('Auth token')]],
    },
  };
  const ICON = { whatsapp_cloud: 'whatsapp', openai: 'stars', deepseek: 'cpu', meta_marketing: 'megaphone', twilio_voice: 'telephone-outbound' };

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
    const meta = i.key === 'meta_marketing' ? metaExtras(i) : (i.key === 'twilio_voice' ? `<div class="col-12 small text-muted">${i.config.account_name ? `<i class="bi bi-building"></i> ${LF.esc(i.config.account_name)} (${LF.esc(i.config.account_type || '')}) · ` : ''}${t('Calls (30 days)')}: ${i.calls_30d} · ${t('Failed (7 days)')}: ${i.calls_failed_7d} · ${t('Last callback')}: ${i.last_webhook_at ? LF.fmt.rel(i.last_webhook_at) : t('never')}
      <div class="mt-1">${t('Click-to-call appears on lead pages for salespeople who have a phone number on their profile. Callback URLs are sent to Twilio with each call; no webhook setup is needed in the Twilio console.')}</div></div>` : '');
    const wa = i.key === 'whatsapp_cloud' ? `
      <div class="col-12"><hr class="my-1"><div class="small-caps mb-1">${t('Webhook (paste into Meta App → WhatsApp → Configuration)')}</div>
        ${i.webhook_url ? `<div class="input-group input-group-sm mb-1"><span class="input-group-text">${t('Callback URL')}</span><input class="form-control" readonly value="${LF.esc(i.webhook_url)}" id="whUrl" aria-label="${t('Callback URL')}"><button class="btn btn-light" type="button" data-copy="#whUrl">${t('Copy')}</button></div>
        <div class="input-group input-group-sm mb-1"><span class="input-group-text">${t('Verify token')}</span><input class="form-control" readonly value="••••••••" id="whTok" aria-label="${t('Verify token')}"><button class="btn btn-light" type="button" id="revealTok">${t('Reveal')}</button></div>
        <div class="small text-muted">${t('Subscribe to the "messages" field. Requests are authenticated with your app secret (X-Hub-Signature-256).')}</div>
        <button class="btn btn-sm btn-link px-0" type="button" id="rotateWh">${t('Rotate URL & token')}</button>` : `<div class="small text-muted">${t('Save once to generate the webhook URL and verify token.')}</div>`}</div>
      ${i.config.display_phone_number ? `<div class="col-12 small"><i class="bi bi-telephone"></i> ${LF.esc(i.config.display_phone_number)} · ${LF.esc(i.config.verified_name || '')}</div>` : ''}
      <div class="col-12 small text-muted">${t('Last webhook')}: ${i.last_webhook_at ? LF.fmt.rel(i.last_webhook_at) : t('never')} · ${t('Failed in 24h')}: ${i.webhook_failures_24h} · ${t('Templates')}: ${i.templates}
        ${i.status === 'connected' ? ` · <button class="btn btn-sm btn-link p-0" type="button" id="syncTpl">${t('Sync templates')}</button>` : ''}</div>` : '';
    return `<div class="col-xl-${['whatsapp_cloud', 'meta_marketing'].includes(i.key) ? 12 : 6}"><div class="card h-100"><form class="card-body" data-int="${i.key}" novalidate autocomplete="off">${head}
      <div class="row g-2">${cfg}${sec}${wa}${meta}
        ${i.last_error ? `<div class="col-12"><div class="alert alert-danger py-1 small mb-0">${t('Last error')} (${LF.fmt.rel(i.last_error_at)}): ${LF.esc(i.last_error)}</div></div>` : ''}
        <div class="col-12 small text-muted">${t('Verified')}: ${i.verified_at ? LF.fmt.dateTime(i.verified_at) : t('never')}</div>
        <div class="col-12"><details><summary class="small">${t('Requirements')}</summary><ul class="small mt-1 mb-0">${i.requirements.map(x => `<li>${LF.esc(x)}</li>`).join('')}</ul></details></div>
        <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm" type="submit">${t('Save')}</button>
          <button class="btn btn-light btn-sm" type="button" data-test="${i.key}"><i class="bi bi-plug"></i> ${t('Test connection')}</button>
          ${i.status === 'disabled' ? `<button class="btn btn-light btn-sm ms-auto" type="button" data-enable="${i.key}">${t('Enable')}</button>` : (i.status !== 'not_configured' ? `<button class="btn btn-link btn-sm text-danger ms-auto" type="button" data-disable="${i.key}">${t('Disable')}</button>` : '')}</div>
      </div></form></div></div>`;
  }

  function metaExtras(i) {
    const c = i.config, cur = c.account_currency;
    const acct = c.account_name ? `<div class="col-12 small"><i class="bi bi-megaphone"></i> ${LF.esc(c.account_name)} · ${LF.esc(cur || '')} · ${LF.esc(c.account_timezone || '')}${c.page_name ? ` · <i class="bi bi-facebook"></i> ${LF.esc(c.page_name)}` : ''}</div>` : '';
    const warn = i.currency_mismatch ? `<div class="col-12"><div class="alert alert-warning py-1 small mb-0">${t('The ad account bills in')} ${LF.esc(cur)} ${t('but this business reports in')} ${LF.esc(LF.currency)}. ${t('Spend is stored as reported and excluded from cost-per-lead metrics until the currencies match.')}</div></div>` : '';
    const sub = i.submissions || {};
    const stats = `<div class="col-12 small text-muted">${t('Campaigns synced')}: ${i.campaigns_synced} · ${t('Last spend sync')}: ${c.last_spend_sync_at ? LF.fmt.rel(c.last_spend_sync_at) + (c.last_sync_range ? ` (${c.last_sync_range[0]} → ${c.last_sync_range[1]})` : '') : t('never')}
      · ${t('Forms')}: ${i.forms} · ${t('Form leads')}: ${sub.created || 0} ${t('created')}, ${sub.existing_lead || 0} ${t('matched existing')}, ${sub.failed || 0} ${t('failed')}, ${sub.no_contact || 0} ${t('without contact details')}</div>`;
    const leadAds = `<div class="col-12"><hr class="my-1"><div class="small-caps mb-1">${t('Lead Ads (Instant Forms)')}</div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="meta-lead-ads" name="config[lead_ads_enabled]" value="1" ${c.lead_ads_enabled ? 'checked' : ''}><label class="form-check-label small" for="meta-lead-ads">${t('Create CRM leads from Instant Form submissions (webhook + hourly backfill)')}</label></div>
      ${i.webhook_url ? `<div class="input-group input-group-sm mb-1 mt-1"><span class="input-group-text">${t('Callback URL')}</span><input class="form-control" readonly value="${LF.esc(i.webhook_url)}" id="metaUrl" aria-label="${t('Callback URL')}"><button class="btn btn-light" type="button" data-copy="#metaUrl">${t('Copy')}</button></div>
      <div class="input-group input-group-sm mb-1"><span class="input-group-text">${t('Verify token')}</span><input class="form-control" readonly value="••••••••" id="metaTok" aria-label="${t('Verify token')}"><button class="btn btn-light" type="button" data-reveal="meta_marketing" data-target="#metaTok">${t('Reveal')}</button></div>
      <div class="small text-muted">${t('Meta App → Webhooks → Page → subscribe to "leadgen". Requests are authenticated with your app secret.')} ${t('Last webhook')}: ${i.last_webhook_at ? LF.fmt.rel(i.last_webhook_at) : t('never')} · ${t('Failed in 24h')}: ${i.webhook_failures_24h}${c.page_subscribed_at ? ` · ${t('Page subscribed')} ${LF.fmt.rel(c.page_subscribed_at)}` : ''}</div>
      <button class="btn btn-sm btn-link px-0" type="button" data-rotate="meta_marketing">${t('Rotate URL & token')}</button>` : `<div class="small text-muted">${t('Save once to generate the webhook URL and verify token.')}</div>`}</div>`;
    const actions = i.status === 'connected' ? `<div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-outline-primary btn-sm" type="button" id="metaSync"><i class="bi bi-arrow-repeat"></i> ${t('Sync now')}</button>
      <button class="btn btn-outline-secondary btn-sm" type="button" id="metaSync30" title="${t('Reload the last 30 days of spend and form leads')}">${t('Reload 30 days')}</button>
      ${c.page_id ? `<button class="btn btn-outline-secondary btn-sm" type="button" id="metaSubscribe"><i class="bi bi-bell"></i> ${t('Subscribe Page to leadgen webhook')}</button>` : ''}</div>` : '';
    return acct + warn + stats + leadAds + actions;
  }

  function load() {
    LF.api('GET', 'integrations/status').then(r => { $('#intRoot').html(r.data.map(card).join('')); });
    loadForms();
  }

  // ----- website / landing-page form endpoints -----
  let WF = { forms: [], sources: [], users: [], campaigns: [] };
  function loadForms() {
    LF.api('GET', 'web-forms').then(r => {
      WF = r.data;
      const $l = $('#webFormsList');
      if (!WF.forms.length) { $l.html(`<div class="lf-empty py-3">${t('No form endpoints yet. Create one and paste the snippet into your website.')}</div>`); return; }
      $l.html(`<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>${t('Name')}</th><th>${t('Source')}</th><th>${t('Origins')}</th><th>${t('Assign to')}</th><th class="text-end">${t('Leads')}</th><th>${t('Last 7 days')}</th><th>${t('Last submission')}</th><th></th></tr></thead><tbody>
        ${WF.forms.map(f => `<tr class="${f.is_active ? '' : 'text-muted'}"><td><strong>${LF.esc(f.name)}</strong>${f.is_active ? '' : ` <span class="badge text-bg-secondary">${t('Inactive')}</span>`}</td><td>${LF.esc(f.source || '')}</td>
          <td class="small">${f.allowed_origins.length ? f.allowed_origins.map(LF.esc).join('<br>') : `<span class="text-warning">${t('Any site')}</span>`}</td><td>${LF.esc(f.assignee || '—')}</td>
          <td class="text-end">${f.submissions_count}</td><td class="small">${t('created')} ${f.last_7_days.created} · ${t('linked')} ${f.last_7_days.linked} · ${t('invalid')} ${f.last_7_days.invalid + f.last_7_days.rejected}</td>
          <td class="small">${f.last_submission_at ? LF.fmt.rel(f.last_submission_at) : t('never')}</td>
          <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" type="button" data-wf-embed="${f.id}" title="${t('Embed & submissions')}"><i class="bi bi-code-slash"></i></button>
            <button class="btn btn-sm btn-light" type="button" data-wf-edit="${f.id}" title="${t('Edit')}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-light" type="button" data-wf-rotate="${f.id}" title="${t('Rotate key')}"><i class="bi bi-arrow-repeat"></i></button>
            <button class="btn btn-sm btn-light text-danger" type="button" data-wf-delete="${f.id}" title="${t('Delete')}"><i class="bi bi-trash"></i></button></td></tr>`).join('')}
      </tbody></table></div>`);
    });
  }
  function fillSelect($s, rows, keep) {
    const first = keep ? $s.find('option').first().prop('outerHTML') : '';
    $s.html(first + rows.map(r => `<option value="${r.id}">${LF.esc(r.name)}</option>`).join(''));
  }
  function openForm(f) {
    const $f = $('#webFormForm'); $f[0].reset(); $f.find('.is-invalid').removeClass('is-invalid');
    fillSelect($('#wf-source'), WF.sources, false); fillSelect($('#wf-assignee'), WF.users, true); fillSelect($('#wf-campaign'), WF.campaigns, true);
    $('#webFormModalTitle').text(f ? t('Edit form endpoint') : t('New form endpoint'));
    $f.find('[name=id]').val(f ? f.id : '');
    if (f) {
      $('#wf-name').val(f.name); $('#wf-source').val(f.lead_source_id); $('#wf-assignee').val(f.assigned_user_id || ''); $('#wf-campaign').val(f.campaign_id || '');
      $('#wf-origins').val(f.allowed_origins.join('\n')); $('#wf-redirect').val(f.redirect_url || ''); $('#wf-tags').val(f.tags.join(', '));
    } else { $('#wf-source').val((WF.sources.find(s => s.key === 'website_form') || WF.sources[0] || {}).id); }
    bootstrap.Modal.getOrCreateInstance('#webFormModal').show();
  }
  $(document).on('click', '[data-action="new-web-form"]', () => openForm(null));
  $(document).on('click', '[data-wf-edit]', function () { openForm(WF.forms.find(f => f.id === $(this).data('wfEdit'))); });
  $(document).on('submit', '#webFormForm', function (e) {
    e.preventDefault();
    const $f = $(this), d = LF.formData($f), id = d.id; delete d.id;
    d.allowed_origins = (d.allowed_origins_text || '').split(/\s+/).map(x => x.trim()).filter(Boolean); delete d.allowed_origins_text;
    d.tags = (d.tags_text || '').split(',').map(x => x.trim()).filter(Boolean); delete d.tags_text;
    ['assigned_user_id', 'campaign_id', 'redirect_url'].forEach(k => { if (d[k] === '') d[k] = null; });
    LF.api(id ? 'PATCH' : 'POST', id ? 'web-forms/' + id : 'web-forms', d, { form: $f }).then(r => {
      bootstrap.Modal.getInstance('#webFormModal').hide(); LF.toast(t('Saved')); loadForms();
      if (!id) showEmbed(r.data);
    });
  });
  function showEmbed(f) {
    $('#webFormEmbedTitle').text(f.name); $('#wfEndpoint').val(f.endpoint); $('#wfEmbed').val(f.embed_html);
    $('#wfJsonExample').text(`fetch(${JSON.stringify(f.endpoint)}, {\n  method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },\n  body: JSON.stringify({ name, phone, email, message, consent_whatsapp: true, page_url: location.href,\n    utm_source, utm_medium, utm_campaign, fbclid })\n}).then(r => r.json()).then(j => j.ok ? showThanks() : showError(j.error.message));`);
    $('#wfSubmissions').html(`<div class="lf-loading">${t('Loading…')}</div>`);
    LF.api('GET', `web-forms/${f.id}/submissions`).then(r => {
      if (!r.data.length) { $('#wfSubmissions').html(`<div class="text-muted">${t('Nothing received yet. Submit the form once to test it.')}</div>`); return; }
      const B = { created: 'text-bg-success', linked: 'text-bg-primary', duplicate: 'text-bg-light border', invalid: 'text-bg-warning', rejected: 'text-bg-danger' };
      $('#wfSubmissions').html(`<div class="table-responsive"><table class="table table-sm mb-0"><tbody>${r.data.map(s => `<tr><td class="text-nowrap">${LF.fmt.dateTime(s.at)}</td><td><span class="badge ${B[s.status] || 'text-bg-light border'}">${LF.esc(t(s.status))}</span></td>
        <td>${s.lead_id ? `<a href="/leads/${s.lead_id}">#${s.lead_id}</a>` : ''}</td><td class="text-muted">${LF.esc(s.error || Object.entries(s.fields).slice(0, 4).map(([k, v]) => k + '=' + v).join(' · '))}</td></tr>`).join('')}</tbody></table></div>`);
    });
    bootstrap.Modal.getOrCreateInstance('#webFormEmbedModal').show();
  }
  $(document).on('click', '[data-wf-embed]', function () { showEmbed(WF.forms.find(f => f.id === $(this).data('wfEmbed'))); });
  $(document).on('click', '[data-wf-rotate]', function () {
    const id = $(this).data('wfRotate');
    LF.confirm({ title: t('Rotate key?'), body: t('The current endpoint URL stops working immediately. Update the form on your website with the new URL.'), confirmText: t('Rotate') })
      .then(() => LF.api('POST', `web-forms/${id}/rotate-key`).then(r => { loadForms(); showEmbed(r.data); }));
  });
  $(document).on('click', '[data-wf-delete]', function () {
    const id = $(this).data('wfDelete'), f = WF.forms.find(x => x.id === id);
    LF.confirm({ title: t('Delete form endpoint?'), body: `${f.name}: ${t('submissions stop immediately; leads already created are kept.')}`, confirmText: t('Delete'), danger: true })
      .then(() => LF.api('DELETE', `web-forms/${id}`).then(() => { LF.toast(t('Deleted')); loadForms(); }));
  });
  $(document).on('submit', '[data-int]', function (e) {
    e.preventDefault();
    const $f = $(this), key = $f.data('int'), d = LF.formData($f);
    Object.keys(d.config || {}).forEach(k => { if (d.config[k] === null) d.config[k] = ''; });
    if (key === 'meta_marketing') { d.config = d.config || {}; d.config.lead_ads_enabled = $f.find('#meta-lead-ads').prop('checked') ? '1' : '0'; }
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
  $(document).on('click', '[data-reveal]', function () { const $b = $(this); LF.api('POST', `integrations/${$b.data('reveal')}/verify-token`).then(r => $($b.data('target')).val(r.data.verify_token)); });
  $(document).on('click', '#rotateWh, [data-rotate]', function () {
    const p = $(this).data('rotate') || 'whatsapp_cloud';
    LF.confirm({ title: t('Rotate webhook'), body: t('The old URL stops working immediately. Update it in Meta right after.'), danger: true, confirmText: t('Rotate') })
      .then(() => LF.api('POST', `integrations/${p}/rotate-webhook`).then(load));
  });
  $(document).on('click', '#metaSync, #metaSync30', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', 'integrations/meta_marketing/sync', this.id === 'metaSync30' ? { days: 30 } : {}).then(r => {
      const d = r.data, la = d.lead_ads;
      LF.toast(`${d.campaigns} ${t('campaigns')} · ${d.spend_days} ${t('spend days')} (${d.currency} ${LF.fmt.num(d.spend_total)})${la ? ` · ${la.created} ${t('new form leads')}` : ''}`);
      load();
    }, load).always(() => LF.busy($b, false));
  });
  $(document).on('click', '#metaSubscribe', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', 'integrations/meta_marketing/subscribe-page').then(() => { LF.toast(t('Page subscribed to leadgen')); load(); }, load).always(() => LF.busy($b, false));
  });
  $(document).on('click', '#syncTpl', function () { const $b = $(this); LF.api('POST', 'integrations/whatsapp_cloud/templates/sync').then(r => { LF.toast(r.data.synced + ' ' + t('templates synced')); load(); }); });
  load();
})(jQuery, window.LF);
