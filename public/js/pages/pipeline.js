(function ($, LF) {
  'use strict';
  let cols = [], timer;

  function card(l) {
    const overdue = l.next_follow_up_at && new Date(l.next_follow_up_at) < new Date();
    return `<div class="kanban-card" data-id="${l.id}" tabindex="0">
      <div class="d-flex"><a class="name lf-row-link" href="/leads/${l.id}">${LF.esc(l.contact.display_name)}</a><span class="ms-auto">${LF.priorityBadge(l.priority)}</span></div>
      <div class="meta">${LF.esc(l.contact.phone || l.contact.whatsapp || l.contact.email || '')}${l.contact.do_not_contact ? ' <span class="dnc">DNC</span>' : ''}</div>
      <div class="meta">${LF.esc(l.attribution && l.attribution.campaign ? l.attribution.campaign : (l.source ? l.source.name : ''))}</div>
      <div class="meta d-flex mt-1"><span>${LF.esc(l.assignee ? l.assignee.name : 'Unassigned')}</span>
        <span class="ms-auto ${overdue ? 'overdue' : ''}">${l.next_follow_up_at ? '<i class="bi bi-alarm"></i> ' + LF.fmt.rel(l.next_follow_up_at) : LF.fmt.rel(l.last_activity_at)}</span></div></div>`;
  }

  function render() {
    $('#board').html(cols.map(c => `<section class="kanban-col ${c.collapsed ? 'collapsed' : ''}" aria-label="${LF.esc(c.status.name)}">
      <div class="kanban-col-head">${LF.statusBadge(c.status)}<span class="count">${LF.fmt.num(c.count)}</span></div>
      <div class="kanban-list" data-status="${c.status.id}">${c.collapsed ? `<div class="kanban-drop-hint">Drop here to move to ${LF.esc(c.status.name)}</div>` : (c.cards.map(card).join('') || '<div class="kanban-drop-hint">No leads</div>')}
      ${!c.collapsed && c.count > c.cards.length ? `<a class="small d-block text-center py-1" href="/leads?status=${c.status.id}">+${c.count - c.cards.length} more in table view</a>` : ''}</div></section>`).join(''));
    if (!LF.can('leads.update')) return;
    document.querySelectorAll('.kanban-list').forEach(el => new Sortable(el, {
      group: 'pipeline', animation: 120, forceFallback: true, fallbackTolerance: 4, ghostClass: 'kanban-ghost', draggable: '.kanban-card', filter: 'a', preventOnFilter: false,
      onAdd: evt => move(evt),
    }));
  }

  function move(evt) {
    const leadId = parseInt(evt.item.dataset.id, 10);
    const to = cols.find(c => String(c.status.id) === evt.to.dataset.status).status;
    const revert = () => { evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] || null); };
    const go = reason => LF.api('POST', `leads/${leadId}/status`, { lead_status_id: to.id, reason: reason || null })
      .then(() => { LF.toast('Moved to ' + to.name); load(); }, () => { revert(); });
    if (to.requires_reason !== 'none' || to.category !== 'open') {
      LF.confirm({ title: 'Move to ' + to.name, body: to.semantic === 'do_not_contact' ? 'Opts the contact out of all channels and cancels pending follow-ups.' : 'Closes the lead and cancels pending follow-ups.',
        reason: true, reasonRequired: to.requires_reason !== 'none', reasonLabel: to.requires_reason === 'opt_out_reason' ? 'Opt-out reason' : (to.category === 'won' ? 'Note (optional)' : 'Lost reason'), danger: to.category === 'lost', confirmText: 'Move' })
        .then(go, revert);
    } else go();
  }

  function load() {
    const params = { q: $('#p-q').val(), include_closed: $('#p-closed').is(':checked') ? 1 : 0 };
    if ($('#p-user').val()) params['assigned_user_ids[]'] = $('#p-user').val();
    if ($('#p-source').val()) params['source_ids[]'] = $('#p-source').val();
    LF.api('GET', 'pipeline', params).then(r => { cols = r.data; render(); });
  }

  LF.fillFromLookups($('.lf-actions')).then(lk => {
    $('#p-user').html(['<option value="">Everyone</option>', `<option value="${LF.userId}">Me</option>`, '<option value="unassigned">Unassigned</option>'].concat(lk.users.filter(u => u.id !== LF.userId).map(u => `<option value="${u.id}">${LF.esc(u.name)}</option>`)).join(''));
    $('#board').html(LF.loading());
    load();
  });
  $('#p-q').on('input', () => { clearTimeout(timer); timer = setTimeout(load, 350); });
  $('#p-user, #p-source, #p-closed').on('change', load);
  $('#board').on('keydown', '.kanban-card', function (e) { if (e.key === 'Enter') window.location.href = '/leads/' + $(this).data('id'); });
})(jQuery, window.LF);
