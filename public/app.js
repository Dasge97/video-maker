// video-maker: what every screen shares (theme, toasts, confirmation, requests). Each screen
// adds its own script at the end of its template.
'use strict';

const App = (() => {
  // ---- Theme: the one of the system until the person chooses light or dark ----
  const toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    toggle.addEventListener('click', () => {
      const dark = document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
      const next = dark ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('theme', next); } catch (e) { /* private window */ }
    });
  }

  // ---- Toasts ----
  const toasts = document.querySelector('[data-toasts]');
  const icons = { success: 'M20 6 9 17l-5-5', danger: 'M12 8v4M12 16h.01', info: 'M12 16v-4M12 8h.01' };
  function toast(message, kind = 'info') {
    const el = document.createElement('div');
    el.className = `notice ${kind === 'danger' ? 'bad' : ''} toast`;
    el.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
    el.innerHTML = `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${kind === 'success' ? '' : '<circle cx="12" cy="12" r="10"/>'}<path d="${icons[kind] || icons.info}"/></svg><p></p>`;
    el.querySelector('p').textContent = message;
    toasts.appendChild(el);
    setTimeout(() => el.remove(), kind === 'danger' ? 9000 : 5000);
  }

  // ---- Own confirmation dialog (never confirm() of the browser) ----
  const dialog = document.querySelector('[data-confirm]');
  function confirm(title, text, okLabel = 'Confirmar', danger = false) {
    return new Promise((resolve) => {
      dialog.querySelector('[data-confirm-title]').textContent = title;
      dialog.querySelector('[data-confirm-text]').textContent = text;
      const ok = dialog.querySelector('[data-confirm-ok]');
      ok.textContent = okLabel;
      ok.className = danger ? 'btn btn-danger btn-sm' : 'btn btn-primary btn-sm';
      const done = (value) => { dialog.close(); resolve(value); };
      ok.onclick = () => done(true);
      dialog.querySelector('[data-confirm-cancel]').onclick = () => done(false);
      dialog.oncancel = () => resolve(false);
      dialog.showModal();
      ok.focus();
    });
  }

  // ---- Requests: JSON back, errors as {field: message} ----
  async function request(url, options = {}) {
    let response;
    try {
      response = await fetch(url, { headers: { Accept: 'application/json', ...(options.headers || {}) }, ...options });
    } catch (e) {
      return { ok: false, errors: { '': 'No se ha podido conectar con el programa. ¿Está el servidor en marcha?' } };
    }
    let data = {};
    try { data = await response.json(); } catch (e) { /* not JSON */ }
    if (!response.ok) {
      return { ok: false, status: response.status, errors: data.errors || { '': 'Algo ha fallado en el servidor. Mira var/log o el registro del vídeo.' } };
    }
    return { ok: true, data };
  }
  const postJson = (url, body) => request(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const postForm = (url, form) => request(url, { method: 'POST', body: form instanceof FormData ? form : new FormData(form) });

  /** A button that shows it is working while the promise runs. */
  async function busy(button, work) {
    if (button) { button.classList.add('loading'); button.disabled = true; }
    try { return await work(); } finally { if (button) { button.classList.remove('loading'); button.disabled = false; } }
  }

  /** The first general error of an answer, for a toast. */
  const firstError = (errors) => errors[''] || Object.values(errors)[0] || 'Algo ha fallado.';

  /** Actions on buttons with data-post: confirmation if asked, then POST and follow the url. */
  document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-post]');
    if (!button) return;
    e.preventDefault();
    if (button.dataset.confirmTitle && !(await confirm(button.dataset.confirmTitle, button.dataset.confirmText || '', button.dataset.confirmOk || 'Confirmar', 'danger' in button.dataset))) return;
    const result = await busy(button, () => request(button.dataset.post, { method: 'POST' }));
    if (!result.ok) { toast(firstError(result.errors), 'danger'); return; }
    if (button.dataset.done) toast(button.dataset.done, 'success');
    if (result.data.url) window.location.href = result.data.url;
    else if ('reload' in button.dataset) window.location.reload();
  });

  /** Asks for the state of a video every `every` ms while `keepGoing(state)` says so. */
  function poll(url, onState, every = 2000) {
    let stopped = false;
    const tick = async () => {
      if (stopped) return;
      const result = await request(url);
      if (result.ok && onState(result.data) === false) { stopped = true; return; }
      setTimeout(tick, every);
    };
    tick();
    return () => { stopped = true; };
  }

  const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (usd) => `${Number(usd).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} $`;
  /** Words said aloud: a figure is one word. */
  const words = (text) => (text || '').trim().split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w)).length;

  /**
   * «Añadir elemento» (templates/_element_dialog.html.twig): asks for a product or a place with
   * its name and its image, uploaded, chosen from «Imágenes» or generated. Resolves with
   * { kind, name, cards, file, library, url } or null when cancelled; `file` is the upload,
   * `library` the hash of an image of «Imágenes», `url` something to show it with.
   */
  function elementDialog() {
    const dialog = document.querySelector('[data-element-dialog]');
    const q = (s) => dialog.querySelector(s);
    const qa = (s) => dialog.querySelectorAll(s);
    let kind = 'product';
    let source = 'upload';
    let picked = { file: null, library: null, url: null };
    const radio = (attr, value) => qa(`[${attr}]`).forEach((b) => b.setAttribute('aria-checked', String(b.getAttribute(attr) === value)));
    const preview = () => {
      const box = q('[data-el-preview]');
      box.classList.toggle('hidden', !picked.url);
      box.style.backgroundImage = picked.url ? `url("${picked.url}")` : '';
    };
    const setKind = (k) => { kind = k; radio('data-el-kind', k); q('[data-el-cards-row]').classList.toggle('hidden', k !== 'place'); };
    const setSource = async (s) => {
      source = s;
      radio('data-el-source', s);
      qa('[data-el-panel]').forEach((p) => p.classList.toggle('hidden', p.dataset.elPanel !== s));
      if (s === 'library') {
        const result = await request(dialog.dataset.listUrl);
        const images = (result.ok ? result.data : []).filter((i) => i.kind !== 'logo');
        q('[data-el-grid]').innerHTML = images.map((i) => `<button type="button" data-hash="${i.hash}" data-url="${i.url}" title="${escape(i.prompt)}"><img src="${i.url}" alt="${escape(i.prompt)}" loading="lazy"></button>`).join('');
        q('[data-el-grid-empty]').classList.toggle('hidden', images.length > 0);
      }
    };
    // Every opening starts clean.
    setKind('product');
    setSource('upload');
    q('[data-el-name]').value = '';
    q('[data-el-prompt]').value = '';
    q('[data-el-file]').value = '';
    q('[data-el-file-name]').textContent = 'Elegir una imagen';
    q('[data-el-cards]').checked = false;
    q('[data-el-error]').textContent = '';
    preview();

    return new Promise((resolve) => {
      qa('[data-el-kind]').forEach((b) => { b.onclick = () => setKind(b.dataset.elKind); });
      qa('[data-el-source]').forEach((b) => { b.onclick = () => setSource(b.dataset.elSource); });
      q('[data-el-file]').onchange = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        picked = { file, library: null, url: URL.createObjectURL(file) };
        q('[data-el-file-name]').textContent = file.name;
        preview();
      };
      q('[data-el-grid]').onclick = (e) => {
        const b = e.target.closest('[data-hash]');
        if (!b) return;
        picked = { file: null, library: b.dataset.hash, url: b.dataset.url };
        qa('[data-el-grid] button').forEach((o) => o.classList.toggle('on', o === b));
        preview();
      };
      q('[data-el-generate]').onclick = async (e) => {
        const data = new FormData();
        data.append('kind', kind);
        data.append('prompt', q('[data-el-prompt]').value);
        q('[data-el-error]').textContent = '';
        const result = await busy(e.currentTarget, () => postForm(dialog.dataset.createUrl, data));
        if (!result.ok) { q('[data-el-error]').textContent = firstError(result.errors); return; }
        picked = { file: null, library: result.data.image.hash, url: result.data.image.url };
        preview();
      };
      const close = (value) => { dialog.close(); resolve(value); };
      q('[data-el-cancel]').onclick = () => close(null);
      dialog.oncancel = () => resolve(null);
      q('[data-el-ok]').onclick = () => {
        const name = q('[data-el-name]').value.trim();
        if (name.length < 2) { q('[data-el-error]').textContent = 'Ponle un nombre corto, como «Botella Brisa» o «La tienda».'; return; }
        if (!picked.file && !picked.library) { q('[data-el-error]').textContent = 'Sube su imagen, elige una de «Imágenes» o genérala.'; return; }
        close({ kind, name, cards: kind === 'place' && q('[data-el-cards]').checked, ...picked });
      };
      dialog.showModal();
      q('[data-el-name]').focus();
    });
  }

  return { toast, confirm, request, postJson, postForm, busy, firstError, poll, escape, money, words, elementDialog };
})();
