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
