(function ($, LF) {
  'use strict';
  let users = [], teams = [], roles = [], editing = null, editingTeam = null;

  $(document).on('click', '[data-reset-2fa]', function () {
    const id = $(this).data('reset-2fa'), u = users.find(x => x.id === id);
    LF.confirm({ title: 'Reset two-factor authentication', body: `${u.name} will be signed out and can sign in with their password only until they enrol again. Do this only after verifying their identity.`, danger: true, confirmText: 'Reset' })
      .then(() => LF.api('POST', `users/${id}/reset-two-factor`).then(() => { LF.toast('Two-factor reset'); load(); }));
  });
  function load() {
    return $.when(LF.api('GET', 'users'), LF.api('GET', 'teams'), LF.api('GET', 'roles')).then((u, t, r) => {
      users = u.data; teams = t.data; roles = r.data.roles;
      $('#usersBody').html(users.map(x => `<tr class="${x.is_active ? '' : 'text-muted'}"><td><strong>${LF.esc(x.name)}</strong><div class="cell-muted">${LF.esc(x.email)}</div></td>
        <td>${x.roles.map(r => `<span class="tag">${LF.esc(r.name)}</span>`).join('')}</td><td>${LF.esc(x.team ? x.team.name : '—')}</td><td class="text-end">${x.open_leads}</td>
        <td>${x.accepts_assignments ? '<i class="bi bi-check2 text-success" aria-label="Yes"></i>' : '<span class="cell-muted">No</span>'}</td>
        <td class="small">${x.last_login_at ? LF.fmt.rel(x.last_login_at) : 'Never'}${x.two_factor ? ' <i class="bi bi-shield-lock-fill text-success" title="Two-factor enabled" aria-label="Two-factor enabled"></i>' : ''}</td><td>${x.is_active ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>'}</td>
        <td class="text-end text-nowrap">${x.two_factor ? `<button class="btn btn-sm btn-light" data-reset-2fa="${x.id}" title="Reset two-factor" aria-label="Reset two-factor for ${LF.esc(x.name)}"><i class="bi bi-shield-slash"></i></button> ` : ''}<button class="btn btn-sm btn-light" data-user="${x.id}" aria-label="Edit ${LF.esc(x.name)}"><i class="bi bi-pencil"></i></button></td></tr>`).join(''));
      $('#teamsList').html(teams.length ? teams.map(t => `<li class="list-group-item d-flex align-items-center"><div><strong>${LF.esc(t.name)}</strong><div class="small text-muted">Manager: ${LF.esc(t.manager ? t.manager.name : '—')} · ${t.members_count} member(s)</div></div><button class="btn btn-sm btn-light ms-auto" data-team="${t.id}" aria-label="Edit team"><i class="bi bi-pencil"></i></button></li>`).join('') : '<li class="list-group-item small text-muted">No teams yet.</li>');
    });
  }

  function openUser(u) {
    editing = u || null;
    const $f = $('#userForm'); $f[0].reset(); LF.clearErrors($f);
    $('#userModalTitle').text(u ? 'Edit ' + u.name : 'Add user');
    $('#u-pass-hint').text(u ? '(leave blank to keep current; changing it signs the user out)' : '(min 10 characters, letters and numbers)');
    $('#u-team').html('<option value="">No team</option>' + teams.map(t => `<option value="${t.id}">${LF.esc(t.name)}</option>`).join(''));
    $('#u-roles').html(roles.map(r => `<div class="form-check"><input class="form-check-input" type="checkbox" id="role-${r.id}" value="${r.id}" ${u && u.roles.some(x => x.id === r.id) ? 'checked' : ''}><label class="form-check-label" for="role-${r.id}">${LF.esc(r.name)} <span class="small text-muted">— ${LF.esc(r.description || '')}</span></label></div>`).join(''));
    $('#u-assign').prop('checked', u ? u.accepts_assignments : true);
    $('#u-active').prop('checked', u ? u.is_active : true);
    if (u) { $('#u-name').val(u.name); $('#u-email').val(u.email); $('#u-phone').val(u.phone); $('#u-team').val(u.team ? u.team.id : ''); }
    const self = u && u.id === LF.userId;
    $('#u-roles input, #u-active').prop('disabled', self);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
  }
  $('#newUser').on('click', () => openUser(null));
  $(document).on('click', '[data-user]', function () { openUser(users.find(u => u.id === $(this).data('user'))); });
  $('#userForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]'), d = LF.formData($f);
    const data = { name: d.name, email: d.email, phone: d.phone, team_id: d.team_id ? parseInt(d.team_id, 10) : null, accepts_assignments: $('#u-assign').is(':checked') };
    if (d.password) data.password = d.password;
    if (!(editing && editing.id === LF.userId)) { data.role_ids = $('#u-roles input:checked').map((i, el) => parseInt(el.value, 10)).get(); data.is_active = $('#u-active').is(':checked'); }
    LF.busy($btn, true);
    LF.api(editing ? 'PATCH' : 'POST', editing ? 'users/' + editing.id : 'users', data, { form: $f }).then(() => { bootstrap.Modal.getInstance(document.getElementById('userModal')).hide(); LF.toast('User saved'); LF.lookups(true); load(); }).always(() => LF.busy($btn, false));
  });

  function openTeam(t) {
    editingTeam = t || null;
    const $f = $('#teamForm'); $f[0].reset(); LF.clearErrors($f);
    $('#teamModalTitle').text(t ? 'Edit team' : 'New team');
    $('#t-manager').html('<option value="">No manager</option>' + users.filter(u => u.is_active).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`).join(''));
    if (t) { $('#t-name').val(t.name); $('#t-manager').val(t.manager ? t.manager.id : ''); }
    $('#t-delete').toggleClass('hidden', !t);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('teamModal')).show();
  }
  $('#newTeam').on('click', () => openTeam(null));
  $(document).on('click', '[data-team]', function () { openTeam(teams.find(t => t.id === $(this).data('team'))); });
  $('#teamForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), d = LF.formData($f);
    LF.api(editingTeam ? 'PATCH' : 'POST', editingTeam ? 'teams/' + editingTeam.id : 'teams', { name: d.name, manager_user_id: d.manager_user_id ? parseInt(d.manager_user_id, 10) : null }, { form: $f })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('teamModal')).hide(); LF.toast('Team saved'); load(); });
  });
  $('#t-delete').on('click', () => {
    bootstrap.Modal.getInstance(document.getElementById('teamModal')).hide();
    LF.confirm({ title: 'Delete team', body: 'Members stay in the organization without a team.', danger: true, confirmText: 'Delete' }).then(() => LF.api('DELETE', 'teams/' + editingTeam.id).then(load));
  });
  load();
})(jQuery, window.LF);
