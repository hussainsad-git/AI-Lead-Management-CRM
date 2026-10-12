(function ($, LF) {
  'use strict';
  let page = 1, timer;
  function load() {
    $('#c-body').html(`<tr><td colspan="7">${LF.loading()}</td></tr>`);
    LF.api('GET', 'contacts', { q: $('#c-q').val(), opt_out: $('#c-opt').val(), page, per_page: 25 }).then(r => {
      $('#c-body').html(r.data.length ? r.data.map(c => `<tr>
        <td><a class="lf-row-link" href="/contacts/${c.id}">${LF.esc(c.display_name)}</a>${c.do_not_contact ? ' <span class="dnc">DNC</span>' : ''}${c.company_name && c.name ? `<div class="cell-muted">${LF.esc(c.company_name)}</div>` : ''}</td>
        <td>${LF.phoneLink(c.phone)}</td><td>${LF.esc(c.whatsapp || '')}</td><td>${LF.esc(c.email || '')}</td><td>${LF.esc(c.city || '')}</td>
        <td class="text-end">${c.leads_count}</td><td>${LF.fmt.date(c.created_at)}</td></tr>`).join('') : `<tr><td colspan="7">${LF.empty('person-vcard', 'No contacts found.')}</td></tr>`);
      $('#c-pager').html(LF.pager(r.meta));
    });
  }
  $('#c-q').on('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 300); });
  $('#c-opt').on('change', () => { page = 1; load(); });
  $('#c-pager').on('click', '[data-page]', function () { page = $(this).data('page'); load(); });
  load();
})(jQuery, window.LF);
