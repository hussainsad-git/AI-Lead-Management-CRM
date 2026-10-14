(function ($, LF) {
  'use strict';
  const t = LF.t;
  LF.api('GET', 'profile').then(r => {
    const p = r.data;
    $('#p-name').val(p.name); $('#p-email').val(p.email); $('#p-phone').val(p.phone);
    $('#p-locale').html(`<option value="">${t('Organization default')}</option>` + Object.entries(p.locales).map(([k, v]) => `<option value="${k}" ${k === p.locale ? 'selected' : ''}>${LF.esc(v.native)}</option>`).join(''));
    $('#p-tz').html(`<option value="">${t('Organization default')}</option>` + p.timezones.map(z => `<option ${z === p.timezone ? 'selected' : ''}>${LF.esc(z)}</option>`).join(''));
    $('#mailOff').toggleClass('d-none', p.notifications.email_enabled);
    $('#notifTypes').html(p.notifications.types.map(n => `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="nt-${n.key}" data-key="${n.key}" ${n.email ? 'checked' : ''}><label class="form-check-label" for="nt-${n.key}">${LF.esc(t(n.label))}</label></div>`).join(''));
    $('#p-roles').text(t('Roles') + ': ' + p.roles.join(', ') + (p.team ? ' · ' + t('Team') + ': ' + p.team : ''));
  });
  $('#profileForm').on('submit', function (e) {
    e.preventDefault();
    const before = document.body.dataset.locale;
    LF.api('PATCH', 'profile', LF.formData($(this)), { form: $(this) }).then(() => {
      LF.toast(t('Profile saved'));
      if (($('#p-locale').val() || before) !== before) setTimeout(() => location.reload(), 600);
    });
  });
  function loadTokens() {
    if (!$('#tokenList').length) return;
    LF.api('GET', 'api-tokens').then(r => {
      $('#tokenList').html(r.data.length ? `<table class="table table-sm small mb-0"><thead><tr><th>${t('Name')}</th><th>${t('Token')}</th><th>${t('Last used')}</th><th>${t('Expires')}</th><th></th></tr></thead><tbody>` + r.data.map(x => `<tr><td>${LF.esc(x.name)}</td><td><code>${LF.esc(x.prefix)}</code></td><td>${x.last_used_at ? LF.fmt.rel(x.last_used_at) + (x.last_used_ip ? ' · ' + LF.esc(x.last_used_ip) : '') : t('never')}</td><td>${x.expires_at ? LF.fmt.date(x.expires_at) : t('never')}</td><td class="text-end"><button class="btn btn-sm btn-link text-danger p-0" data-revoke="${x.id}">${t('Revoke')}</button></td></tr>`).join('') + '</tbody></table>'
        : `<div class="small text-muted">${t('No active tokens.')}</div>`);
    });
  }
  $('#newToken').on('click', () => {
    LF.confirm({ title: t('New API token'), reason: true, reasonRequired: true, reasonLabel: t('Name (what will use it?)'), confirmText: t('Create') }).then(name =>
      LF.api('POST', 'api-tokens', { name, expires_in_days: 365 }).then(r => {
        $('#tokenReveal').removeClass('d-none').html(`<div class="fw-semibold mb-1">${t('Copy your token now — it will not be shown again.')}</div><div class="input-group input-group-sm"><input class="form-control font-monospace" readonly id="tokPlain" value="${LF.esc(r.data.token)}"><button class="btn btn-light" type="button" data-copy="#tokPlain">${t('Copy')}</button></div><div class="text-muted mt-1">${t('Expires in 365 days.')}</div>`);
        loadTokens();
      }));
  });
  $(document).on('click', '[data-copy]', function () { const v = $($(this).data('copy')).val(); navigator.clipboard && navigator.clipboard.writeText(v).then(() => LF.toast(t('Copied'))); });
  $(document).on('click', '[data-revoke]', function () {
    const id = $(this).data('revoke');
    LF.confirm({ title: t('Revoke token?'), body: t('Anything using it stops working immediately.'), danger: true, confirmText: t('Revoke') }).then(() => LF.api('DELETE', 'api-tokens/' + id).then(() => { $('#tokenReveal').addClass('d-none'); loadTokens(); }));
  });
  loadTokens();
  $('#notifForm').on('submit', function (e) {
    e.preventDefault();
    const email = {};
    $('#notifTypes input').each(function () { email[this.dataset.key] = this.checked; });
    LF.api('PUT', 'profile/notifications', { email }).then(() => LF.toast(t('Preferences saved')));
  });
  $('#pwForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this);
    LF.api('POST', 'profile/password', LF.formData($f), { form: $f }).then(() => { $f[0].reset(); LF.toast(t('Password changed')); });
  });
})(jQuery, window.LF);
(function ($, LF) {
  'use strict';
  const t = LF.t, esc = LF.esc;
  const $body = $('#tfaBody'), $badge = $('#tfaBadge');
  if (!$body.length) return;
  const codesHtml = codes => `<div class="alert alert-warning small"><div class="fw-semibold mb-1">${t('Recovery codes — save these somewhere safe. Each works once.')}</div><div class="font-monospace">${codes.map(esc).join('<br>')}</div><button class="btn btn-sm btn-light mt-2" type="button" id="tfaCopyCodes">${t('Copy')}</button></div>`;
  function render(afterHtml) {
    return LF.api('GET', 'profile/two-factor').then(r => {
      const s = r.data;
      $badge.html(s.enabled ? `<span class="badge text-bg-success">${t('Enabled')}</span>` : `<span class="badge ${s.required ? 'text-bg-danger' : 'text-bg-light border'}">${s.required ? t('Required — not set up') : t('Off')}</span>`);
      if (s.enabled) {
        $body.html(`<div class="small">${t('Enabled')} ${LF.fmt.date(s.enabled_at)} · ${s.recovery_codes_left} ${t('recovery codes left')}</div>
          <form class="row g-2 mt-2 align-items-end" id="tfaForm"><div class="col-sm-6"><label class="form-label small" for="tfa-pw">${t('Current password')}</label><input class="form-control form-control-sm" type="password" id="tfa-pw" name="current_password" autocomplete="current-password"></div>
          <div class="col-sm-6 d-flex gap-2"><button class="btn btn-light btn-sm" type="button" id="tfaRegen">${t('New recovery codes')}</button>${s.required ? '' : `<button class="btn btn-outline-danger btn-sm" type="button" id="tfaDisable">${t('Disable')}</button>`}</div></form><div id="tfaOut" class="mt-2"></div>`);
      } else {
        $body.html(`<p class="small text-muted mb-2">${t('Protect your account with a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).')}</p><button class="btn btn-primary btn-sm" type="button" id="tfaBegin"><i class="bi bi-shield-lock"></i> ${t('Set up two-factor')}</button><div id="tfaOut" class="mt-3"></div>`);
        if (location.search.includes('setup=2fa')) $('#tfaBegin').trigger('click');
      }
      if (afterHtml) $('#tfaOut').html(afterHtml);
    });
  }
  $(document).on('click', '#tfaBegin', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', 'profile/two-factor/begin').then(r => {
      $('#tfaOut').html(`<div class="row g-3 align-items-start"><div class="col-auto">${r.data.qr_svg}</div><div class="col">
        <div class="small">${t('1. Scan the code with your authenticator app, or enter this key manually:')}</div><code class="d-block my-1 user-select-all">${esc(r.data.secret)}</code>
        <div class="small mb-2">${t('2. Enter the 6-digit code the app shows:')}</div>
        <form class="input-group input-group-sm mw-260" id="tfaConfirm"><input class="form-control text-center" inputmode="numeric" autocomplete="one-time-code" maxlength="6" name="code" aria-label="${t('Code')}" required><button class="btn btn-primary" type="submit">${t('Confirm')}</button></form></div></div>`);
      $('#tfaConfirm input').trigger('focus');
    }).always(() => LF.busy($b, false));
  });
  $(document).on('submit', '#tfaConfirm', function (e) {
    e.preventDefault();
    const $f = $(this);
    LF.api('POST', 'profile/two-factor/confirm', { code: $f.find('input').val() }, { form: $f }).then(r => {
      LF.toast(t('Two-factor enabled'));
      render(codesHtml(r.data.recovery_codes));
    });
  });
  $(document).on('click', '#tfaCopyCodes', function () { const txt = $(this).siblings('.font-monospace').text(); navigator.clipboard && navigator.clipboard.writeText(txt).then(() => LF.toast(t('Copied'))); });
  $(document).on('click', '#tfaRegen', function () {
    LF.api('POST', 'profile/two-factor/recovery-codes', { current_password: $('#tfa-pw').val() }, { form: $('#tfaForm') }).then(r => { $('#tfaOut').html(codesHtml(r.data.recovery_codes)); $('#tfa-pw').val(''); });
  });
  $(document).on('click', '#tfaDisable', function () {
    LF.confirm({ title: t('Disable two-factor?'), body: t('Your account will be protected by your password only.'), danger: true, confirmText: t('Disable') })
      .then(() => LF.api('POST', 'profile/two-factor/disable', { current_password: $('#tfa-pw').val() }, { form: $('#tfaForm') }).then(() => { LF.toast(t('Two-factor disabled')); render(); }));
  });
  render();
})(jQuery, window.LF);
