(function ($, LF) {
  'use strict';
  const t = LF.t;
  let page = 1, rows = [], mode = 'file', pollTimer = null;
  const canWrite = LF.can('kb.manage');
  $('.kb-write').toggleClass('hidden', !canWrite);
  const TYPE = { file: t('Document'), text: t('Text'), faq: 'FAQ', learned: t('Learned') };
  const ST = { ready: 'text-bg-success', pending: 'text-bg-secondary', processing: 'text-bg-info', failed: 'text-bg-danger' };

  function load() {
    LF.api('GET', 'kb/documents', { page, type: $('#kbType').val(), q: $('#kbQ').val() }).then(r => {
      rows = r.data;
      const s = r.meta.stats;
      $('#kbStats').html([[t('Documents'), s.documents], [t('Ready'), s.ready], [t('Searchable chunks'), s.chunks], [t('With semantic vectors'), s.embedded], [t('Failed'), s.failed]]
        .map(([k, v]) => `<div class="col-6 col-md"><div class="card kpi"><div class="kpi-label">${LF.esc(k)}</div><div class="kpi-value">${LF.fmt.num(v)}</div></div></div>`).join(''));
      $('#kbBody').html(rows.length ? rows.map(d => `<tr><td><a href="#" class="lf-row-link trunc-lg" data-chunks="${d.id}" title="${LF.esc(d.title)}" dir="auto">${LF.esc(d.title)}</a><div class="cell-muted">${LF.esc(d.file || '')}${d.by ? ' · ' + LF.esc(d.by) : ''} · ${LF.fmt.date(d.created_at)}</div></td>
        <td>${LF.esc(TYPE[d.type] || d.type)}</td><td><span class="badge ${ST[d.status]}">${LF.esc(t(d.status))}</span>${d.error ? `<div class="small text-danger trunc" title="${LF.esc(d.error)}">${LF.esc(d.error)}</div>` : ''}</td>
        <td class="text-end">${d.chunks}</td><td class="small">${d.embedded ? t('semantic + keyword') : t('keyword')}</td>
        <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-toggle="${d.id}" ${d.enabled ? 'checked' : ''} ${canWrite ? '' : 'disabled'} aria-label="${t('Enabled')}"></div></td>
        <td class="text-end text-nowrap">${canWrite ? `<button class="btn btn-sm btn-light" data-reindex="${d.id}" title="${t('Re-process')}" aria-label="${t('Re-process')}"><i class="bi bi-arrow-repeat"></i></button>
          <button class="btn btn-sm btn-light text-danger" data-del="${d.id}" aria-label="${t('Delete')}"><i class="bi bi-trash"></i></button>` : ''}</td></tr>`).join('')
        : `<tr><td colspan="7">${LF.empty('journal-richtext', t('No content yet. Upload your price list, brochure or FAQs so the assistant can answer accurately.'))}</td></tr>`);
      $('#kbPager').html(LF.pager(r.meta));
      clearTimeout(pollTimer);
      if (rows.some(d => d.status === 'pending' || d.status === 'processing')) pollTimer = setTimeout(load, 2500);
    });
  }

  $('[data-add]').on('click', function () {
    mode = $(this).data('add');
    const $f = $('#kbForm'); $f[0].reset(); LF.clearErrors($f);
    $('.k-file, .k-text, .k-faq').addClass('hidden');
    $('.k-' + mode).removeClass('hidden');
    $('#kbTitle').text({ file: t('Upload document'), text: t('Paste text'), faq: t('Add FAQ') }[mode]);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('kbModal')).show();
  });
  $('#kbForm').on('submit', function (e) {
    e.preventDefault();
    const $f = $(this), $b = $f.find('[type="submit"]');
    let payload;
    if (mode === 'file') {
      const file = $('#k-file')[0].files[0];
      if (!file) { $('#k-file').addClass('is-invalid'); return; }
      payload = new FormData(); payload.append('file', file);
      if ($('#k-title').val()) payload.append('title', $('#k-title').val());
      if ($('#k-lang').val()) payload.append('language', $('#k-lang').val());
    } else {
      payload = { type: mode, title: $('#k-title').val(), content: $('#k-content').val(), question: $('#k-q').val(), answer: $('#k-a').val(), language: $('#k-lang').val() || null };
    }
    LF.busy($b, true);
    LF.api('POST', 'kb/documents', payload, { form: $f }).then(() => { bootstrap.Modal.getInstance(document.getElementById('kbModal')).hide(); LF.toast(t('Added — processing')); page = 1; load(); }).always(() => LF.busy($b, false));
  });
  $(document).on('change', '[data-toggle]', function () { LF.api('PATCH', 'kb/documents/' + $(this).data('toggle'), { is_enabled: this.checked }).then(() => LF.toast(this.checked ? t('Enabled') : t('Disabled — the assistant will ignore it'))); });
  $(document).on('click', '[data-reindex]', function () { LF.api('POST', `kb/documents/${$(this).data('reindex')}/reindex`).then(load); });
  $(document).on('click', '[data-del]', function () {
    const id = $(this).data('del');
    LF.confirm({ title: t('Delete content'), body: t('The assistant will stop using it immediately.'), danger: true, confirmText: t('Delete') }).then(() => LF.api('DELETE', 'kb/documents/' + id).then(load));
  });
  $(document).on('click', '[data-chunks]', function (e) {
    e.preventDefault();
    LF.api('GET', 'kb/documents/' + $(this).data('chunks')).then(r => {
      $('#chunksTitle').text(r.data.title);
      $('#chunksBody').html(r.data.chunks.length ? r.data.chunks.map(c => `<div class="border rounded p-2 mb-2 small"><div class="text-muted mb-1">#${c.position + 1} · ${t('used')} ${c.times_retrieved}×</div><div class="text-pre" dir="auto">${LF.esc(c.content)}</div></div>`).join('') : LF.empty('journal', t('No chunks.')));
      bootstrap.Modal.getOrCreateInstance(document.getElementById('chunksModal')).show();
    });
  });
  $('#kbSearchBtn').on('click', search);
  $('#kbSearchQ').on('keydown', e => { if (e.key === 'Enter') search(); });
  function search() {
    const q = $('#kbSearchQ').val().trim(); if (!q) return;
    $('#kbSearchOut').html(LF.loading());
    LF.api('GET', 'kb/search', { q }).then(r => {
      $('#kbSearchOut').html(r.data.length ? r.data.map((h, i) => `<div class="border rounded p-2 mb-2 small"><div class="d-flex"><strong dir="auto">${i + 1}. ${LF.esc(h.title)}</strong>
        <span class="ms-auto text-muted">${h.semantic !== null ? 'sim ' + h.semantic + ' · ' : ''}kw ${h.lexical}</span></div><div class="text-muted mt-1" dir="auto">${LF.esc(h.content.slice(0, 300))}${h.content.length > 300 ? '…' : ''}</div></div>`).join('')
        : `<div class="small text-muted">${t('Nothing relevant found — the assistant would hand this question to a human.')}</div>`);
    });
  }
  $('#kbType').on('change', () => { page = 1; load(); });
  let qt; $('#kbQ').on('input', () => { clearTimeout(qt); qt = setTimeout(() => { page = 1; load(); }, 300); });
  $('#kbPager').on('click', '[data-page]', function () { page = $(this).data('page'); load(); });
  load();
})(jQuery, window.LF);
