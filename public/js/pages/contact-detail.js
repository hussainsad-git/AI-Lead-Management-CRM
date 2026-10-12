(function ($, LF) {
  'use strict';
  const id = parseInt(document.body.dataset.contactId, 10);
  let D = null;

  function render() {
    const c = D.contact;
    document.title = c.display_name + ' · LeadFlow';
    $('#crumb').text(c.display_name);
    const html = `
      <div class="lf-page-head"><div><h1>${LF.esc(c.display_name)} ${c.do_not_contact ? '<span class="dnc align-middle">DO NOT CONTACT</span>' : ''}</h1>
        <div class="small text-muted">Contact #${c.id} · ${LF.esc(LF.fmt.label(c.type))} · since ${LF.fmt.date(c.created_at)}</div></div>
        <div class="lf-actions">${LF.can('leads.create') ? '<button class="btn btn-primary btn-sm" id="newInquiry"><i class="bi bi-plus-lg"></i> New inquiry</button>' : ''}</div></div>
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card mb-3"><div class="card-header">Details</div><div class="card-body"><dl class="dl-grid">
            ${[['Name', c.name], ['Company', c.company_name], ['Phone', c.phone], ['Entered as', c.phone_raw !== c.phone ? c.phone_raw : null], ['WhatsApp', c.whatsapp], ['Email', c.email], ['City', c.city], ['Country', c.country_code], ['Prefers', LF.fmt.label(c.preferred_contact_method)]]
              .filter(r => r[1]).map(([k, v]) => `<dt>${LF.esc(k)}</dt><dd>${LF.esc(v)}</dd>`).join('')}
          </dl></div></div>
          <div class="card mb-3"><div class="card-header d-flex">Consent &amp; suppression${LF.can('consent.manage') && !c.do_not_contact ? '<button class="btn btn-sm btn-link text-danger ms-auto p-0" id="optOutAll">Opt out of everything</button>' : ''}</div><div class="card-body">
            <div class="consent-grid mb-2">${Object.entries(D.consent).map(([ch, v]) => `<div class="consent-item"><div class="text-muted">${LF.esc(LF.fmt.label(ch))}</div><div class="v-${LF.esc(v)}">${LF.esc(LF.fmt.label(v))}</div></div>`).join('')}</div>
            ${D.suppressions.length ? '<div class="small-caps mt-3 mb-1">Suppressions</div><ul class="list-unstyled small mb-2">' + D.suppressions.map(s => `<li class="d-flex gap-2 mb-1"><span>${LF.esc(LF.fmt.label(s.channel))}: ${LF.esc(s.reason)} <span class="text-muted">(${LF.fmt.date(s.created_at)})</span></span>${s.lifted_at ? `<span class="text-muted ms-auto">lifted ${LF.fmt.date(s.lifted_at)}</span>` : (LF.can('consent.manage') && LF.can('contacts.merge', 'settings.manage') ? `<button class="btn btn-sm btn-link p-0 ms-auto" data-lift="${s.id}">Lift</button>` : '')}</li>`).join('') + '</ul>' : ''}
            ${D.consent_records.length ? '<div class="small-caps mt-3 mb-1">Consent ledger</div><ul class="list-unstyled small mb-0">' + D.consent_records.slice(0, 10).map(r => `<li>${LF.fmt.date(r.at)} · ${LF.esc(LF.fmt.label(r.channel))} <strong>${LF.esc(r.status)}</strong> · ${LF.esc(LF.fmt.label(r.source))}${r.by ? ' · ' + LF.esc(r.by) : ''}${r.evidence ? `<div class="text-muted">${LF.esc(r.evidence)}</div>` : ''}</li>`).join('') + '</ul>' : '<div class="small text-muted">No consent recorded.</div>'}
          </div></div>
        </div>
        <div class="col-lg-7">
          <div class="card mb-3"><div class="card-header">Inquiries (leads)</div>${D.leads.length ? '<ul class="list-group list-group-flush">' + D.leads.map(l => `<li class="list-group-item d-flex align-items-center gap-2"><a class="lf-row-link" href="/leads/${l.id}">Lead #${l.id}</a>${LF.statusBadge(l.status)}${l.archived ? '<span class="badge text-bg-secondary">Archived</span>' : ''}<span class="ms-auto small text-muted">${LF.esc(l.source || '')} · ${LF.esc(l.assignee || 'Unassigned')} · ${LF.fmt.date(l.created_at)}</span></li>`).join('') + '</ul>' : `<div class="card-body">${LF.empty('inbox', 'No visible leads.')}</div>`}</div>
          ${LF.can('contacts.merge') ? '<div class="card mb-3"><div class="card-header">Possible duplicates</div><div class="card-body" id="dupBox">' + LF.loading('Checking…') + '</div></div>' : ''}
          ${D.activity.length ? '<div class="card"><div class="card-header">Contact-level activity</div><ul class="list-group list-group-flush">' + D.activity.map(a => `<li class="list-group-item small">${LF.esc(LF.fmt.label(a.type))}${a.body ? ': ' + LF.esc(a.body) : ''}<span class="text-muted float-end">${LF.esc(a.user ? a.user.name : 'System')} · ${LF.fmt.rel(a.occurred_at)}</span></li>`).join('') + '</ul></div>' : ''}
        </div>
      </div>`;
    $('#contactRoot').html(html);
    if (LF.can('contacts.merge')) loadDuplicates();
  }

  function loadDuplicates() {
    LF.api('GET', `contacts/${id}/duplicates`).then(r => {
      if (!r.data.length) { $('#dupBox').html('<div class="small text-muted">No likely duplicates found.</div>'); return; }
      $('#dupBox').html('<p class="small text-muted">Strong = identical normalized phone/email. Weak = same name only — verify before merging. Nothing is merged automatically.</p><ul class="list-group">' + r.data.map(m => `<li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
        <span class="badge ${m.strength === 'strong' ? 'text-bg-warning' : 'text-bg-light border'}">${LF.esc(m.strength)}</span>
        <div><a href="/contacts/${m.contact.id}">${LF.esc(m.contact.display_name)}</a><div class="small text-muted">${LF.esc([m.contact.phone, m.contact.whatsapp, m.contact.email].filter(Boolean).join(' · '))} · matches on ${LF.esc(m.reasons.join(', '))} · ${m.leads_count} lead(s)</div></div>
        <button class="btn btn-sm btn-outline-primary ms-auto" data-merge="${m.contact.id}" data-name="${LF.esc(m.contact.display_name)}">Merge into this record</button></li>`).join('') + '</ul>');
    });
  }

  function load() { return LF.api('GET', 'contacts/' + id).then(r => { D = r.data; render(); }, err => { if (err.status === 404) $('#contactRoot').html(LF.empty('question-circle', 'Contact not found or not shared with you.')); }); }

  $(document).on('click', '[data-merge]', function () {
    const src = $(this).data('merge');
    LF.confirm({ title: 'Merge contacts', danger: true, confirmText: 'Merge',
      html: `<p>Merge <strong>${LF.esc($(this).data('name'))}</strong> (#${src}) into <strong>${LF.esc(D.contact.display_name)}</strong> (#${id})?</p><ul class="small"><li>All leads, timeline, consent and suppression records move to this contact.</li><li>Only empty fields here are filled; nothing here is overwritten.</li><li>The other record is archived and the merge is audited. This cannot be undone from the UI.</li></ul>` })
      .then(() => LF.api('POST', `contacts/${id}/merge`, { source_contact_id: src, confirm: true }).then(() => { LF.toast('Contacts merged'); load(); }));
  });
  $(document).on('click', '#optOutAll', () => {
    LF.confirm({ title: 'Opt out of all contact', reason: true, reasonRequired: true, reasonLabel: 'Reason / how they asked', danger: true, confirmText: 'Opt out', body: 'Withdraws consent on all channels, suppresses the contact and cancels pending follow-ups.' })
      .then(reason => LF.api('POST', `contacts/${id}/opt-out`, { channel: 'all', reason }).then(() => { LF.toast('Opted out'); load(); }));
  });
  $(document).on('click', '[data-lift]', function () {
    const sid = $(this).data('lift');
    LF.confirm({ title: 'Lift suppression', reason: true, reasonRequired: true, reasonLabel: 'Why is contact allowed again? (audited)', confirmText: 'Lift' })
      .then(reason => LF.api('POST', `suppressions/${sid}/lift`, { reason }).then(() => { LF.toast('Suppression lifted'); load(); }));
  });
  $(document).on('click', '#newInquiry', () => {
    LF.leadModal.open(d => { window.location.href = '/leads/' + d.id; });
    setTimeout(() => {
      const c = D.contact;
      $('#lf-name').val(c.name); $('#lf-company').val(c.company_name); $('#lf-phone').val(c.phone); $('#lf-wa').val(c.whatsapp); $('#lf-email').val(c.email).trigger('input');
    }, 350);
  });
  load();
})(jQuery, window.LF);
