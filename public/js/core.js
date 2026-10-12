/* LeadFlow core: API client, formatting, shared modals. No inline scripts (CSP). */
(function ($, window) {
  'use strict';

  const body = document.body;
  const LF = window.LF = {
    tz: body.dataset.tz || 'UTC',
    currency: body.dataset.currency || 'PKR',
    country: body.dataset.country || 'PK',
    userId: parseInt(body.dataset.userId || '0', 10),
    perms: JSON.parse(body.dataset.perms || '[]'),
  };

  LF.can = (...keys) => keys.some(k => LF.perms.includes(k));

  // ---------- HTML escaping ----------
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
  LF.esc = v => (v === null || v === undefined) ? '' : String(v).replace(/[&<>"'`]/g, c => ESC[c]);

  // ---------- API ----------
  const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
  LF.api = function (method, url, data, opts = {}) {
    const isForm = data instanceof FormData;
    const settings = {
      url: '/api/v1/' + url.replace(/^\//, ''),
      method,
      headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      dataType: 'json',
    };
    if (data !== undefined && data !== null) {
      if (method === 'GET') settings.data = data;
      else if (isForm) { settings.data = data; settings.processData = false; settings.contentType = false; }
      else { settings.data = JSON.stringify(data); settings.contentType = 'application/json'; }
    }
    // then(fn, fn) normalises success to a single value so $.when() callers get the body, not [body, status, xhr].
    return $.ajax(settings).then(d => d, xhr => {
      const err = (xhr.responseJSON && xhr.responseJSON.error) || { code: 'network', message: xhr.status ? 'Request failed (' + xhr.status + ').' : 'Network error — check your connection.' };
      err.status = xhr.status;
      if (xhr.status === 401) { window.location.href = '/login'; }
      else if (xhr.status === 419) { LF.toast('Your session expired. Reload the page.', 'danger'); }
      else if (!opts.silent && !(opts.form && err.code === 'validation_failed')) { LF.toast(err.message, 'danger'); }
      if (opts.form) LF.showErrors(opts.form, err);
      return $.Deferred().reject(err).promise();
    });
  };

  /** File download through a POSTed JSON body (exports). */
  LF.download = async function (url, payload) {
    const res = await fetch('/api/v1/' + url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json, text/csv, application/octet-stream' },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      let msg = 'Export failed.';
      try { msg = (await res.json()).error.message; } catch (e) { /* ignore */ }
      LF.toast(msg, 'danger');
      return;
    }
    const blob = await res.blob();
    const name = (res.headers.get('Content-Disposition') || '').match(/filename="?([^"]+)"?/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name ? name[1] : 'export';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  };

  // ---------- Lookups ----------
  let lookupsPromise = null;
  LF.lookups = (refresh) => {
    if (!lookupsPromise || refresh) lookupsPromise = LF.api('GET', 'lookups').then(r => r.data);
    return lookupsPromise;
  };

  LF.fillSelect = function ($sel, items, opts = {}) {
    const current = $sel.val();
    const html = [];
    if (opts.empty !== undefined && opts.empty !== null) html.push('<option value="">' + LF.esc(opts.empty) + '</option>');
    items.forEach(i => html.push('<option value="' + LF.esc(i.value) + '">' + LF.esc(i.label) + '</option>'));
    $sel.html(html.join(''));
    if (current) $sel.val(current);
  };

  LF.fillFromLookups = function ($root) {
    return LF.lookups().then(lk => {
      $root.find('[data-fill]').each(function () {
        const $s = $(this);
        const kind = $s.data('fill');
        const empty = $s.data('empty');
        let items = [];
        if (kind === 'sources') items = lk.sources.filter(s => s.is_active).map(s => ({ value: s.id, label: s.name }));
        if (kind === 'statuses') items = lk.statuses.filter(s => s.is_active).map(s => ({ value: s.id, label: s.name }));
        if (kind === 'users') items = lk.users.map(u => ({ value: u.id, label: u.name }));
        if (kind === 'outcomes') items = lk.outcomes.map(o => ({ value: o.id, label: o.name }));
        if (kind === 'campaigns') items = (lk.campaigns || []).map(c => ({ value: c.id, label: c.name }));
        if (kind === 'tags') items = lk.tags.map(t => ({ value: t.id, label: t.name }));
        if (kind === 'regions') items = lk.phone_regions.map(r => ({ value: r, label: r }));
        LF.fillSelect($s, items, { empty: empty === undefined ? null : empty });
        if (kind === 'regions' && !$s.val()) $s.val(lk.organization.default_country);
      });
      return lk;
    });
  };

  // ---------- Formatting ----------
  const dtf = {};
  const fmtr = (key, o) => dtf[key] || (dtf[key] = new Intl.DateTimeFormat(undefined, Object.assign({ timeZone: LF.tz }, o)));
  LF.fmt = {
    date(iso) { return iso ? fmtr('d', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(iso)) : '—'; },
    dateTime(iso) { return iso ? fmtr('dt', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(iso)) : '—'; },
    time(iso) { return iso ? fmtr('t', { hour: 'numeric', minute: '2-digit' }).format(new Date(iso)) : ''; },
    rel(iso) {
      if (!iso) return '—';
      const diff = (new Date(iso).getTime() - Date.now()) / 1000;
      const abs = Math.abs(diff);
      const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
      if (abs < 60) return rtf.format(Math.round(diff), 'second');
      if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
      if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
      if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day');
      return LF.fmt.date(iso);
    },
    money(n, cur) {
      if (n === null || n === undefined || n === '') return '—';
      try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur || LF.currency, maximumFractionDigits: 0 }).format(n); }
      catch (e) { return (cur || LF.currency) + ' ' + Number(n).toLocaleString(); }
    },
    num(n) { return (n === null || n === undefined) ? '—' : Number(n).toLocaleString(); },
    pct(n) { return (n === null || n === undefined) ? '—' : n + '%'; },
    label(s) { return s ? String(s).replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase()) : ''; },
  };

  /** "YYYY-MM-DDTHH:mm" for <input type="datetime-local"> in the org/user time zone. */
  LF.localInput = function (date) {
    const parts = fmtr('iso', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(date).reduce((a, p) => (a[p.type] = p.value, a), {});
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  };
  LF.localDate = function (date) { return LF.localInput(date).slice(0, 10); };
  LF.inHours = h => LF.localInput(new Date(Date.now() + h * 3600e3));
  LF.tomorrowAt = (hour) => {
    const d = LF.localDate(new Date(Date.now() + 86400e3));
    return d + 'T' + String(hour).padStart(2, '0') + ':00';
  };

  LF.statusBadge = s => s ? `<span class="st st-${LF.esc(s.color || 'secondary')}">${LF.esc(s.name)}</span>` : '';
  LF.priorityBadge = p => `<span class="prio prio-${LF.esc(p)}">${LF.esc(p)}</span>`;
  LF.phoneLink = (num) => num ? `<a href="tel:${LF.esc(num)}" class="text-nowrap">${LF.esc(num)}</a>` : '<span class="cell-muted">—</span>';
  LF.waLink = (num) => num ? `https://wa.me/${String(num).replace(/\D/g, '')}` : null;
  LF.empty = (icon, text) => `<div class="lf-empty"><i class="bi bi-${LF.esc(icon)}"></i>${LF.esc(text)}</div>`;
  LF.loading = (text) => `<div class="lf-loading"><span class="spinner-border spinner-border-sm me-2"></span>${LF.esc(text || 'Loading…')}</div>`;

  // ---------- Toasts ----------
  LF.toast = function (message, type = 'success') {
    const id = 't' + Math.random().toString(36).slice(2);
    const icon = type === 'danger' ? 'exclamation-octagon' : type === 'warning' ? 'exclamation-triangle' : 'check-circle';
    $('#toasts').append(`<div id="${id}" class="toast align-items-center text-bg-${type === 'success' ? 'dark' : type} border-0" role="${type === 'danger' ? 'alert' : 'status'}">
      <div class="d-flex"><div class="toast-body"><i class="bi bi-${icon} me-2"></i>${LF.esc(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`);
    const el = document.getElementById(id);
    const t = new bootstrap.Toast(el, { delay: type === 'danger' ? 7000 : 3500 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  };

  // ---------- Forms ----------
  /** Serialize a form into a nested object: contact[name] → {contact:{name}}. Empty strings become null. */
  LF.formData = function ($form) {
    const out = {};
    $form.find('input, select, textarea').each(function () {
      if (!this.name || this.disabled) return;
      let val;
      if (this.type === 'checkbox') { if (!this.checked) return; val = this.value === 'on' ? true : (this.value === '1' ? true : this.value); }
      else if (this.type === 'radio') { if (!this.checked) return; val = this.value; }
      else if (this.multiple) val = $(this).val();
      else val = this.value === '' ? null : this.value;
      const path = this.name.replace(/\]/g, '').split('[');
      let o = out;
      path.forEach((k, i) => {
        if (i === path.length - 1) { if (k === '') { (o.push ? o : null).push(val); } else o[k] = val; }
        else { o[k] = o[k] || {}; o = o[k]; }
      });
    });
    return out;
  };

  LF.clearErrors = $form => { $form.find('.is-invalid').removeClass('is-invalid'); $form.find('.invalid-feedback.lf').remove(); $form.find('.lf-form-error').remove(); };
  LF.showErrors = function ($form, err) {
    LF.clearErrors($form);
    if (err.code === 'validation_failed' && err.details) {
      let first = null;
      Object.entries(err.details).forEach(([key, msgs]) => {
        const name = key.split('.').map((p, i) => i === 0 ? p : `[${p}]`).join('');
        let $f = $form.find(`[name="${name}"]`);
        if (!$f.length) $f = $form.find(`[name="${key}"]`);
        if ($f.length) {
          $f.addClass('is-invalid').after(`<div class="invalid-feedback lf">${LF.esc(msgs[0])}</div>`);
          first = first || $f;
        } else {
          $form.find('.modal-body, .card-body').first().prepend(`<div class="alert alert-danger py-2 lf-form-error">${LF.esc(msgs[0])}</div>`);
        }
      });
      if (first) first.trigger('focus');
    } else if (err.message && err.code !== 'network') {
      $form.find('.modal-body, .card-body').first().prepend(`<div class="alert alert-danger py-2 lf-form-error">${LF.esc(err.message)}</div>`);
    }
  };

  LF.busy = function ($btn, on) {
    if (on) { $btn.prop('disabled', true).data('label', $btn.html()).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…'); }
    else { $btn.prop('disabled', false).html($btn.data('label')); }
  };

  // ---------- Confirm dialog ----------
  LF.confirm = function (o) {
    const d = $.Deferred();
    const $m = $('#confirmModal');
    $('#confirmModalTitle').text(o.title || 'Are you sure?');
    $('#confirmBody').html(o.html || LF.esc(o.body || ''));
    $('#confirmOk').text(o.confirmText || 'Confirm').toggleClass('btn-danger', !!o.danger).toggleClass('btn-primary', !o.danger);
    $('#confirmReasonWrap').toggleClass('hidden', !o.reason);
    $('#confirmReasonLabel').text(o.reasonLabel || 'Reason');
    $('#confirmReason').val('').removeClass('is-invalid');
    const modal = bootstrap.Modal.getOrCreateInstance($m[0]);
    $('#confirmForm').off('submit').on('submit', e => {
      e.preventDefault();
      const reason = $('#confirmReason').val().trim();
      if (o.reason && o.reasonRequired && !reason) { $('#confirmReason').addClass('is-invalid').trigger('focus'); return; }
      modal.hide();
      d.resolve(reason);
    });
    $m.off('hidden.bs.modal.confirm').on('hidden.bs.modal.confirm', () => { if (d.state() === 'pending') d.reject(); });
    modal.show();
    return d.promise();
  };

  // ---------- Lead modal (create) ----------
  LF.leadModal = {
    open(onSaved) {
      const $f = $('#leadForm');
      $f[0].reset(); LF.clearErrors($f);
      $f.find('[name="contact_id"], [name="allow_duplicate_open_lead"]').val('');
      $('#leadMatchBox').removeClass('show alert-warning alert-info alert-danger').empty();
      LF.fillFromLookups($f).then(lk => {
        if (!LF.can('leads.assign')) {
          LF.fillSelect($('#lf-assign'), [{ value: LF.userId, label: 'Me' }], { empty: 'Unassigned' });
          $('#lf-assign').val(LF.userId);
        }
        $('#lf-region').val(lk.organization.default_country);
      });
      this.onSaved = onSaved;
      bootstrap.Modal.getOrCreateInstance(document.getElementById('leadModal')).show();
      setTimeout(() => $('#lf-name').trigger('focus'), 300);
    },
  };

  let matchTimer = null;
  function checkMatch() {
    const $f = $('#leadForm');
    const phone = $('#lf-phone').val().trim(), wa = $('#lf-wa').val().trim(), email = $('#lf-email').val().trim();
    const $box = $('#leadMatchBox');
    $f.find('[name="contact_id"]').val('');
    if (!phone && !wa && !email) { $box.removeClass('show').empty(); return; }
    LF.api('GET', 'contacts/match', { phone, whatsapp: wa, email, phone_region: $('#lf-region').val() }, { silent: true }).then(r => {
      const d = r.data;
      const bad = [];
      if (phone && !d.phone.valid) bad.push('Phone doesn\'t look valid for ' + $('#lf-region').val() + '.');
      if (wa && !d.whatsapp.valid) bad.push('WhatsApp number doesn\'t look valid.');
      if (!d.matches.length) {
        if (bad.length) { $box.attr('class', 'match-box show alert alert-warning mb-0').text(bad.join(' ')); }
        else if (d.phone.e164 || d.whatsapp.e164) { $box.attr('class', 'match-box show alert alert-info mb-0').text('Will be saved as ' + [d.phone.e164, d.whatsapp.e164].filter(Boolean).join(' / ') + '. No existing contact found.'); }
        else $box.removeClass('show').empty();
        return;
      }
      const m = d.matches[0];
      let html = `<strong>Existing contact found</strong> (${LF.esc(m.reasons.join(', '))}): ${LF.esc(m.display_name)}. `;
      if (m.do_not_contact) html += '<span class="dnc">Do not contact</span> ';
      if (m.has_open_lead) {
        html += m.open_lead_id ? `They already have an <a href="/leads/${m.open_lead_id}">open lead #${m.open_lead_id}</a>. Saving will be blocked unless you add a separate inquiry.`
          : 'They already have an open lead owned by someone else.';
        html += ' <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="lf-allowdup"><label class="form-check-label" for="lf-allowdup">This is a separate, new inquiry</label></div>';
      } else {
        html += 'The new lead will be linked to this contact.';
      }
      $box.attr('class', 'match-box show alert ' + (m.has_open_lead ? 'alert-warning' : 'alert-info') + ' mb-0').html(html);
      if (m.contact_id) $f.find('[name="contact_id"]').val(m.contact_id);
    }, () => {});
  }
  $(document).on('input', '#lf-phone, #lf-wa, #lf-email', () => { clearTimeout(matchTimer); matchTimer = setTimeout(checkMatch, 450); });
  $(document).on('change', '#lf-region', checkMatch);
  $(document).on('change', '#lf-allowdup', function () { $('#leadForm [name="allow_duplicate_open_lead"]').val(this.checked ? '1' : ''); });

  $(document).on('submit', '#leadForm', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]');
    const data = LF.formData($f);
    data.tags = (data.tags_csv || '').split(',').map(s => s.trim()).filter(Boolean);
    delete data.tags_csv;
    if (data.contact_id) delete data.contact; else delete data.contact_id;
    data.allow_duplicate_open_lead = data.allow_duplicate_open_lead === '1';
    if (data.attribution) { Object.keys(data.attribution).forEach(k => data.attribution[k] === null && delete data.attribution[k]); }
    if (data.attribution && data.attribution.campaign_id) {
      const src = $('#lf-source option:selected').text().toLowerCase();
      data.attribution.platform = src.includes('instagram') ? 'instagram' : src.includes('facebook') ? 'facebook' : (src.includes('meta') ? 'meta' : undefined);
    }
    LF.busy($btn, true);
    LF.api('POST', 'leads', data, { form: $f }).then(r => {
      bootstrap.Modal.getInstance(document.getElementById('leadModal')).hide();
      LF.toast('Lead #' + r.data.id + ' created');
      if (LF.leadModal.onSaved) LF.leadModal.onSaved(r.data); else window.location.href = '/leads/' + r.data.id;
    }, err => {
      if (err.code === 'duplicate_open_lead') {
        $f.find('.modal-body').prepend(`<div class="alert alert-warning py-2 lf-form-error">${LF.esc(err.message)} ${err.details && err.details.existing_lead_id ? `<a href="/leads/${err.details.existing_lead_id}">Open it</a>, or tick “separate, new inquiry”.` : ''}</div>`);
        checkMatch();
      }
    }).always(() => LF.busy($btn, false));
  });
  $(document).on('click', '[data-action="new-lead"]', () => LF.leadModal.open());

  // ---------- Call / follow-up / appointment modals ----------
  function openForm(modalId, formId, setup) {
    const $f = $('#' + formId);
    $f[0].reset(); LF.clearErrors($f);
    return LF.fillFromLookups($f).then(lk => { setup && setup($f, lk); bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId)).show(); return lk; });
  }

  LF.callModal = {
    open(o) {
      this.o = o;
      openForm('callModal', 'callForm', ($f) => {
        $('#callModalContext').text(o.context || '');
        $('#cf-callback-wrap').addClass('hidden');
        $('#cf-callback').val(LF.tomorrowAt(11));
      });
    },
  };
  $(document).on('change', '#cf-outcome', function () {
    LF.lookups().then(lk => {
      const o = lk.outcomes.find(x => String(x.id) === $('#cf-outcome').val());
      $('#cf-callback-wrap').toggleClass('hidden', !(o && o.creates_callback));
      $('#cf-next').closest('.col-7').toggleClass('hidden', !!(o && o.creates_callback));
    });
  });
  $(document).on('submit', '#callForm', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]'), o = LF.callModal.o;
    const data = LF.formData($f);
    if ($('#cf-callback-wrap').hasClass('hidden')) delete data.callback_at;
    LF.busy($btn, true);
    const url = o.taskId ? `call-tasks/${o.taskId}/complete` : `leads/${o.leadId}/calls`;
    LF.api('POST', url, data, { form: $f }).then(r => {
      bootstrap.Modal.getInstance(document.getElementById('callModal')).hide();
      LF.toast('Call logged: ' + (r.data.outcome ? r.data.outcome.name : ''));
      o.onSaved && o.onSaved(r.data);
    }).always(() => LF.busy($btn, false));
  });

  LF.followUpModal = {
    open(o) {
      this.o = o;
      openForm('followUpModal', 'followUpForm', () => { $('#fu-at').val(o.at || LF.tomorrowAt(11)); if (!LF.can('followups.manage_all')) $('#fu-user').closest('.col-6').addClass('hidden'); });
    },
  };
  $(document).on('submit', '#followUpForm', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]'), o = LF.followUpModal.o;
    LF.busy($btn, true);
    LF.api('POST', `leads/${o.leadId}/follow-ups`, LF.formData($f), { form: $f }).then(r => {
      bootstrap.Modal.getInstance(document.getElementById('followUpModal')).hide();
      LF.toast('Follow-up scheduled for ' + LF.fmt.dateTime(r.data.scheduled_at));
      o.onSaved && o.onSaved(r.data);
    }).always(() => LF.busy($btn, false));
  });

  LF.apptModal = {
    open(o) {
      this.o = o;
      openForm('apptModal', 'apptForm', () => { $('#ap-start').val(LF.tomorrowAt(15)); if (!LF.can('followups.manage_all')) $('#ap-user').closest('.col-12').addClass('hidden'); });
    },
  };
  $(document).on('submit', '#apptForm', function (e) {
    e.preventDefault();
    const $f = $(this), $btn = $f.find('[type="submit"]'), o = LF.apptModal.o;
    LF.busy($btn, true);
    LF.api('POST', `leads/${o.leadId}/appointments`, LF.formData($f), { form: $f }).then(r => {
      bootstrap.Modal.getInstance(document.getElementById('apptModal')).hide();
      LF.toast('Appointment scheduled');
      o.onSaved && o.onSaved(r.data);
    }).always(() => LF.busy($btn, false));
  });

  // ---------- Pagination ----------
  LF.pager = function (meta, onPage) {
    if (!meta || meta.last_page <= 1) return `<div class="small text-muted">${meta ? LF.fmt.num(meta.total) + ' result' + (meta.total === 1 ? '' : 's') : ''}</div>`;
    const p = meta.page, last = meta.last_page;
    const btn = (n, label, dis, act) => `<li class="page-item ${dis ? 'disabled' : ''} ${act ? 'active' : ''}"><button class="page-link" data-page="${n}" ${dis ? 'disabled' : ''}>${label}</button></li>`;
    let pages = '';
    const from = Math.max(1, p - 2), to = Math.min(last, p + 2);
    for (let i = from; i <= to; i++) pages += btn(i, i, false, i === p);
    return `<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="small text-muted">${LF.fmt.num((p - 1) * meta.per_page + 1)}–${LF.fmt.num(Math.min(p * meta.per_page, meta.total))} of ${LF.fmt.num(meta.total)}</div>
      <ul class="pagination pagination-sm mb-0">${btn(p - 1, '‹', p <= 1)}${pages}${btn(p + 1, '›', p >= last)}</ul></div>`;
  };

  // ---------- Global chrome ----------
  $('#sidebarToggle').on('click', () => {
    $('#sidebar').toggleClass('open'); $('#sidebarBackdrop').toggleClass('show');
    $('#sidebarToggle').attr('aria-expanded', $('#sidebar').hasClass('open'));
  });
  $('#sidebarBackdrop').on('click', () => { $('#sidebar').removeClass('open'); $('#sidebarBackdrop').removeClass('show'); });

  let searchTimer = null;
  $('#globalSearch').on('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    const $r = $('#globalSearchResults');
    if (q.length < 2) { $r.removeClass('show').empty(); return; }
    searchTimer = setTimeout(() => {
      LF.api('GET', 'leads', { q, per_page: 8, archived: 'include' }, { silent: true }).then(r => {
        if (!r.data.length) { $r.html('<div class="p-3 small text-muted">No leads match.</div>').addClass('show'); return; }
        $r.html(r.data.map(l => `<a href="/leads/${l.id}" role="option"><div class="d-flex justify-content-between"><strong>${LF.esc(l.contact.display_name)}</strong>${LF.statusBadge(l.status)}</div>
          <div class="small text-muted">#${l.id} · ${LF.esc(l.contact.phone || l.contact.whatsapp || l.contact.email || '')}</div></a>`).join('')).addClass('show');
      }, () => {});
    }, 250);
  }).on('keydown', function (e) {
    if (e.key === 'Escape') { $('#globalSearchResults').removeClass('show'); }
    if (e.key === 'Enter') { window.location.href = '/leads?q=' + encodeURIComponent(this.value.trim()); }
  });
  $(document).on('click', e => { if (!$(e.target).closest('.lf-search').length) $('#globalSearchResults').removeClass('show'); });
})(jQuery, window);
