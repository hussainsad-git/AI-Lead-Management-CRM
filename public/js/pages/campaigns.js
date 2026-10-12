(function ($, LF) {
  'use strict';
  let rows = [], current = null;
  const canManage = LF.can('campaigns.manage');
  $('.manage-only').toggleClass('hidden', !canManage);

  function load() {
    $('#camp-body').html(`<tr><td colspan="10">${LF.loading()}</td></tr>`);
    LF.api('GET', 'campaigns').then(r => {
      rows = r.data;
      $('#camp-body').html(rows.length ? rows.map(c => `<tr>
        <td><strong>${LF.esc(c.name)}</strong>${c.external_id ? `<div class="cell-muted">ID ${LF.esc(c.external_id)}</div>` : ''}${c.objective ? `<div class="cell-muted">${LF.esc(c.objective)}</div>` : ''}</td>
        <td>${LF.esc(LF.fmt.label(c.platform))}</td><td><span class="badge text-bg-light border">${LF.esc(c.status)}</span></td>
        <td class="text-end"><a href="/leads?campaign_ids=${c.id}">${c.leads}</a></td><td class="text-end">${c.qualified}</td><td class="text-end">${c.converted}</td>
        <td class="text-end">${c.spend === null ? '<span class="cell-muted">Not recorded</span>' : LF.fmt.money(c.spend, c.currency) + `<div class="cell-muted">${LF.esc(c.spend_from)} → ${LF.esc(c.spend_to)}</div>`}</td>
        <td class="text-end">${c.cpl === null ? '<span class="cell-muted">n/a</span>' : LF.fmt.money(c.cpl, c.currency)}</td>
        <td><span class="small">${c.data_source === 'meta_api' ? 'Meta API' : LF.esc(LF.fmt.label(c.data_source))}</span></td>
        <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" data-spend="${c.id}">Spend</button>${canManage ? ` <button class="btn btn-sm btn-light" data-edit="${c.id}" aria-label="Edit"><i class="bi bi-pencil"></i></button>` : ''}</td></tr>`).join('')
        : `<tr><td colspan="10">${LF.empty('megaphone', 'No campaigns yet. Add the campaigns you run so leads can be attributed to them.')}</td></tr>`);
    });
  }

  function openForm(c) {
    current = c || null;
    const $f = $('#campForm'); $f[0].reset(); LF.clearErrors($f);
    $('#campModalTitle').text(c ? 'Edit campaign' : 'New campaign');
    $('.cm-new').toggleClass('hidden', !!c);
    $('#cm-cur').val(LF.currency);
    if (c) { $('#cm-name').val(c.name); $('#cm-platform').val(c.platform); $('#cm-status').val(c.status); $('#cm-ext').val(c.external_id); $('#cm-start').val(c.starts_on); $('#cm-end').val(c.ends_on); $('#cm-obj').val(c.objective); }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('campModal')).show();
  }
  $('#newCampaign').on('click', () => openForm(null));
  $(document).on('click', '[data-edit]', function () { openForm(rows.find(r => r.id === $(this).data('edit'))); });
  $('#campForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), data = LF.formData($f), $btn = $f.find('[type="submit"]');
    if (current) delete data.currency;
    LF.busy($btn, true);
    LF.api(current ? 'PATCH' : 'POST', current ? 'campaigns/' + current.id : 'campaigns', data, { form: $f })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('campModal')).hide(); LF.toast('Campaign saved'); LF.lookups(true); load(); }).always(() => LF.busy($btn, false));
  });

  function loadSpend() {
    LF.api('GET', `campaigns/${current.id}/spend`).then(r => {
      $('#spend-body').html(r.data.length ? r.data.map(s => `<tr><td>${LF.esc(String(s.spend_date).slice(0, 10))}</td><td class="text-end">${LF.fmt.money(s.amount, s.currency)}</td><td>${LF.esc(LF.fmt.label(s.data_source))}</td>
        <td class="text-end">${canManage && s.data_source === 'manual' ? `<button class="btn btn-sm btn-link text-danger p-0" data-del-spend="${s.id}">Delete</button>` : ''}</td></tr>`).join('') : `<tr><td colspan="4">${LF.empty('cash', 'No spend recorded.')}</td></tr>`);
    });
  }
  $(document).on('click', '[data-spend]', function () {
    current = rows.find(r => r.id === $(this).data('spend'));
    $('#spendModalTitle').text('Daily spend · ' + current.name); $('#sp-cur').text(current.currency);
    $('#spendForm')[0].reset(); $('#sp-date').val(LF.localDate(new Date(Date.now() - 86400e3)));
    $('#spendForm').toggleClass('hidden', !canManage || current.data_source === 'meta_api');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('spendModal')).show();
    loadSpend();
  });
  $('#spendForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this);
    LF.api('POST', `campaigns/${current.id}/spend`, LF.formData($f), { form: $f }).then(() => { LF.toast('Spend saved'); $('#sp-amount').val(''); loadSpend(); load(); });
  });
  $(document).on('click', '[data-del-spend]', function () {
    const sid = $(this).data('del-spend');
    LF.api('DELETE', `campaigns/${current.id}/spend/${sid}`).then(() => { loadSpend(); load(); });
  });
  load();
})(jQuery, window.LF);
