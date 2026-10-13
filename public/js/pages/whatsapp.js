(function ($, LF) {
  'use strict';
  const t = LF.t;
  const S = { view: 'all', q: '', convId: null, data: null, lastId: 0, listTimer: null, threadTimer: null, users: [] };
  const canSend = LF.can('whatsapp.send');
  const canTeach = LF.can('kb.manage');

  const SENDER = { ai: t('AI assistant'), auto_reply: t('Auto-reply'), system: t('System'), user: '' };
  const STATUS_ICON = {
    queued: '<i class="bi bi-clock" title="Queued locally"></i>',
    accepted: '<i class="bi bi-check" title="Accepted by WhatsApp"></i>',
    sent: '<i class="bi bi-check" title="Sent"></i>',
    delivered: '<i class="bi bi-check-all" title="Delivered"></i>',
    read: '<i class="bi bi-check-all tick-read" title="Read"></i>',
    failed: '<i class="bi bi-exclamation-circle text-danger" title="Failed"></i>',
  };

  // ---------- list ----------
  function loadList() {
    return LF.api('GET', 'conversations', { view: S.view, q: S.q, per_page: 50 }, { silent: true }).then(r => {
      Object.entries(r.meta.counts).forEach(([k, v]) => $(`[data-c="${k}"]`).text(v || ''));
      $('#convList').html(r.data.length ? r.data.map(c => `<button class="conv-item ${c.id === S.convId ? 'active' : ''}" data-conv="${c.id}">
          <div class="d-flex align-items-center gap-1"><span class="nm text-truncate">${LF.esc(c.contact_name || '+' + c.wa_id)}</span>
            ${c.handoff ? '<i class="bi bi-person-raised-hand text-danger" title="Needs a human"></i>' : ''}
            ${c.score_label ? `<span class="score-pill score-${LF.esc(c.score_label)}">${LF.esc(t(c.score_label.replace('_', ' ')))}</span>` : ''}
            <span class="tm ms-auto">${LF.fmt.rel(c.last_message_at)}</span></div>
          <div class="d-flex align-items-center gap-1"><span class="pv flex-grow-1" dir="auto">${LF.esc(c.preview || '')}</span>${c.unread ? `<span class="unread-dot">${c.unread}</span>` : ''}</div>
          <div class="tm">${LF.esc(c.assignee ? c.assignee.name : t('Unassigned'))}${c.ai_mode === 'off' ? ' · AI off' : ''}${c.opted_out ? ' · <span class="text-danger">opted out</span>' : ''}</div>
        </button>`).join('') : LF.empty('chat-dots', S.view === 'all' ? t('No open conversations. New WhatsApp messages appear here automatically.') : t('Nothing here.')));
    }, () => {});
  }

  // ---------- thread ----------
  function bubble(m) {
    const out = m.direction === 'out';
    const cls = out ? (m.sender_type === 'ai' ? 'ai' : m.sender_type === 'auto_reply' ? 'auto' : '') + (m.status === 'failed' ? ' failed' : '') : '';
    const who = out ? (SENDER[m.sender_type] || m.user || '') : '';
    const fb = out && m.sender_type === 'ai' && m.interaction_id ? `
      <button class="fb ${m.feedback === 'up' ? 'on' : ''}" data-fb="up" data-int="${m.interaction_id}" title="${t('Good answer')}" aria-label="${t('Good answer')}"><i class="bi bi-hand-thumbs-up"></i></button>
      <button class="fb ${m.feedback === 'down' ? 'on' : ''}" data-fb="down" data-int="${m.interaction_id}" title="${t('Wrong answer')}" aria-label="${t('Wrong answer')}"><i class="bi bi-hand-thumbs-down"></i></button>
      ${canTeach ? `<button class="fb" data-teach="${m.interaction_id}" data-msg="${m.id}" title="${t('Correct & teach')}" aria-label="${t('Correct & teach')}"><i class="bi bi-mortarboard"></i></button>` : ''}` : '';
    return `<div class="bubble-row ${out ? 'out' : 'in'}" data-mid="${m.id}"><div class="bubble ${cls}">
      ${who ? `<div class="who">${LF.esc(who)}</div>` : ''}${m.referral ? `<div class="small text-muted mb-1"><i class="bi bi-megaphone"></i> ${t('From ad')}: ${LF.esc(m.referral)}</div>` : ''}${m.template ? `<div class="small text-muted">Template: ${LF.esc(m.template)}</div>` : ''}<div class="txt" dir="auto">${LF.esc(m.body || '[' + m.type + ']')}</div>
      ${m.error ? `<div class="small text-danger mt-1">${LF.esc(m.error)}</div>` : ''}
      <div class="meta">${fb}<span>${LF.fmt.time(m.at)}</span>${out ? `<span data-st="${m.id}">${STATUS_ICON[m.status] || ''}</span>` : ''}</div></div></div>`;
  }

  function renderMessages(list, append) {
    let html = '';
    let lastDay = append ? $('#tbody').data('lastDay') : null;
    list.forEach(m => {
      const day = LF.fmt.date(m.at);
      if (day !== lastDay) { html += `<div class="day-sep"><span>${LF.esc(day)}</span></div>`; lastDay = day; }
      html += bubble(m);
      S.lastId = Math.max(S.lastId, m.id);
    });
    const $b = $('#tbody');
    const atBottom = !append || ($b[0].scrollHeight - $b.scrollTop() - $b.height() < 80);
    append ? $b.append(html) : $b.html(html || `<div class="text-center small text-muted mt-4">${t('No messages yet.')}</div>`);
    $b.data('lastDay', lastDay);
    if (atBottom) $b.scrollTop($b[0].scrollHeight);
  }

  function renderThread() {
    const d = S.data, c = d.conversation;
    const assignOpts = '<option value="">' + t('Unassigned') + '</option>' + S.users.map(u => `<option value="${u.id}" ${c.assignee && c.assignee.id === u.id ? 'selected' : ''}>${LF.esc(u.name)}</option>`).join('');
    const tplOpts = d.templates.map(x => `<option value="${x.id}" data-params="${x.params}" data-preview="${LF.esc(x.preview)}">${LF.esc(x.name)} (${LF.esc(x.language)})</option>`).join('');
    $('#thread').html(`
      <div class="thread-head">
        <button class="btn btn-sm btn-light d-md-none" id="backToList" aria-label="${t('Back')}"><i class="bi bi-arrow-left"></i></button>
        <div class="me-auto"><div class="fw-semibold" dir="auto">${LF.esc(d.contact.name)}</div><div class="small text-muted">+${LF.esc(c.wa_id)}${c.language ? ' · ' + LF.esc(c.language) : ''}</div></div>
        <select class="form-select form-select-sm w-auto" id="aiMode" aria-label="AI mode" ${LF.can('whatsapp.send') ? '' : 'disabled'}>
          <option value="inherit" ${c.ai_mode === 'inherit' ? 'selected' : ''}>${t('AI: default')}</option>
          <option value="on" ${c.ai_mode === 'on' ? 'selected' : ''}>${t('AI: on')}</option>
          <option value="off" ${c.ai_mode === 'off' ? 'selected' : ''}>${t('AI: off (human only)')}</option></select>
        <select class="form-select form-select-sm w-auto" id="convAssign" aria-label="${t('Assigned to')}">${assignOpts}</select>
        ${c.status === 'closed' ? `<button class="btn btn-sm btn-light" data-status="open">${t('Reopen')}</button>` : `<button class="btn btn-sm btn-light" data-status="closed"><i class="bi bi-check2-circle"></i> ${t('Close')}</button>`}
      </div>
      ${c.handoff ? `<div class="alert alert-warning rounded-0 mb-0 py-2 small d-flex align-items-center gap-2"><i class="bi bi-person-raised-hand"></i><span>${t('Needs a human')}: ${LF.esc(c.handoff_reason || '')}</span>
        <button class="btn btn-sm btn-warning ms-auto" id="resolveHandoff">${t('Mark handled · let AI resume')}</button></div>` : ''}
      <div class="thread-body" id="tbody"></div>
      <div class="composer">
        ${d.contact.whatsapp_opted_out ? `<div class="window-closed">${t('This contact opted out of WhatsApp messages. Sending is blocked.')}</div>` : ''}
        ${!c.window_open && !d.contact.whatsapp_opted_out ? `<div class="window-closed"><i class="bi bi-clock-history"></i> ${t('24-hour window closed — only approved templates can be sent.')}
          ${d.templates.length ? '' : ' ' + t('No approved templates synced yet (Integrations → WhatsApp → Sync templates).')}</div>` : ''}
        <div id="suggestBox">${d.suggestion ? suggestionHtml(d.suggestion) : ''}</div>
        ${canSend && !d.contact.whatsapp_opted_out ? (c.window_open ? `
        <form id="sendForm" class="d-flex gap-2 align-items-end">
          <textarea class="form-control" id="msgBody" rows="2" maxlength="4096" placeholder="${t('Type a reply…')}" dir="auto" aria-label="${t('Message')}"></textarea>
          <div class="d-flex flex-column gap-1">
            <button class="btn btn-light btn-sm" type="button" id="suggestBtn" title="${t('Draft with AI from the knowledge base')}"><i class="bi bi-stars"></i> ${t('AI draft')}</button>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send"></i> ${t('Send')}</button></div>
          <input type="hidden" id="msgInteraction"></form>
        <div class="small text-muted mt-1">${t('Window open until')} ${LF.fmt.dateTime(c.window_closes_at)} · Enter ${t('to send')}, Shift+Enter ${t('for a new line')}</div>` : (d.templates.length ? `
        <form id="tplForm" class="row g-2 align-items-end">
          <div class="col-md-5"><select class="form-select form-select-sm" id="tplSel" aria-label="Template">${tplOpts}</select></div>
          <div class="col-md-5" id="tplParams"></div>
          <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">${t('Send template')}</button></div>
          <div class="col-12 small text-muted" id="tplPreview"></div></form>` : '')) : ''}
      </div>`);
    renderMessages(d.messages, false);
    renderSide();
    if ($('#tplSel').length) $('#tplSel').trigger('change');
  }

  function suggestionHtml(s) {
    return `<div class="suggestion" dir="auto"><div class="d-flex align-items-center mb-1"><strong class="small"><i class="bi bi-stars"></i> ${t('AI suggestion')}</strong>
      ${s.needs_human || s.outcome === 'handoff' ? `<span class="flag text-bg-warning ms-2">${t('AI is unsure — check before sending')}</span>` : ''}
      <button class="btn btn-sm btn-link ms-auto p-0" id="useSuggestion" data-int="${s.interaction_id}">${t('Use')}</button>
      <button class="btn btn-sm btn-link p-0 ms-2 text-muted" id="dismissSuggestion">${t('Dismiss')}</button></div>
      <div id="suggestionText">${LF.esc(s.reply || '')}</div>
      ${s.sources && s.sources.length ? '<div class="mt-1">' + s.sources.map(x => `<span class="src-chip" title="${LF.esc(x.excerpt)}">${LF.esc(x.title)}</span>`).join('') + '</div>' : ''}</div>`;
  }

  function renderSide() {
    const d = S.data, l = d.lead;
    let html = `<div class="small-caps mb-1">${t('Contact')}</div><div class="fw-semibold" dir="auto">${LF.esc(d.contact.name)}</div>
      <div class="text-muted">${LF.esc(d.contact.whatsapp || '')}</div>${d.contact.email ? `<div>${LF.esc(d.contact.email)}</div>` : ''}${d.contact.city ? `<div>${LF.esc(d.contact.city)}</div>` : ''}`;
    if (l) {
      html += `<hr><div class="d-flex align-items-center mb-1"><span class="small-caps">${t('Lead')}</span>${l.visible ? `<a class="ms-auto small" href="/leads/${l.id}">${t('Open lead')} #${l.id}</a>` : ''}</div>
        ${LF.statusBadge(l.status)} ${l.score_label ? `<span class="score-pill score-${LF.esc(l.score_label)} ms-1">${LF.esc(t(l.score_label.replace('_', ' ')))} · ${l.score}</span>` : ''}
        <dl class="dl-grid mt-2">${[[t('Source'), l.source], [t('Campaign / ad'), l.campaign], [t('Interested in'), l.product_interest], [t('Budget'), l.budget]]
          .filter(x => x[1]).map(([k, v]) => `<dt>${LF.esc(k)}</dt><dd dir="auto">${LF.esc(v)}</dd>`).join('')}</dl>
        ${l.ai_summary ? `<div class="mt-2"><div class="small-caps">${t('AI summary')}</div><div dir="auto">${LF.esc(l.ai_summary)}</div></div>` : ''}
        ${l.score_factors ? `<div class="mt-2"><div class="small-caps mb-1">${t('Why this score')}</div>${l.score_factors.map(f => `<div class="factor"><span>${LF.esc(f.label)}</span><span class="${f.points >= 0 ? 'pos' : 'neg'}">${f.points > 0 ? '+' : ''}${f.points}</span></div>`).join('')}</div>` : ''}
        ${l.visible && LF.can('followups.manage_own', 'followups.manage_all') ? `<div class="d-grid gap-1 mt-3"><button class="btn btn-sm btn-light" data-side="call"><i class="bi bi-journal-check"></i> ${t('Log call')}</button>
          <button class="btn btn-sm btn-light" data-side="fu"><i class="bi bi-alarm"></i> ${t('Schedule follow-up')}</button></div>` : ''}`;
    }
    $('#side').html(html);
  }

  function openConversation(id) {
    S.convId = id; S.lastId = 0;
    clearInterval(S.threadTimer);
    $('#inbox').addClass('show-thread');
    $('.conv-item').removeClass('active').filter(`[data-conv="${id}"]`).addClass('active');
    $('#thread').html(LF.loading());
    history.replaceState(null, '', '/whatsapp?c=' + id);
    LF.api('GET', 'conversations/' + id).then(r => {
      S.data = r.data; renderThread();
      if (r.data.conversation.unread) LF.api('POST', `conversations/${id}/read`, null, { silent: true }).then(loadList);
      S.threadTimer = setInterval(poll, 4000);
    }, err => { if (err.status === 404) $('#thread').html(`<div class="inbox-empty">${t('Conversation not found.')}</div>`); });
  }

  function poll() {
    if (document.hidden || !S.convId) return;
    LF.api('GET', `conversations/${S.convId}/poll`, { after_id: S.lastId }, { silent: true }).then(r => {
      if (r.data.messages.length) {
        renderMessages(r.data.messages, true);
        if (r.data.messages.some(m => m.direction === 'in')) { LF.api('POST', `conversations/${S.convId}/read`, null, { silent: true }); refreshMeta(); }
      }
      r.data.statuses.forEach(s => $(`[data-st="${s.id}"]`).html(STATUS_ICON[s.status] || ''));
    }, () => {});
  }

  function refreshMeta() {
    LF.api('GET', 'conversations/' + S.convId, null, { silent: true }).then(r => {
      S.data = r.data;
      if (r.data.suggestion && !$('#suggestionText').length) $('#suggestBox').html(suggestionHtml(r.data.suggestion));
      renderSide();
    });
  }

  function send(body, interactionId) {
    const $btn = $('#sendForm [type="submit"]');
    LF.busy($btn, true);
    return LF.api('POST', `conversations/${S.convId}/messages`, { body, interaction_id: interactionId || null }).then(r => {
      $('#msgBody').val(''); $('#msgInteraction').val(''); $('#suggestBox').empty();
      renderMessages(r.data.filter(m => m.id > S.lastId), true);
      loadList();
    }, err => { if (err.details && err.details.message_id) poll(); }).always(() => LF.busy($btn, false));
  }

  // ---------- events ----------
  $('#convViews').on('click', '[data-view]', function () { $('#convViews .btn').removeClass('active'); $(this).addClass('active'); S.view = $(this).data('view'); loadList(); });
  let qt; $('#convSearch').on('input', function () { clearTimeout(qt); qt = setTimeout(() => { S.q = this.value.trim(); loadList(); }, 300); });
  $('#convList').on('click', '[data-conv]', function () { openConversation($(this).data('conv')); });
  $(document).on('click', '#backToList', () => { $('#inbox').removeClass('show-thread'); S.convId = null; clearInterval(S.threadTimer); history.replaceState(null, '', '/whatsapp'); });

  $(document).on('submit', '#sendForm', e => { e.preventDefault(); const b = $('#msgBody').val().trim(); if (b) send(b, $('#msgInteraction').val()); });
  $(document).on('keydown', '#msgBody', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#sendForm').trigger('submit'); } });
  $(document).on('input', '#msgBody', function () { if (!this.value) $('#msgInteraction').val(''); });

  $(document).on('click', '#suggestBtn', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('POST', `conversations/${S.convId}/suggest`).then(r => {
      $('#suggestBox').html(suggestionHtml(r.data));
    }).always(() => LF.busy($b, false));
  });
  $(document).on('click', '#useSuggestion', function () { $('#msgBody').val($('#suggestionText').text()).trigger('focus'); $('#msgInteraction').val($(this).data('int')); });
  $(document).on('click', '#dismissSuggestion', () => $('#suggestBox').empty());

  $(document).on('change', '#aiMode', function () { LF.api('PATCH', 'conversations/' + S.convId, { ai_mode: this.value }).then(() => { LF.toast(t('AI mode updated')); loadList(); }); });
  $(document).on('change', '#convAssign', function () { LF.api('PATCH', 'conversations/' + S.convId, { assigned_user_id: this.value ? parseInt(this.value, 10) : null }).then(() => { LF.toast(t('Assignment updated')); loadList(); }, () => openConversation(S.convId)); });
  $(document).on('click', '[data-status]', function () { LF.api('PATCH', 'conversations/' + S.convId, { status: $(this).data('status') }).then(() => { loadList(); openConversation(S.convId); }); });
  $(document).on('click', '#resolveHandoff', () => LF.api('PATCH', 'conversations/' + S.convId, { resolve_handoff: true }).then(() => { loadList(); openConversation(S.convId); }));

  $(document).on('change', '#tplSel', function () {
    const $o = $(this).find(':selected'), n = parseInt($o.data('params') || 0, 10);
    let h = ''; for (let i = 1; i <= n; i++) h += `<input class="form-control form-control-sm mb-1" data-param placeholder="{{${i}}}" aria-label="Parameter ${i}" required>`;
    $('#tplParams').html(h || `<span class="small text-muted">${t('No parameters')}</span>`);
    $('#tplPreview').text($o.data('preview') || '');
  });
  $(document).on('submit', '#tplForm', function (e) {
    e.preventDefault();
    const params = $('[data-param]').map((i, el) => el.value).get();
    LF.api('POST', `conversations/${S.convId}/messages`, { template_id: parseInt($('#tplSel').val(), 10), params }).then(r => { renderMessages(r.data.filter(m => m.id > S.lastId), true); loadList(); });
  });

  $(document).on('click', '[data-fb]', function () {
    const $b = $(this);
    LF.api('POST', `ai-interactions/${$b.data('int')}/feedback`, { feedback: $b.data('fb') }).then(() => { $b.siblings('.fb').removeClass('on'); $b.addClass('on'); LF.toast(t('Thanks — feedback saved')); });
  });
  let teachInt = null;
  $(document).on('click', '[data-teach]', function () {
    teachInt = $(this).data('teach');
    const mid = $(this).data('msg');
    const prevIn = S.data.messages.filter(m => m.direction === 'in' && m.id < mid).pop();
    const lastIn = $(`.bubble-row.in`).filter((i, el) => $(el).data('mid') < mid).last().find('.txt').text().trim();
    $('#tq').val(prevIn ? prevIn.body : lastIn);
    $('#ta').val($(this).closest('.bubble').find('.txt').text().trim());
    bootstrap.Modal.getOrCreateInstance(document.getElementById('teachModal')).show();
  });
  $('#teachForm').on('submit', function (e) {
    e.preventDefault();
    LF.api('POST', `ai-interactions/${teachInt}/feedback`, { feedback: 'corrected', corrected_text: $('#ta').val(), question: $('#tq').val(), teach: true }, { form: $(this) })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('teachModal')).hide(); LF.toast(t('Saved to the knowledge base')); });
  });
  $(document).on('click', '[data-side="call"]', () => LF.callModal.open({ leadId: S.data.lead.id, context: S.data.contact.name, onSaved: refreshMeta }));
  $(document).on('click', '[data-side="fu"]', () => LF.followUpModal.open({ leadId: S.data.lead.id, onSaved: refreshMeta }));

  // ---------- init ----------
  LF.lookups().then(lk => { S.users = lk.users; });
  if (LF.can('integrations.manage')) {
    LF.api('GET', 'integrations/status', null, { silent: true }).then(r => {
      const wa = r.data.find(x => x.key === 'whatsapp_cloud');
      if (wa && wa.status !== 'connected') $('#waStatusNote').html(`${t('WhatsApp is not connected yet.')} <a href="/integrations">${t('Set it up')}</a>`);
    });
  }
  loadList().then(() => {
    const qp = new URLSearchParams(location.search), c = qp.get('c');
    if (qp.get('view')) $(`#convViews [data-view="${qp.get('view')}"]`).trigger('click');
    if (c) openConversation(parseInt(c, 10));
  });
  S.listTimer = setInterval(() => { if (!document.hidden) loadList(); }, 10000);
})(jQuery, window.LF);
