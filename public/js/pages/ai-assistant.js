(function ($, LF) {
  'use strict';
  const t = LF.t;
  let D = null, rules = [], editingRule = null, history = [], learnPage = 1, learnRows = [], learnItem = null;

  function load() {
    return LF.api('GET', 'ai/assistant').then(r => { D = r.data; fill(); renderRules(); });
  }

  function fill() {
    const a = D.assistant, p = D.providers.find(x => x.key === a.provider);
    $('#a-name').val(a.name);
    $('#a-industry').html(D.industries.map(i => `<option value="${i.key}" ${i.key === a.industry ? 'selected' : ''}>${LF.esc(i.label)}</option>`).join(''));
    $('#a-provider').html(D.providers.map(x => `<option value="${x.key}" ${x.key === a.provider ? 'selected' : ''}>${LF.esc(x.label)} — ${x.connected ? t('connected') : t('not connected')}</option>`).join(''));
    $('#a-model').val(a.model || '').attr('placeholder', D.effective_model ? D.effective_model + ' (' + t('default') + ')' : t('Load models or type an ID'));
    $('#a-temp').val(a.temperature); $('#a-mode').val(a.mode); $('#a-hours').val(a.active_hours);
    $('#a-max').val(a.max_auto_replies); $('#a-tokens').val(a.max_tokens); $('#a-history').val(a.history_messages);
    $('#a-kbonly').prop('checked', a.kb_only); $('#a-qualify').prop('checked', a.auto_qualify);
    $('#a-langs').html(Object.entries(D.languages).map(([k, v]) => `<div class="form-check"><input class="form-check-input" type="checkbox" id="lang-${k}" value="${k}" data-lang ${(a.languages || []).includes(k) ? 'checked' : ''}><label class="form-check-label" for="lang-${k}">${LF.esc(v)}</label></div>`).join(''));
    $('#a-deflang').html(Object.entries(D.languages).map(([k, v]) => `<option value="${k}" ${k === a.default_language ? 'selected' : ''}>${LF.esc(v)}</option>`).join(''));
    $('#a-fields').html(Object.entries(D.fields).map(([k, v]) => `<div class="form-check"><input class="form-check-input" type="checkbox" id="fld-${k}" value="${k}" data-field ${(a.qualification_fields || []).includes(k) ? 'checked' : ''}><label class="form-check-label" for="fld-${k}">${LF.esc(t(v))}</label></div>`).join(''));
    $('#a-persona').val(a.persona); $('#a-instr').val(a.instructions); $('#a-greeting').val(a.greeting); $('#a-fallback').val(a.fallback_message);
    $('#a-handoff').val(a.handoff_message); $('#a-hkw').val((a.handoff_keywords || []).join(', '));
    $('#hoursHint').text(t('Business is currently') + ' ' + (D.business_open_now ? t('open') : t('closed')) + ' · ' + t('set hours in Settings → Business'));
    $('#modelHint').text(p && !p.connected ? t('Connect this provider under Integrations first.') : (p && p.embeddings ? t('Semantic search enabled via OpenAI embeddings.') : t('Knowledge base uses keyword search unless OpenAI is also connected.')));
    const connected = p && p.connected;
    $('#aiStatus').attr('class', 'badge ' + (a.mode === 'off' ? 'text-bg-secondary' : connected ? 'text-bg-success' : 'text-bg-warning'))
      .text(a.mode === 'off' ? t('AI off') : (connected ? (a.mode === 'auto' ? t('Auto-replying') : t('Suggesting replies')) : t('Provider not connected')));
    $('#aiNotice').html([
      !connected ? `<div class="alert alert-warning py-2 small">${t('The selected AI provider is not connected. Add an API key under')} <a href="/integrations">${t('Integrations')}</a>.</div>` : '',
      !D.kb.documents ? `<div class="alert alert-info py-2 small">${t('The knowledge base is empty. With "answer only from the knowledge base" on, every question will be handed to a human.')} <a href="/knowledge-base">${t('Add content')}</a></div>` : '',
    ].join(''));
  }

  $('#aiForm').on('submit', function (e) {
    e.preventDefault();
    const d = LF.formData($(this));
    const data = {
      name: d.name, provider: d.provider, model: d.model, temperature: parseFloat(d.temperature), max_tokens: parseInt(d.max_tokens, 10),
      persona: d.persona, instructions: d.instructions, greeting: d.greeting, fallback_message: d.fallback_message, handoff_message: d.handoff_message,
      mode: d.mode, active_hours: d.active_hours, kb_only: $('#a-kbonly').is(':checked'), auto_qualify: $('#a-qualify').is(':checked'),
      languages: $('[data-lang]:checked').map((i, el) => el.value).get(), default_language: d.default_language,
      handoff_keywords: (d.handoff_keywords_csv || '').split(',').map(s => s.trim()).filter(Boolean),
      qualification_fields: $('[data-field]:checked').map((i, el) => el.value).get(),
      max_auto_replies: parseInt(d.max_auto_replies, 10), history_messages: parseInt(d.history_messages, 10),
    };
    const $form = $(this);
    const save = () => {
      const $b = $('#aiSave'); LF.busy($b, true);
      LF.api('PUT', 'ai/assistant', data, { form: $form }).then(() => { LF.toast(t('Assistant saved')); load(); }).always(() => LF.busy($b, false));
    };
    if (data.mode === 'auto' && !data.kb_only) {
      LF.confirm({ title: t('Auto mode without knowledge-base limit'), body: t('The AI may answer from general knowledge instead of your content. Continue?'), confirmText: t('Save anyway') }).then(save);
    } else save();
  });

  $('#applyPreset').on('click', () => {
    LF.confirm({ title: t('Apply industry preset'), body: t('Persona, instructions and fields will be replaced. Your knowledge base is not changed.'), confirmText: t('Apply') })
      .then(() => LF.api('POST', 'ai/assistant/preset', { industry: $('#a-industry').val() }).then(() => { LF.toast(t('Preset applied')); load(); }));
  });
  $('#loadModels').on('click', function () {
    const $b = $(this); LF.busy($b, true);
    LF.api('GET', 'ai/models', { provider: $('#a-provider').val() }).then(r => {
      $('#modelList').html(r.data.map(m => `<option value="${LF.esc(m)}">`).join(''));
      LF.toast(r.data.length + ' ' + t('models available'));
      $('#a-model').trigger('focus');
    }).always(() => LF.busy($b, false));
  });
  $('#a-provider').on('change', () => { $('#a-model').val(''); $('#modelList').empty(); });
  $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
    const target = $(this).data('bs-target');
    $('.save-bar').toggleClass('hidden', target === '#t-rules' || target === '#t-learn' || target === '#t-play');
    if (target === '#t-learn') loadLearning();
  });

  // ---------- playground ----------
  function bubble(role, text, extra) {
    return `<div class="bubble-row ${role === 'user' ? 'in' : 'out'}"><div class="bubble ${role === 'user' ? '' : 'ai'}" dir="auto">${LF.esc(text)}${extra || ''}</div></div>`;
  }
  $('#playSend').on('click', send);
  $('#playQ').on('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send(); } });
  $('#playReset').on('click', () => { history = []; $('#playLog').empty(); $('#playMeta').html(`<span class="text-muted">${t('Send a test message to see sources, flags and latency.')}</span>`); });
  function send() {
    const q = $('#playQ').val().trim();
    if (!q) return;
    $('#playLog').append(bubble('user', q)).append(`<div class="text-center small text-muted" id="typing">${t('thinking…')}</div>`);
    $('#playLog').scrollTop(1e6); $('#playQ').val('');
    LF.api('POST', 'ai/playground', { question: q, history: history.slice(-10) }).then(r => {
      const d = r.data;
      $('#typing').remove();
      if (!d.ok) { $('#playLog').append(`<div class="alert alert-danger py-1 small my-2">${LF.esc(d.error)}</div>`); return; }
      const flags = (d.needs_human ? `<div class="mt-1"><span class="flag text-bg-warning">${t('would hand off to a human')}</span></div>` : '') + (d.off_topic ? `<div><span class="flag text-bg-secondary">${t('off-topic')}</span></div>` : '');
      $('#playLog').append(bubble('assistant', d.reply || '—', flags)).scrollTop(1e6);
      history.push({ role: 'user', content: q }, { role: 'assistant', content: d.reply || '' });
      $('#playMeta').html(`<dl class="dl-grid"><dt>${t('Language')}</dt><dd>${LF.esc(d.language || '—')}</dd><dt>${t('Answerable from KB')}</dt><dd>${d.answerable ? t('yes') : t('no')}</dd>
        <dt>${t('Hand off')}</dt><dd>${d.needs_human ? t('yes') + (d.handoff_reason ? ' — ' + LF.esc(d.handoff_reason) : '') : t('no')}</dd><dt>${t('Latency')}</dt><dd>${d.latency_ms || '—'} ms</dd><dt>${t('Tokens')}</dt><dd>${d.tokens || '—'}</dd></dl>
        <div class="small-caps mt-2 mb-1">${t('Knowledge used')}</div>${d.sources.length ? d.sources.map(s => `<div class="mb-2"><strong>${LF.esc(s.title)}</strong><div class="text-muted">${LF.esc(s.excerpt)}…</div></div>`).join('') : `<div class="text-muted">${t('No matching knowledge.')}</div>`}`);
    }, () => $('#typing').remove());
  }

  // ---------- rules ----------
  function renderRules() {
    rules = D.rules;
    const trig = { keyword: t('Keyword'), first_message: t('First message'), outside_hours: t('Outside hours') };
    $('#rulesBody').html(rules.length ? rules.map(r => `<tr><td><strong>${LF.esc(r.name)}</strong><div class="cell-muted">#${r.priority}${r.stop_ai ? ' · ' + t('skips AI') : ''}</div></td>
      <td>${LF.esc(trig[r.trigger])}${r.trigger === 'keyword' ? `<div class="cell-muted" dir="auto">${LF.esc((r.keywords || []).join(', '))}</div>` : ''}</td>
      <td class="small" dir="auto">${LF.esc(r.response.length > 120 ? r.response.slice(0, 120) + '…' : r.response)}</td><td class="text-end">${r.times_used}</td>
      <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-rule-toggle="${r.id}" ${r.is_active ? 'checked' : ''} aria-label="${t('Active')}"></div></td>
      <td class="text-end text-nowrap"><button class="btn btn-sm btn-light" type="button" data-rule-edit="${r.id}" aria-label="${t('Edit')}"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-light text-danger" type="button" data-rule-del="${r.id}" aria-label="${t('Delete')}"><i class="bi bi-trash"></i></button></td></tr>`).join('')
      : `<tr><td colspan="6">${LF.empty('lightning', t('No auto-replies yet. Add a greeting for first messages or an after-hours reply.'))}</td></tr>`);
  }
  function openRule(r) {
    editingRule = r || null;
    const $f = $('#ruleForm'); $f[0].reset(); LF.clearErrors($f);
    if (r) { $('#r-name').val(r.name); $('#r-trigger').val(r.trigger); $('#r-match').val(r.match_type); $('#r-kw').val((r.keywords || []).join(', ')); $('#r-resp').val(r.response); $('#r-prio').val(r.priority); $('#r-cool').val(r.cooldown_minutes); $('#r-stop').prop('checked', r.stop_ai); }
    else if (D.assistant.greeting) { $('#r-trigger').val('first_message'); $('#r-name').val(t('Welcome message')); $('#r-resp').val(D.assistant.greeting); }
    $('#r-trigger').trigger('change');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleModal')).show();
  }
  $('#r-trigger').on('change', function () { $('.kw-only').toggleClass('hidden', this.value !== 'keyword'); });
  $('#addRule').on('click', () => openRule(null));
  $(document).on('click', '[data-rule-edit]', function () { openRule(rules.find(r => r.id === $(this).data('rule-edit'))); });
  $(document).on('click', '[data-rule-del]', function () { const id = $(this).data('rule-del'); LF.confirm({ title: t('Delete rule'), danger: true, confirmText: t('Delete') }).then(() => LF.api('DELETE', 'ai/rules/' + id).then(load)); });
  $(document).on('change', '[data-rule-toggle]', function () { LF.api('PATCH', 'ai/rules/' + $(this).data('rule-toggle'), { is_active: this.checked }).then(() => LF.toast(t('Saved'))); });
  $('#ruleForm').on('submit', function (e) {
    e.preventDefault();
    const d = LF.formData($(this));
    const data = { name: d.name, trigger: d.trigger, match_type: d.match_type, response: d.response, priority: parseInt(d.priority, 10), cooldown_minutes: parseInt(d.cooldown_minutes, 10),
      stop_ai: $('#r-stop').is(':checked'), keywords: (d.keywords_csv || '').split(',').map(s => s.trim()).filter(Boolean) };
    LF.api(editingRule ? 'PATCH' : 'POST', editingRule ? 'ai/rules/' + editingRule.id : 'ai/rules', data, { form: $(this) })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('ruleModal')).hide(); LF.toast(t('Rule saved')); load(); });
  });

  // ---------- learning ----------
  function loadLearning() {
    LF.api('GET', 'ai/learning', { page: learnPage }).then(r => {
      learnRows = r.data;
      $('#learnCount').text(r.meta.total || '');
      $('#learnBody').html(r.data.length ? r.data.map(i => `<tr><td dir="auto"><div>${LF.esc(i.question || '—')}</div><div class="cell-muted">${LF.esc(i.contact || t('Test chat'))} · ${LF.fmt.rel(i.at)}</div></td>
        <td class="small" dir="auto">${LF.esc(i.corrected || i.ai_answer || '—')}${i.corrected ? `<div class="cell-muted">${t('corrected by an agent')}</div>` : ''}</td>
        <td>${i.outcome === 'handoff' ? `<span class="badge text-bg-warning">${t('handed off')}</span>` : ''} ${i.feedback ? `<span class="badge text-bg-light border">${LF.esc(t(i.feedback))}</span>` : ''}</td>
        <td class="text-end text-nowrap">${LF.can('kb.manage') ? `<button class="btn btn-sm btn-primary" data-learn="${i.id}">${t('Approve answer')}</button>` : ''} <button class="btn btn-sm btn-light" data-learn-dismiss="${i.id}">${t('Dismiss')}</button></td></tr>`).join('')
        : `<tr><td colspan="4">${LF.empty('mortarboard', t('Nothing to review. Handed-off questions and corrected answers appear here.'))}</td></tr>`);
      $('#learnPager').html(LF.pager(r.meta));
    });
  }
  $('#learnPager').on('click', '[data-page]', function () { learnPage = $(this).data('page'); loadLearning(); });
  $(document).on('click', '[data-learn]', function () {
    learnItem = learnRows.find(x => x.id === $(this).data('learn'));
    $('#l-q').val(learnItem.question || ''); $('#l-a').val(learnItem.corrected || learnItem.ai_answer || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('learnModal')).show();
  });
  $('#learnForm').on('submit', function (e) {
    e.preventDefault();
    LF.api('POST', `ai-interactions/${learnItem.id}/feedback`, { feedback: 'corrected', corrected_text: $('#l-a').val(), question: $('#l-q').val(), teach: true }, { form: $(this) })
      .then(() => { bootstrap.Modal.getInstance(document.getElementById('learnModal')).hide(); LF.toast(t('Saved to the knowledge base')); loadLearning(); });
  });
  $(document).on('click', '[data-learn-dismiss]', function () { LF.api('POST', `ai/learning/${$(this).data('learn-dismiss')}/dismiss`).then(loadLearning); });

  load();
  LF.api('GET', 'ai/learning', { per_page: 5 }, { silent: true }).then(r => $('#learnCount').text(r.meta.total || ''));
})(jQuery, window.LF);
