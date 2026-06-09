'use strict';

/* ==========================================================================
   Budget Tracker — front-end behaviour
   Pages are server-rendered; this wires up the interactive bits only.
   ========================================================================== */

const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

const usd = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const usdCompact = n => {
  const a = Math.abs(Number(n) || 0);
  if (a >= 1e6) return '$' + (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
  if (a >= 1e3) return '$' + Math.round(n / 1e3) + 'k';
  return '$' + Math.round(n);
};
const sliceColor = i => `hsl(${(i * 67) % 360},65%,55%)`;

async function postJSON(url, body, method = 'POST') {
  try {
    const res = await fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return await res.json();
  } catch (e) { return null; }
}

/* ---- Navigation drawer --------------------------------------------------- */
function initDrawer() {
  const open = $('#menu-open'), close = $('#menu-close'), scrim = $('#scrim'), drawer = $('#drawer');
  if (!open || !drawer) return;
  const setOpen = on => {
    document.body.classList.toggle('menu-open', on);
    open.setAttribute('aria-expanded', on ? 'true' : 'false');
    drawer.setAttribute('aria-hidden', on ? 'false' : 'true');
    if (on) { const f = drawer.querySelector('a, button'); f && f.focus(); }
    else { open.focus(); }
  };
  open.addEventListener('click', () => setOpen(true));
  close && close.addEventListener('click', () => setOpen(false));
  scrim && scrim.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.body.classList.contains('menu-open')) setOpen(false); });

  // On desktop the drawer is a permanent sidebar (CSS): expose it to assistive
  // tech and make sure no left-over mobile "open" state lingers across resizes.
  const desktop = window.matchMedia('(min-width: 1024px)');
  const syncDesktop = () => {
    if (desktop.matches) { document.body.classList.remove('menu-open'); drawer.setAttribute('aria-hidden', 'false'); }
    else { drawer.setAttribute('aria-hidden', 'true'); }
  };
  desktop.addEventListener('change', syncDesktop);
  syncDesktop();
}

/* ---- Charts -------------------------------------------------------------- */
function themeColors() {
  const cs = getComputedStyle(document.body);
  const v = n => cs.getPropertyValue(n).trim();
  return {
    ink: v('--ink-2') || '#475569', muted: v('--muted') || '#8a94a6',
    line: v('--line') || '#e6e9ef', brand: v('--brand') || '#4f46e5',
    pos: v('--pos') || '#16a34a', neg: v('--neg') || '#dc2626',
  };
}

function readData(canvas) {
  const el = document.getElementById(canvas.dataset.src);
  if (!el) return null;
  try { return JSON.parse(el.textContent); } catch (e) { return null; }
}

function initCharts() {
  if (typeof Chart === 'undefined') return;
  const c = themeColors();
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = c.muted;

  $$('canvas[data-chart]').forEach(canvas => {
    const d = readData(canvas);
    if (!d) return;
    const type = canvas.dataset.chart;

    if (type === 'line' || type === 'spark') {
      const ctx = canvas.getContext('2d');
      const grad = ctx.createLinearGradient(0, 0, 0, canvas.height || 240);
      grad.addColorStop(0, hexA(c.brand, .28));
      grad.addColorStop(1, hexA(c.brand, 0));
      const spark = type === 'spark';
      new Chart(canvas, {
        type: 'line',
        data: { labels: d.labels, datasets: [{
          data: d.values, borderColor: c.brand, backgroundColor: grad,
          borderWidth: 2, fill: true, tension: .3, pointRadius: 0, pointHoverRadius: spark ? 0 : 4,
        }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { enabled: !spark, callbacks: { label: i => usd(i.parsed.y) } } },
          scales: spark
            ? { x: { display: false }, y: { display: false } }
            : {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 5, maxRotation: 0 } },
                y: { grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
              },
        },
      });
    }

    // Multi-series line: d = {labels, series:[{label, values, color?, dashed?,
    // fill?, fillTo?(dataset index for a band), faint?, points?, legend?}]}.
    // color is a token (brand|pos|neg|muted) or any CSS color.
    if (type === 'multiline') {
      const resolve = col => ({ brand: c.brand, pos: c.pos, neg: c.neg, muted: c.muted }[col] || col || c.brand);
      const series = d.series || [];
      const datasets = series.map((s, i) => {
        const col = resolve(s.color) || sliceColor(i);
        const fill = (typeof s.fillTo === 'number') ? s.fillTo : (s.fill ? 'origin' : false);
        return {
          label: s.label, data: s.values,
          borderColor: s.faint ? hexA(col, .4) : col,
          backgroundColor: hexA(col, s.faint ? .08 : (fill !== false ? .15 : 0)),
          borderWidth: s.faint ? 1 : 2,
          borderDash: s.dashed ? [5, 4] : [],
          fill, tension: .25, spanGaps: true,
          showLine: s.points ? false : true,
          pointRadius: s.points ? 3 : 0, pointHoverRadius: s.faint ? 0 : 4,
          _legend: s.legend !== false,
        };
      });
      new Chart(canvas, {
        type: 'line',
        data: { labels: d.labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, filter: (it, data) => data.datasets[it.datasetIndex]._legend !== false } },
            tooltip: { filter: it => it.dataset._legend !== false, callbacks: { label: i => `${i.dataset.label}: ${usd(i.parsed.y)}` } },
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
            y: { grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
          },
        },
      });
    }

    // Yearly bars: d = {labels, values, color?}.
    if (type === 'bars') {
      const col = ({ brand: c.brand, pos: c.pos, neg: c.neg, muted: c.muted }[d.color] || d.color || c.brand);
      new Chart(canvas, {
        type: 'bar',
        data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: hexA(col, .72), borderRadius: 4, maxBarThickness: 46 }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: i => usd(i.parsed.y) } } },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 10, maxRotation: 0 } },
            y: { grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
          },
        },
      });
    }

    if (type === 'doughnut') {
      new Chart(canvas, {
        type: 'doughnut',
        data: { labels: d.labels, datasets: [{
          data: d.values, backgroundColor: d.values.map((_, i) => sliceColor(i)),
          borderColor: getComputedStyle(document.body).getPropertyValue('--surface').trim() || '#fff', borderWidth: 2,
        }] },
        options: {
          responsive: true, maintainAspectRatio: false, cutout: '62%',
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: i => `${i.label}: ${usd(i.parsed)}` } } },
        },
      });
    }
  });
}

/* turn a hex/rgb/hsl colour into a translucent rgba()/hsla() with the given alpha */
function hexA(color, alpha) {
  color = color.trim();
  if (color.startsWith('#')) {
    let h = color.slice(1);
    if (h.length === 3) h = h.split('').map(x => x + x).join('');
    const n = parseInt(h, 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
  }
  if (color.startsWith('hsl(')) return color.replace(/^hsl\(/, 'hsla(').replace(/\)$/, `,${alpha})`);
  if (color.startsWith('rgb(')) return color.replace(/^rgb\(/, 'rgba(').replace(/\)$/, `,${alpha})`);
  return color;
}

/* ---- Search / filter (client-side, for bounded already-rendered lists) --- */
// A `.search-input[data-filter="#id"]` instantly hides any `[data-search]`
// descendant of #id that doesn't contain the query. Two optional refinements:
//  · `[data-daygroup]` headers (transactions) collapse when their rows all hide;
//  · `[data-filter-group]` wrappers (account category groups, list columns)
//    collapse when none of their rows match.
// Growing time-series lists use server-side filters + pagination instead — this
// only refines what's already on the page.
function initFilters() {
  $$('.search-input[data-filter]').forEach(input => {
    const container = $(input.dataset.filter);
    if (!container) return;
    const exportSel = input.dataset.export;
    const exportEl = exportSel ? $(exportSel) : null;
    const exportBase = exportEl ? exportEl.getAttribute('href').split('?')[0] : null;
    const exportQuery = exportEl ? (exportEl.getAttribute('href').split('?')[1] || '') : '';

    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      $$('[data-search]', container).forEach(row => {
        row.classList.toggle('is-hidden', q !== '' && !row.dataset.search.includes(q));
      });
      // collapse day-group headers that now have no visible rows
      let dayEl = null, count = 0;
      const finalize = () => { if (dayEl) dayEl.classList.toggle('is-hidden', count === 0); };
      Array.from(container.children).forEach(ch => {
        if (ch.hasAttribute('data-daygroup')) { finalize(); dayEl = ch; count = 0; }
        else if (ch.classList.contains('row') && !ch.classList.contains('is-hidden')) { count++; }
      });
      finalize();
      // collapse marked group wrappers with no matching rows
      $$('[data-filter-group]', container).forEach(g => {
        const hit = $$('[data-search]', g).some(r => !r.classList.contains('is-hidden'));
        g.classList.toggle('is-hidden', q !== '' && !hit);
      });
      // keep CSV export link in sync
      if (exportEl) {
        const parts = [];
        if (exportQuery) parts.push(exportQuery);
        if (q) parts.push('q=' + encodeURIComponent(input.value.trim()));
        exportEl.href = exportBase + (parts.length ? '?' + parts.join('&') : '');
      }
    });
  });
}

/* ---- Auto-submit filter forms -------------------------------------------- */
// A `[data-autosubmit]` control (select / date input) submits its GET filter
// form on change, so picking an account/category/date reloads server-side
// without a separate "Filter" click. The text search still submits on Enter.
function initAutoSubmit() {
  $$('[data-autosubmit]').forEach(el => {
    el.addEventListener('change', () => {
      const form = el.closest('form');
      if (form) form.submit();
    });
  });
}

/* ---- Recategorize -------------------------------------------------------- */
// Category options ([{value:TAG,label:Friendly}]) emitted server-side per page.
let CATEGORY_OPTIONS = [];

const chipTag = chip => {
  const label = chip.textContent.trim();
  return label === 'Set category' ? '' : label.toUpperCase().replace(/ /g, '_');
};

// Build a fresh category chip (button) wired to open the picker on click.
function makeCatChip(tx, tag) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'cat-chip';
  btn.dataset.tx = tx;
  btn.textContent = tag ? prettyCat(tag) : 'Set category';
  btn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); openCatPicker(btn); });
  return btn;
}

// Swap a chip for an inline <select>; commit on change, restore on dismiss.
function openCatPicker(chip) {
  const tx = chip.dataset.tx;
  const currentTag = chipTag(chip);

  const sel = document.createElement('select');
  sel.className = 'cat-chip-select';
  sel.add(new Option('— Plaid default —', ''));
  let matched = false;
  CATEGORY_OPTIONS.forEach(c => {
    const o = new Option(c.label, c.value);
    if (c.value === currentTag) { o.selected = true; matched = true; }
    sel.add(o);
  });
  // Preserve a custom category that isn't in the canonical list.
  if (currentTag && !matched) { const o = new Option(prettyCat(currentTag), currentTag); o.selected = true; sel.add(o); }

  chip.replaceWith(sel);

  let done = false;
  const restore = tag => { if (done) return; done = true; if (sel.isConnected) sel.replaceWith(makeCatChip(tx, tag)); };

  sel.addEventListener('change', async () => {
    if (done) return; done = true;
    const out = await postJSON('/api/account.php', { action: 'recategorize', transaction_id: tx, category: sel.value });
    if (sel.isConnected) sel.replaceWith(makeCatChip(tx, out && out.ok ? out.category : currentTag));
  });
  // Dismissed without choosing → put the original chip back (delay lets a
  // selection's change event win the race over blur).
  sel.addEventListener('blur', () => setTimeout(() => restore(currentTag), 150));

  sel.focus();
  try { sel.showPicker && sel.showPicker(); } catch (e) { /* not all browsers */ }
}

function initRecategorize() {
  const el = document.getElementById('cat-options');
  if (el) { try { CATEGORY_OPTIONS = JSON.parse(el.textContent) || []; } catch (e) { CATEGORY_OPTIONS = []; } }
  $$('.cat-chip[data-tx]').forEach(chip => {
    chip.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); openCatPicker(chip); });
  });
}

/* ---- Account visibility -------------------------------------------------- */
function initVisibility() {
  $$('.vis-select[data-account]').forEach(sel => {
    sel.addEventListener('change', async () => {
      sel.disabled = true;
      await postJSON('/api/account.php', { action: 'visibility', account_id: sel.dataset.account, visibility: sel.value });
      sel.disabled = false;
    });
  });
}

/* Retirement classification override (Auto / Yes / No). Reloads so the change is
   reflected (the account may move between the Investments and Retirement pages). */
function initRetirement() {
  $$('.ret-select[data-account]').forEach(sel => {
    sel.addEventListener('change', async () => {
      sel.disabled = true;
      const out = await postJSON('/api/account.php', { action: 'retirement', account_id: sel.dataset.account, retirement: sel.value });
      if (out && out.ok) location.reload(); else sel.disabled = false;
    });
  });
}

/* Account display-name override. Owner edits the name and saves; we POST
   action=rename and reload so the new name shows everywhere it appears (header,
   dashboard, transactions, …). A blank value reverts to the bank/manual name. */
function initRename() {
  $$('.name-form[data-account]').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const input = form.querySelector('.name-input');
      const btn = form.querySelector('.name-save');
      if (input) input.disabled = true;
      if (btn) btn.disabled = true;
      const out = await postJSON('/api/account.php', { action: 'rename', account_id: form.dataset.account, name: input ? input.value : '' });
      if (out && out.ok) location.reload();
      else { if (input) input.disabled = false; if (btn) btn.disabled = false; }
    });
  });
}

/* ---- Budgets ------------------------------------------------------------- */
function initBudgets() {
  const btn = $('#add-budget-btn'), form = $('#add-budget-form');
  if (btn && form) {
    btn.addEventListener('click', () => { form.hidden = !form.hidden; if (!form.hidden) $('#budget-cat').focus(); });
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const category = $('#budget-cat').value.trim();
      const monthly_limit = Number($('#budget-limit').value);
      if (!category || !(monthly_limit > 0)) return;
      const out = await postJSON('/api/budgets.php', { category, monthly_limit });
      if (out && out.ok) location.reload();
    });
  }
  $$('.budget-del[data-id]').forEach(del => {
    del.addEventListener('click', async e => {
      e.preventDefault();
      const out = await postJSON('/api/budgets.php', { id: Number(del.dataset.id) }, 'DELETE');
      if (out && out.ok) location.reload();
    });
  });
}

function prettyCat(c) { return String(c || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, m => m.toUpperCase()); }

/* ---- Boot ---------------------------------------------------------------- */
initDrawer();
initCharts();
initFilters();
initAutoSubmit();
initRecategorize();
initVisibility();
initRetirement();
initRename();
initBudgets();
