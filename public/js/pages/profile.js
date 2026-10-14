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
