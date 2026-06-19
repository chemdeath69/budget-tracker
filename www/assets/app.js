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
/* Curated "Quiet Wealth" chart-series palette (brass · teal · slate · gold ·
   sage · clay · rose · taupe), read from the CSS --ch-* tokens so it stays
   theme-aware (light/dark). Mirrors the same tokens used by .cat-swatch /
   chart_slice_color() (layout.php). Cached once — charts don't live-recolour on
   a theme toggle (they recolour on the next page load, same as before). */
let _chartPal = null;
function chartPalette() {
  if (_chartPal) return _chartPal;
  const cs = getComputedStyle(document.body);
  const v = (n, fb) => (cs.getPropertyValue(n).trim() || fb);
  _chartPal = [
    v('--ch-1', '#A8814C'), v('--ch-2', '#2C8C7A'), v('--ch-3', '#5E7A8C'), v('--ch-4', '#C8A24C'),
    v('--ch-5', '#7E8B5A'), v('--ch-6', '#B4503F'), v('--ch-7', '#9C6B72'), v('--ch-8', '#7A6A57'),
  ];
  return _chartPal;
}
const sliceColor = i => { const p = chartPalette(); return p[(((i | 0) % p.length) + p.length) % p.length]; };

const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

async function postJSON(url, body, method = 'POST') {
  try {
    const res = await fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
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

    // Cash flow: grouped income/expense bars + a net line on one axis.
    // d = {labels, income:[...], expense:[...], net:[...]}.
    if (type === 'cashflow') {
      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: d.labels,
          datasets: [
            { type: 'bar', label: 'Income', data: d.income, backgroundColor: hexA(c.pos, .72), borderRadius: 4, maxBarThickness: 26, order: 2 },
            { type: 'bar', label: 'Expense', data: d.expense, backgroundColor: hexA(c.neg, .72), borderRadius: 4, maxBarThickness: 26, order: 2 },
            { type: 'line', label: 'Net', data: d.net, borderColor: c.brand, backgroundColor: hexA(c.brand, .12), borderWidth: 2, tension: .3, pointRadius: 0, pointHoverRadius: 4, fill: false, order: 1 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { callbacks: { label: i => `${i.dataset.label}: ${usd(i.parsed.y)}` } },
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
            y: { grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
          },
        },
      });
    }

    // Stacked category bars over months: d = {labels, series:[{label, values}, …]}.
    // Colours follow the doughnut palette (sliceColor by series index); a trailing
    // "Other" series (last) is rendered muted so it reads as the remainder.
    if (type === 'stackbars') {
      const series = d.series || [];
      const last = series.length - 1;
      const datasets = series.map((s, i) => {
        const isOther = i === last && /^other$/i.test(s.label || '');
        const col = isOther ? c.muted : sliceColor(i);
        return { label: s.label, data: s.values, backgroundColor: hexA(col, .82), borderRadius: 3, maxBarThickness: 46 };
      });
      new Chart(canvas, {
        type: 'bar',
        data: { labels: d.labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { callbacks: { label: i => `${i.dataset.label}: ${usd(i.parsed.y)}` } },
          },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
            y: { stacked: true, grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
          },
        },
      });
    }

    // Stacked AREA over time: d = {labels, series:[{label, values}, …]}.
    // Asset bands fill above zero (doughnut palette); a "Debt" band fills below zero in
    // --neg, so the stack nets to the net-worth line. Dollar-denominated (usd axis/tooltip),
    // with a "Net" tooltip footer. Used by networth.php (#6).
    if (type === 'stackarea') {
      const series = d.series || [];
      const datasets = series.map((s, i) => {
        const col = /^debt$/i.test(s.label || '') ? c.neg : sliceColor(i);
        return {
          label: s.label, data: s.values,
          borderColor: col, backgroundColor: hexA(col, .45),
          borderWidth: 1.5, fill: true, tension: .25, spanGaps: true,
          pointRadius: 0, pointHoverRadius: 3,
        };
      });
      new Chart(canvas, {
        type: 'line',
        data: { labels: d.labels, datasets },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { callbacks: {
              label: i => `${i.dataset.label}: ${usd(i.parsed.y)}`,
              footer: items => 'Net: ' + usd(items.reduce((a, it) => a + (it.parsed.y || 0), 0)),
            } },
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 7, maxRotation: 0 } },
            y: { stacked: true, grid: { color: c.line }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: v => usdCompact(v) } },
          },
        },
      });
    }

    // Money-flow Sankey (#7): income sources → "Income" → spending categories.
    // d = {nodes:[{id,label,column,color}], links:[{from,to,flow}]}. color is a token
    // (brand|pos|neg|muted|slice:N) resolved theme-aware here. Needs the chartjs-chart-sankey
    // plugin (loaded by render_header(['sankey'=>true])); register defensively in case the
    // UMD build exposes a global without auto-registering.
    if (type === 'sankey') {
      if (typeof Chart === 'undefined' || !(d.links && d.links.length)) return;
      try {
        const S = window.ChartSankey;
        if (S && S.SankeyController && !Chart.registry.controllers.get('sankey')) {
          Chart.register(S.SankeyController, S.Flow);
        }
      } catch (e) { /* already registered, or plugin auto-registered */ }
      if (!Chart.registry.controllers.get('sankey')) return;   // plugin missing → leave canvas blank

      const tok = t =>
        t === 'brand' ? c.brand : t === 'pos' ? c.pos : t === 'neg' ? c.neg :
        t === 'muted' ? c.muted : (String(t).startsWith('slice:') ? sliceColor(+String(t).slice(6)) : c.muted);
      const labels = {}, columns = {}, colorByNode = {};
      (d.nodes || []).forEach(n => { labels[n.id] = n.label; columns[n.id] = n.column; colorByNode[n.id] = tok(n.color); });
      const nodeColor = id => colorByNode[id] || c.muted;

      const sankeyChart = new Chart(canvas, {
        type: 'sankey',
        data: { datasets: [{
          data: d.links,
          labels, column: columns,
          colorFrom: ctx => nodeColor(ctx.dataset.data[ctx.dataIndex].from),
          colorTo:   ctx => nodeColor(ctx.dataset.data[ctx.dataIndex].to),
          colorMode: 'gradient',
          alpha: 0.6,
          borderWidth: 0,
          nodeWidth: 10,
          nodePadding: 14,
        }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          layout: { padding: { left: 4, right: 4 } },
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => {
              const l = d.links[ctx.dataIndex] || {};
              return `${labels[l.from] || l.from} → ${labels[l.to] || l.to}: ${usd(l.flow)}`;
            } } },
          },
        },
      });
      // The sankey plugin sometimes measures the container at 0 width on first
      // layout; since the container size never actually changes, the responsive
      // ResizeObserver never fires to correct it. Force one resize next frame.
      requestAnimationFrame(() => { try { sankeyChart.resize(); } catch (e) { /* noop */ } });
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

/* Colour the .cat-swatch legend chips from the SAME curated palette the charts
   use, keyed off each chip's inline --i, so a legend matches its chart's slice
   order. Runs on every page (independent of Chart.js). Plain .cat-swatch only —
   the .pos/.neg/.other variants keep their explicit semantic tokens. */
function initChartSwatches() {
  $$('.cat-swatch').forEach(el => {
    if (el.classList.contains('pos') || el.classList.contains('neg') || el.classList.contains('other')) return;
    const i = parseInt(getComputedStyle(el).getPropertyValue('--i'), 10);
    if (Number.isFinite(i)) el.style.background = sliceColor(i);
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
  // Create a brand-new custom category inline, then assign it.
  const NEW_CAT = '__new__';
  sel.add(new Option('＋ New category…', NEW_CAT));

  chip.replaceWith(sel);

  let done = false;
  const restore = tag => { if (done) return; done = true; if (sel.isConnected) sel.replaceWith(makeCatChip(tx, tag)); };

  sel.addEventListener('change', async () => {
    if (done) return; done = true;
    let category = sel.value;
    if (category === NEW_CAT) {
      const label = (window.prompt('New category name') || '').trim();
      if (!label) { if (sel.isConnected) sel.replaceWith(makeCatChip(tx, currentTag)); return; }
      const created = await postJSON('/api/categories.php', { action: 'add', label });
      if (!created || !created.ok) {
        toast((created && created.error) || 'Could not create the category.');
        if (sel.isConnected) sel.replaceWith(makeCatChip(tx, currentTag));
        return;
      }
      category = created.category.tag;
      CATEGORY_OPTIONS.push({ value: created.category.tag, label: created.category.label });   // available to later pickers this load
    }
    const out = await postJSON('/api/account.php', { action: 'recategorize', transaction_id: tx, category });
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

/* Per-security asset-class override on the Allocation page (#32). A `.class-select`
   change POSTs the override (or 'auto' to clear) and reloads so the mix/drift recompute. */
function initAllocation() {
  $$('.class-select[data-security]').forEach(sel => {
    sel.addEventListener('change', async () => {
      sel.disabled = true;
      const out = await postJSON('/api/allocation.php', { action: 'set_class', security_id: sel.dataset.security, asset_class: sel.value });
      if (out && out.ok) location.reload(); else sel.disabled = false;
    });
  });
}

/* Manual-account statement cadence (Auto/Monthly/Quarterly/Annually/Off). Reloads
   so the overdue warning/tag reflects the new cadence immediately. */
function initStatementCadence() {
  $$('.cadence-select[data-account]').forEach(sel => {
    sel.addEventListener('change', async () => {
      sel.disabled = true;
      const out = await postJSON('/api/account.php', { action: 'cadence', account_id: sel.dataset.account, cadence: sel.value });
      if (out && out.ok) location.reload(); else sel.disabled = false;
    });
  });
}

/* Transient toast notification (bottom-center, auto-dismiss). */
function toast(msg, ms = 4500) {
  let host = $('#toast-host');
  if (!host) { host = document.createElement('div'); host.id = 'toast-host'; host.className = 'toast-host'; document.body.appendChild(host); }
  const el = document.createElement('div');
  el.className = 'toast';
  el.setAttribute('role', 'status');
  el.textContent = msg;
  host.appendChild(el);
  requestAnimationFrame(() => el.classList.add('show'));
  setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); }, ms);
}

/* On-demand "Refresh now" (TODO #13). data-item → one bank; absent → all household
   banks. The endpoint acks instantly and runs the Plaid refresh + sync in the
   background (fastcgi_finish_request), so we DON'T reload — we toast that it's
   happening. Fresh balances/snapshot show on the next load; brand-new charges arrive
   via the webhook. */
function initRefresh() {
  $$('[data-refresh]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Refreshing…';
      const body = btn.dataset.item ? { item_id: btn.dataset.item } : {};
      const out = await postJSON('/api/refresh.php', body);
      btn.textContent = label;
      if (out && out.ok) {
        toast(out.note || 'Refreshing in the background — new data will appear shortly.');
        setTimeout(() => { btn.disabled = false; }, 3000);   // brief anti-spam pause
      } else {
        toast((out && out.error) || 'Could not refresh right now.');
        setTimeout(() => { btn.disabled = false; }, 1000);   // anti-spam pause on error too
      }
    });
  });
}

/* ---- Unlink (remove) a Plaid bank — destructive, owner-only -------------- */
function initUnlink() {
  $$('[data-unlink]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const inst = btn.dataset.institution || 'this bank';
      const n = parseInt(btn.dataset.accounts || '0', 10);
      const acctText = n > 0 ? `${n} account${n === 1 ? '' : 's'}` : 'its accounts';
      const msg = `Remove ${inst}?\n\nThis permanently deletes ${acctText} and ALL their transactions, holdings and history, and revokes access at Plaid. This cannot be undone.`;
      if (!confirm(msg)) return;
      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Removing…';
      const out = await postJSON('/api/unlink.php', { item_id: btn.dataset.item });
      if (out && out.ok) {
        toast(`Removed ${out.removed || inst}.`);
        setTimeout(() => location.reload(), 600);
      } else {
        btn.disabled = false;
        btn.textContent = label;
        toast((out && out.error) || 'Could not remove the bank.');
      }
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
      const rollover = $('#budget-rollover') && $('#budget-rollover').checked ? 1 : 0;
      if (!category || !(monthly_limit > 0)) return;
      const out = await postJSON('/api/budgets.php', { category, monthly_limit, rollover });
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
  // Per-row "roll unspent forward" toggle (#11b) — re-saves the budget (upsert on the
  // recurring NULL-month row) with the new flag, then reloads so the carryover recomputes.
  $$('.budget-roll[data-id]').forEach(t => {
    t.addEventListener('change', async () => {
      const out = await postJSON('/api/budgets.php', {
        category: t.dataset.category,
        monthly_limit: Number(t.dataset.limit),
        rollover: t.checked ? 1 : 0,
      });
      if (out && out.ok) location.reload();
      else { t.checked = !t.checked; toast((out && out.error) || 'Could not update budget'); }
    });
  });
}

/* ---- Savings goals (#9) -------------------------------------------------- */
/* The add/edit form is reused for both: a per-row ✎ Edit populates it with a
   hidden id (→ UPDATE), the + Add button opens it blank (→ INSERT). The source
   <select> toggles the manual "current amount" field — an account-tied goal
   draws its progress from the account balance, so that field is hidden. */
function initGoals() {
  const form = $('#add-goal-form');
  if (!form) return;
  const addBtn = $('#add-goal-btn'), cancelBtn = $('#goal-cancel');
  const idEl = $('#goal-id'), nameEl = $('#goal-name'), targetEl = $('#goal-target');
  const sourceEl = $('#goal-source'), currentField = $('#goal-current-field'), currentEl = $('#goal-current');

  const syncSource = () => { currentField.hidden = sourceEl.value !== 'manual'; };
  const reset = () => { idEl.value = ''; nameEl.value = ''; targetEl.value = ''; sourceEl.value = 'manual'; currentEl.value = ''; syncSource(); };
  const open = () => { form.hidden = false; syncSource(); nameEl.focus(); };

  if (addBtn) addBtn.addEventListener('click', () => { if (form.hidden) { reset(); open(); } else { form.hidden = true; } });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { form.hidden = true; reset(); });
  sourceEl.addEventListener('change', syncSource);

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const name = nameEl.value.trim();
    const target_amount = Number(targetEl.value);
    if (!name || !(target_amount > 0)) return;
    const body = { name, target_amount };
    const id = Number(idEl.value);
    if (id > 0) body.id = id;
    if (sourceEl.value === 'manual') {
      body.source = 'manual';
      body.current_amount = Number(currentEl.value) || 0;
    } else {
      body.source = 'account';
      body.account_id = sourceEl.value.replace(/^acct:/, '');
    }
    const out = await postJSON('/api/goals.php', body);
    if (out && out.ok) location.reload();
    else toast((out && out.error) || 'Could not save goal');
  });

  $$('.goal-edit[data-id]').forEach(btn => {
    btn.addEventListener('click', () => {
      const row = btn.closest('.budget-row');
      if (!row) return;
      idEl.value = row.dataset.id;
      nameEl.value = row.dataset.name || '';
      targetEl.value = row.dataset.target || '';
      sourceEl.value = row.dataset.source || 'manual';
      currentEl.value = row.dataset.current || '';
      open();
    });
  });

  $$('.goal-del[data-id]').forEach(del => {
    del.addEventListener('click', async () => {
      const out = await postJSON('/api/goals.php', { id: Number(del.dataset.id) }, 'DELETE');
      if (out && out.ok) location.reload();
    });
  });
}

/* ---- Alert settings (TODO #14) ------------------------------------------- */
/* Household-shared notification prefs on settings.php. Any [data-alert] control
   change gathers the whole panel and POSTs it to api/alerts.php; toasts on save.
   No reload (the page doesn't depend on these for first paint). Number inputs fire
   `change` on blur/Enter, which is the debounce we want. */
function initAlertSettings() {
  const panel = $('#alert-settings');
  if (!panel) return;
  const controls = $$('[data-alert]', panel);
  const save = async () => {
    const body = {};
    controls.forEach(el => {
      body[el.dataset.alert] = el.type === 'checkbox' ? el.checked : el.value;
    });
    const out = await postJSON('/api/alerts.php', body);
    toast(out && out.ok ? 'Alert settings saved' : ((out && out.error) || 'Could not save settings'));
  };
  controls.forEach(el => el.addEventListener('change', save));
}

/* ---- Customize home — dashboard designer (Phase 3) ----------------------- */
/* customize_home.php: per-widget size segmented controls + ▲/▼ reorder + Reset +
   Save. Vanilla, no drag library. Save gathers the DOM order + each row's active
   size into the layout and POSTs it to /api/prefs.php (dashboard branch). */
function initDashDesigner() {
  const form = document.getElementById('dash-designer');
  if (!form) return;
  const list = document.getElementById('des-list');
  const setSeg = (seg, v) => seg.querySelectorAll('.seg-btn').forEach(x => {
    const on = x.dataset.v === v;
    x.classList.toggle('on', on);
    x.setAttribute('aria-pressed', on ? 'true' : 'false');
  });

  // Segmented size controls — activate the clicked button within its own .seg.
  form.querySelectorAll('.seg').forEach(seg => {
    seg.addEventListener('click', e => {
      const b = e.target.closest('.seg-btn');
      if (!b) return;
      seg.querySelectorAll('.seg-btn').forEach(x => {
        const on = x === b;
        x.classList.toggle('on', on);
        x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      const row = b.closest('.des-row');
      if (row && !row.hasAttribute('data-attention')) row.classList.toggle('off', b.dataset.v === 'off');
    });
  });

  // Reorder via ▲/▼.
  list.addEventListener('click', e => {
    const row = e.target.closest('.des-row');
    if (!row) return;
    if (e.target.closest('.des-up')) { const p = row.previousElementSibling; if (p) list.insertBefore(row, p); }
    else if (e.target.closest('.des-down')) { const n = row.nextElementSibling; if (n) list.insertBefore(n, row); }
  });

  // Reset to the shipped default (catalog order + default sizes, feed pinned on).
  $('#dash-reset').addEventListener('click', () => {
    Array.from(list.children)
      .sort((a, b) => (+a.dataset.order) - (+b.dataset.order))
      .forEach(r => {
        list.appendChild(r);
        setSeg(r.querySelector('.seg'), r.dataset.defaultSize);
        r.classList.toggle('off', r.dataset.defaultSize === 'off');
      });
    const att = form.querySelector('[data-attention] .seg');
    if (att) setSeg(att, 'on');
  });

  // Save → POST the gathered layout, then back to Home.
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const attBtn = form.querySelector('[data-attention] .seg-btn.on');
    const attention_on = attBtn ? attBtn.dataset.v === 'on' : true;
    const cards = Array.from(list.children).filter(r => r.dataset.widget).map(r => {
      const on = r.querySelector('.seg-btn.on');
      return { widget: r.dataset.widget, size: on ? on.dataset.v : 'off' };
    });
    const save = $('#dash-save');
    save.disabled = true;
    const out = await postJSON('/api/prefs.php', { dashboard: { attention_on, cards } });
    if (out && out.ok) { location.href = '/index.php'; }
    else { save.disabled = false; toast((out && out.error) || 'Could not save layout'); }
  });
}

/* ---- Theme (Light/Dark/Auto) — Settings → Appearance (Phase 2) ----------- */
/* The server already renders the saved theme onto <html data-theme> (no flash).
   This segmented control applies the choice instantly for live feedback, then
   persists it via /api/prefs.php; on failure it reverts the UI + the attribute. */
function applyTheme(t) {
  const html = document.documentElement;
  if (t === 'light' || t === 'dark') html.setAttribute('data-theme', t);
  else html.removeAttribute('data-theme');   // 'auto' → media query wins
}
function initTheme() {
  const seg = document.getElementById('theme-seg');
  if (!seg) return;
  seg.addEventListener('click', async (e) => {
    const btn = e.target.closest('.seg-btn[data-theme]');
    if (!btn || btn.classList.contains('on')) return;
    const theme = btn.dataset.theme;
    const prevBtn = seg.querySelector('.seg-btn.on');
    const prevTheme = prevBtn ? prevBtn.dataset.theme : 'auto';
    const setActive = (el) => seg.querySelectorAll('.seg-btn').forEach(b => {
      const on = b === el;
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    setActive(btn);
    applyTheme(theme);                         // optimistic
    const out = await postJSON('/api/prefs.php', { theme });
    if (!out || !out.ok) {
      applyTheme(prevTheme);                    // revert
      if (prevBtn) setActive(prevBtn);
      toast((out && out.error) || 'Could not save theme');
    }
  });
}

/* ---- Transaction notes (#8) ---------------------------------------------- */
/* Click a `.note-btn[data-tx]` → inline input prefilled with the current note;
   Enter / blur commits `action=set_note`, Escape cancels. The button is rebuilt
   reflecting the saved note (blank → "note" placeholder). */
function initTxNotes() {
  $$('.note-btn[data-tx]').forEach(btn => btn.addEventListener('click', () => openNoteInput(btn)));
}
function openNoteInput(btn) {
  const tx = btn.dataset.tx;
  const current = btn.classList.contains('has-note') ? btn.textContent : '';
  const input = document.createElement('input');
  input.type = 'text'; input.className = 'note-input'; input.maxLength = 500;
  input.placeholder = 'Add a note…'; input.value = current;
  btn.replaceWith(input);

  let done = false, busy = false;
  const finish = text => {
    if (done) return; done = true;
    const nb = document.createElement('button');
    nb.type = 'button';
    nb.className = 'meta-btn note-btn' + (text ? ' has-note' : '');
    nb.dataset.tx = tx;
    nb.textContent = text || 'note';
    nb.addEventListener('click', () => openNoteInput(nb));
    if (input.isConnected) input.replaceWith(nb);
  };
  const commit = async () => {
    if (busy || done) return; busy = true;
    const val = input.value.trim();
    if (val === current) { finish(current); return; }
    const out = await postJSON('/api/account.php', { action: 'set_note', transaction_id: tx, note: val });
    finish(out && out.ok ? (out.note || '') : current);
  };
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    else if (e.key === 'Escape') { finish(current); }
  });
  input.addEventListener('blur', () => commit());
  input.focus();
}

/* ---- Transaction tags (#8) ----------------------------------------------- */
let TAG_OPTIONS = [];
function makeTagChip(tx, id, name) {
  const chip = document.createElement('span');
  chip.className = 'tag-chip';
  chip.dataset.tagId = id;
  chip.textContent = '#' + name;
  const x = document.createElement('button');
  x.type = 'button'; x.className = 'tag-x';
  x.dataset.tx = tx; x.dataset.tagId = id;
  x.setAttribute('aria-label', 'Remove tag ' + name);
  x.textContent = '×';
  chip.appendChild(x);
  return chip;
}
function initTxTags() {
  const el = document.getElementById('tag-options');
  if (el) { try { TAG_OPTIONS = JSON.parse(el.textContent) || []; } catch (e) { TAG_OPTIONS = []; } }
  if (!$('.tx-meta')) return;
  // Shared autocomplete datalist for the add-tag input.
  if (TAG_OPTIONS.length && !$('#tag-datalist')) {
    const dl = document.createElement('datalist');
    dl.id = 'tag-datalist';
    TAG_OPTIONS.forEach(t => dl.appendChild(new Option(t, t)));
    document.body.appendChild(dl);
  }
  // Remove a tag — delegated so dynamically-added chips work too.
  document.addEventListener('click', async e => {
    const x = e.target.closest('.tag-x');
    if (!x) return;
    e.preventDefault();
    const out = await postJSON('/api/account.php', { action: 'remove_tag', transaction_id: x.dataset.tx, tag_id: Number(x.dataset.tagId) });
    if (out && out.ok) { const chip = x.closest('.tag-chip'); chip && chip.remove(); }
  });
  $$('.tag-add-btn[data-tx]').forEach(btn => btn.addEventListener('click', () => openTagInput(btn)));
}
function openTagInput(btn) {
  const tx = btn.dataset.tx;
  const wrap = btn.parentElement;   // .tx-tags
  const input = document.createElement('input');
  input.type = 'text'; input.className = 'tag-input'; input.placeholder = 'tag…';
  if ($('#tag-datalist')) input.setAttribute('list', 'tag-datalist');
  btn.replaceWith(input);

  let done = false;
  const finish = () => { if (done) return; done = true; if (input.isConnected) input.replaceWith(btn); };
  const commit = async () => {
    const val = input.value.trim();
    if (!val) { finish(); return; }
    const out = await postJSON('/api/account.php', { action: 'add_tag', transaction_id: tx, tag: val });
    if (!input.isConnected) return;
    if (out && out.ok && out.tag) {
      if (!wrap.querySelector('.tag-chip[data-tag-id="' + out.tag.id + '"]')) {
        wrap.insertBefore(makeTagChip(tx, out.tag.id, out.tag.name), input);
      }
      if (!TAG_OPTIONS.includes(out.tag.name)) TAG_OPTIONS.push(out.tag.name);
      input.value = '';
      input.focus();   // allow adding several in a row
    } else {
      toast((out && out.error) || 'Could not add tag');
    }
  };
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    else if (e.key === 'Escape') { finish(); }
  });
  input.addEventListener('blur', () => setTimeout(finish, 150));
  input.focus();
}

/* ---- Transaction splits (#8) --------------------------------------------- */
/* Click `.split-btn[data-tx]` → inline editor of {category, amount} parts. The
   parts MUST sum to the parent amount (the server enforces it too) — Save stays
   disabled until the remainder is 0. Reuses CATEGORY_OPTIONS (loaded by
   initRecategorize). Save / Un-split reload so the spend figures + row refresh. */
function initTxSplits() {
  $$('.split-btn[data-tx]').forEach(btn => btn.addEventListener('click', () => openSplitEditor(btn)));
}
function openSplitEditor(btn) {
  const meta = btn.closest('.tx-meta');
  if (!meta) return;
  const host = meta.closest('.row') || meta;   // panel renders below the whole row, full-width
  const open = host.querySelector('.split-panel');
  if (open) { open.remove(); return; }   // toggle closed

  const tx = btn.dataset.tx;
  const total = Math.abs(Number(meta.dataset.amount) || 0);
  let initial = [];
  try { initial = JSON.parse(btn.dataset.splits || '[]') || []; } catch (e) { initial = []; }

  const panel = document.createElement('div');
  panel.className = 'split-panel';
  panel.innerHTML =
    '<div class="split-rows"></div>' +
    '<div class="split-foot">' +
      '<button type="button" class="btn-ghost split-add">+ part</button>' +
      '<span class="split-remainder"></span>' +
      '<button type="button" class="btn-ghost split-clear">Un-split</button>' +
      '<button type="button" class="btn-ghost split-cancel">Cancel</button>' +
      '<button type="button" class="btn split-save">Save</button>' +
    '</div>';
  const rowsWrap = panel.querySelector('.split-rows');
  const remEl = panel.querySelector('.split-remainder');
  const saveBtn = panel.querySelector('.split-save');

  const gather = () => $$('.split-row', panel).map(r => ({
    category: r.querySelector('.split-cat').value,
    amount: Number(r.querySelector('.split-amt').value) || 0,
  }));
  const recalc = () => {
    const parts = gather();
    const sum = parts.reduce((a, p) => a + p.amount, 0);
    const rem = Math.round((total - sum) * 100) / 100;
    remEl.textContent = (rem === 0 ? 'Balanced' : 'Remaining ' + usd(rem)) + ' of ' + usd(total);
    remEl.classList.toggle('ok', rem === 0);
    saveBtn.disabled = !(parts.length >= 2 && rem === 0 && parts.every(p => p.category && p.amount > 0));
  };
  // A split sub-categorises an expense — never a transfer/income (the server rejects
  // those too; keeping the dropdown in lock-step avoids a confusing 422).
  const SPLIT_CAT_BLOCKED = ['TRANSFER_IN', 'TRANSFER_OUT', 'INCOME'];
  const addRow = (cat = '', amt = '') => {
    const row = document.createElement('div');
    row.className = 'split-row';
    const sel = document.createElement('select');
    sel.className = 'select split-cat';
    sel.add(new Option('— category —', ''));
    CATEGORY_OPTIONS.filter(c => !SPLIT_CAT_BLOCKED.includes(c.value)).forEach(c => { const o = new Option(c.label, c.value); if (c.value === cat) o.selected = true; sel.add(o); });
    if (cat && !CATEGORY_OPTIONS.some(c => c.value === cat)) { const o = new Option(prettyCat(cat), cat); o.selected = true; sel.add(o); }
    const amtIn = document.createElement('input');
    amtIn.type = 'number'; amtIn.step = '0.01'; amtIn.min = '0';
    amtIn.className = 'input split-amt'; amtIn.value = amt; amtIn.placeholder = '0.00';
    const del = document.createElement('button');
    del.type = 'button'; del.className = 'split-del'; del.textContent = '×'; del.setAttribute('aria-label', 'Remove part');
    row.append(sel, amtIn, del);
    rowsWrap.appendChild(row);
    sel.addEventListener('change', recalc);
    amtIn.addEventListener('input', recalc);
    del.addEventListener('click', () => { row.remove(); recalc(); });
  };

  if (initial.length) initial.forEach(s => addRow(s.category, (Math.round(Number(s.amount) * 100) / 100).toFixed(2)));
  else { addRow(); addRow(); }
  recalc();

  panel.querySelector('.split-add').addEventListener('click', () => { addRow(); recalc(); });
  panel.querySelector('.split-cancel').addEventListener('click', () => { panel.remove(); btn.focus(); });
  panel.querySelector('.split-clear').addEventListener('click', async () => {
    const out = await postJSON('/api/account.php', { action: 'set_splits', transaction_id: tx, splits: [] });
    if (out && out.ok) location.reload(); else toast((out && out.error) || 'Could not clear splits');
  });
  saveBtn.addEventListener('click', async () => {
    saveBtn.disabled = true;
    const out = await postJSON('/api/account.php', { action: 'set_splits', transaction_id: tx, splits: gather() });
    if (out && out.ok) location.reload();
    else { toast((out && out.error) || 'Could not save splits'); recalc(); }
  });

  host.appendChild(panel);
}

/* ---- Category rules (#10) ------------------------------------------------ */
/* Management page (rules.php): add-form submit + per-row delete → api/rules.php,
   then reload (refreshes the list + the per-rule match counts). */
function initRules() {
  const form = $('#add-rule-form');
  if (form) {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const match_type = $('#rule-type').value;
      const match_value = $('#rule-value').value.trim();
      const category = $('#rule-cat').value;
      if (!match_value || !category) { toast('Enter what to match and a category.'); return; }
      const out = await postJSON('/api/rules.php', { action: 'add', match_type, match_value, category });
      if (out && out.ok) location.reload();
      else toast((out && out.error) || 'Could not save the rule.');
    });
  }
  $$('.rule-del[data-id]').forEach(del => {
    del.addEventListener('click', async () => {
      const out = await postJSON('/api/rules.php', { action: 'delete', id: Number(del.dataset.id) });
      if (out && out.ok) location.reload();
      else toast((out && out.error) || 'Could not delete the rule.');
    });
  });
}

/* Inline "+ rule" shortcut on a transaction (render_tx_meta `.rule-add-btn`). Opens a
   small form prefilled from the row's merchant/description + current category; POST
   api/rules.php (add) → toast + reload so the re-bucketing shows. Reuses CATEGORY_OPTIONS
   (loaded by initRecategorize, which boots earlier). */
function initTxRules() {
  $$('.rule-add-btn[data-tx]').forEach(btn => btn.addEventListener('click', () => openRuleEditor(btn)));
}
function openRuleEditor(btn) {
  const meta = btn.closest('.tx-meta');
  if (!meta) return;
  const host = meta.closest('.row') || meta;     // panel renders below the whole row, full-width
  const existing = host.querySelector('.rule-panel');
  if (existing) { existing.remove(); return; }   // toggle closed

  const merchant = (meta.dataset.merchant || '').trim();
  const name = (meta.dataset.name || '').trim();
  const curCat = (meta.dataset.cat || '').trim();
  const hasMerchant = merchant !== '';

  const panel = document.createElement('div');
  panel.className = 'rule-panel';
  panel.innerHTML =
    '<div class="rule-panel-row">' +
      '<select class="select rule-p-type">' +
        '<option value="merchant"' + (hasMerchant ? ' selected' : '') + '>Merchant is</option>' +
        '<option value="contains"' + (hasMerchant ? '' : ' selected') + '>Description contains</option>' +
      '</select>' +
      '<input type="text" class="input rule-p-value" maxlength="255">' +
    '</div>' +
    '<div class="rule-panel-row">' +
      '<span class="muted">categorize as</span>' +
      '<select class="select rule-p-cat"></select>' +
      '<button type="button" class="btn rule-p-save">Add rule</button>' +
      '<button type="button" class="btn-ghost rule-p-cancel">Cancel</button>' +
    '</div>';

  const typeSel = panel.querySelector('.rule-p-type');
  const valIn = panel.querySelector('.rule-p-value');
  const catSel = panel.querySelector('.rule-p-cat');

  const fillValue = () => { valIn.value = (typeSel.value === 'merchant' ? merchant : name).toUpperCase(); };
  fillValue();
  typeSel.addEventListener('change', fillValue);

  // A rule must not target a transfer/income category (the server 422s it too) — it would
  // drop the merchant's spend from the true-expense reads. Same set the split editor blocks.
  const RULE_CAT_BLOCKED = ['TRANSFER_IN', 'TRANSFER_OUT', 'INCOME'];
  CATEGORY_OPTIONS.filter(c => !RULE_CAT_BLOCKED.includes(c.value)).forEach(c => { const o = new Option(c.label, c.value); if (c.value === curCat) o.selected = true; catSel.add(o); });
  if (curCat && !CATEGORY_OPTIONS.some(c => c.value === curCat)) { const o = new Option(prettyCat(curCat), curCat); o.selected = true; catSel.add(o); }

  panel.querySelector('.rule-p-cancel').addEventListener('click', () => { panel.remove(); btn.focus(); });

  panel.querySelector('.rule-p-save').addEventListener('click', async () => {
    const match_value = valIn.value.trim();
    const category = catSel.value;
    if (!match_value || !category) { toast('Enter what to match and a category.'); return; }
    const out = await postJSON('/api/rules.php', { action: 'add', match_type: typeSel.value, match_value, category });
    if (out && out.ok) { toast('Rule saved — recategorizing matching transactions.'); location.reload(); }
    else toast((out && out.error) || 'Could not save the rule.');
  });

  host.appendChild(panel);
}

/* Custom categories management (migration 024) on the Categories & rules page. Add form +
   per-row "not spending" toggle / rename / delete → api/categories.php → reload so the
   pickers, spending math and counts refresh. No-op on pages without these elements. */
function initCategories() {
  const form = $('#add-category-form');
  if (form) {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const label = $('#cat-label').value.trim();
      const exclude_from_spending = $('#cat-exclude').checked ? 1 : 0;
      if (!label) { toast('Enter a category name.'); return; }
      const out = await postJSON('/api/categories.php', { action: 'add', label, exclude_from_spending });
      if (out && out.ok) location.reload();
      else toast((out && out.error) || 'Could not add the category.');
    });
  }
  $$('.catmgr-exclude[data-id]').forEach(cb => {
    cb.addEventListener('change', async () => {
      cb.disabled = true;
      const out = await postJSON('/api/categories.php', { action: 'update', id: Number(cb.dataset.id), exclude_from_spending: cb.checked ? 1 : 0 });
      cb.disabled = false;
      if (out && out.ok) location.reload();
      else { cb.checked = !cb.checked; toast((out && out.error) || 'Could not update the category.'); }
    });
  });
  $$('.catmgr-rename[data-id]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const cur = btn.dataset.label || '';
      const label = (window.prompt('Rename this category', cur) || '').trim();
      if (!label || label === cur) return;
      const out = await postJSON('/api/categories.php', { action: 'update', id: Number(btn.dataset.id), label });
      if (out && out.ok) location.reload();
      else toast((out && out.error) || 'Could not rename the category.');
    });
  });
  $$('.catmgr-del[data-id]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const uses = Number(btn.dataset.uses || 0);
      const msg = uses > 0
        ? 'Delete “' + btn.dataset.label + '”? ' + uses + ' item' + (uses === 1 ? '' : 's') + ' using it will revert to their default category.'
        : 'Delete “' + btn.dataset.label + '”?';
      if (!window.confirm(msg)) return;
      const out = await postJSON('/api/categories.php', { action: 'delete', id: Number(btn.dataset.id) });
      if (out && out.ok) location.reload();
      else toast((out && out.error) || 'Could not delete the category.');
    });
  });
}

function prettyCat(c) {
  c = String(c || '');
  const opt = CATEGORY_OPTIONS.find(o => o.value === c);   // custom categories carry a stored label
  if (opt) return opt.label;
  return c.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, ch => ch.toUpperCase());
}

/* Statement photo-import (Session 55, #25): thumbnail previews with per-page remove,
   and a "Reading…" submit state (the extraction call takes ~10-30s). */
function initStatementImport() {
  const form = document.getElementById('import-form');
  if (!form) return;
  const input = document.getElementById('import-files');
  const previews = document.getElementById('import-previews');
  const submit = document.getElementById('import-submit');
  if (!input || !previews) return;

  // The file input is immutable, so we keep our own list and rebuild a DataTransfer.
  let files = [];
  const MAX = 6;

  function render() {
    previews.innerHTML = '';
    files.forEach((f, i) => {
      const cell = document.createElement('div');
      cell.className = 'file-thumb';
      const img = document.createElement('img');
      img.alt = f.name;
      img.src = URL.createObjectURL(f);
      img.onload = () => URL.revokeObjectURL(img.src);
      const x = document.createElement('button');
      x.type = 'button'; x.className = 'file-thumb-x'; x.textContent = '×';
      x.setAttribute('aria-label', 'Remove page'); x.title = 'Remove page';
      x.addEventListener('click', () => { files.splice(i, 1); sync(); });
      cell.appendChild(img); cell.appendChild(x);
      const n = document.createElement('span'); n.className = 'file-thumb-n'; n.textContent = 'Page ' + (i + 1);
      cell.appendChild(n);
      previews.appendChild(cell);
    });
  }
  function sync() {
    const dt = new DataTransfer();
    files.slice(0, MAX).forEach(f => dt.items.add(f));
    input.files = dt.files;
    render();
  }
  input.addEventListener('change', () => {
    for (const f of input.files) {
      if (f.type.startsWith('image/') && files.length < MAX) files.push(f);
    }
    sync();
  });
  form.addEventListener('submit', () => {
    if (submit) { submit.disabled = true; submit.textContent = 'Reading your statement… (~20s)'; }
  });
}

/* ---- Credit-report import (#28) ------------------------------------------ */
function initCreditImport() {
  const form = document.getElementById('credit-import-form');
  if (!form) return;
  const input = document.getElementById('credit-files');
  const names = document.getElementById('credit-file-names');
  const submit = document.getElementById('credit-import-submit');
  if (input && names) {
    input.addEventListener('change', () => {
      names.textContent = input.files && input.files.length
        ? Array.from(input.files).map(f => f.name).join(', ')
        : '';
    });
  }
  form.addEventListener('submit', () => {
    if (submit) { submit.disabled = true; submit.textContent = 'Reading your report… (this can take ~30s)'; }
  });
}

/* ---- AI assistant (#27) -------------------------------------------------- */
function assistantMarkup(text) {
  // Markdown-lite → safe HTML. Escape FIRST, then apply a tiny subset (bold,
  // bullet lists, paragraphs) so a model reply can never inject markup.
  const esc = s => s.replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const lines = esc(text).split(/\r?\n/);
  let html = '', inList = false;
  const inline = s => s
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
  // Split a markdown table row "| a | b |" into trimmed cells (drop the
  // empty leading/trailing cells from the bounding pipes).
  const cells = row => {
    let parts = row.trim().split('|');
    if (parts.length && parts[0].trim() === '') parts.shift();
    if (parts.length && parts[parts.length - 1].trim() === '') parts.pop();
    return parts.map(c => c.trim());
  };
  const isRow = l => /^\|.*\|/.test(l.trim());
  const isSep = l => /^\|?[\s:|-]*-[\s:|-]*\|?$/.test(l.trim()) && l.includes('-');
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].trim();
    // Markdown table: a row line immediately followed by a |---|---| separator.
    if (isRow(line) && i + 1 < lines.length && isSep(lines[i + 1])) {
      if (inList) { html += '</ul>'; inList = false; }
      const head = cells(line);
      html += '<table><thead><tr>' + head.map(c => '<th>' + inline(c) + '</th>').join('') + '</tr></thead><tbody>';
      i += 2; // skip header + separator
      while (i < lines.length && isRow(lines[i])) {
        const cs = cells(lines[i]);
        html += '<tr>' + head.map((_, k) => '<td>' + inline(cs[k] || '') + '</td>').join('') + '</tr>';
        i++;
      }
      i--; // the for-loop will ++; we stopped on a non-row line
      html += '</tbody></table>';
      continue;
    }
    const m = line.match(/^[-*•]\s+(.*)$/);
    if (m) {
      if (!inList) { html += '<ul>'; inList = true; }
      html += '<li>' + inline(m[1]) + '</li>';
    } else {
      if (inList) { html += '</ul>'; inList = false; }
      if (line) html += '<p>' + inline(line) + '</p>';
    }
  }
  if (inList) html += '</ul>';
  return html || '<p></p>';
}

function initAssistant() {
  const form = $('#assistant-form');
  if (!form) return;
  const thread   = $('#assistant-thread');
  const input    = $('#assistant-input');
  const send     = $('#assistant-send');
  const starters = $('#assistant-starters');
  const history  = [];      // [{role, content}] — visible text turns only
  let busy = false;

  function bubble(role, html, cls) {
    const el = document.createElement('div');
    el.className = 'msg msg-' + role + (cls ? ' ' + cls : '');
    el.innerHTML = html;
    thread.appendChild(el);
    thread.scrollTop = thread.scrollHeight;
    return el;
  }
  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  }

  async function ask(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    busy = true;
    send.disabled = true;
    if (starters) starters.hidden = true;
    bubble('user', assistantMarkup(text));
    history.push({ role: 'user', content: text });
    input.value = '';
    autoGrow();
    const thinking = bubble('assistant', '<span class="dots"><span></span><span></span><span></span></span>', 'thinking');

    try {
      const res = await postJSON('/api/assistant.php', { messages: history });
      thinking.remove();
      if (res && res.ok && res.reply) {
        bubble('assistant', assistantMarkup(res.reply));
        history.push({ role: 'assistant', content: res.reply });
      } else {
        bubble('assistant', assistantMarkup((res && res.error) || 'Sorry, I could not answer that.'), 'error');
      }
    } catch (e) {
      thinking.remove();
      bubble('assistant', assistantMarkup('Sorry — something went wrong. Please try again.'), 'error');
    } finally {
      busy = false;
      send.disabled = false;
      input.focus();
    }
  }

  form.addEventListener('submit', e => { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', autoGrow);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
  });
  if (starters) {
    starters.querySelectorAll('.starter-chip[data-q]').forEach(chip =>
      chip.addEventListener('click', () => ask(chip.getAttribute('data-q'))));
  }
}

/* ---- Refund tracking (#34) ----------------------------------------------- */
/* The `.refund-btn` in a transaction's meta strip (render_tx_meta) toggles the
   "expecting a refund" flag on/off in place (none ↔ pending). Confirming the
   matching credit / marking received happens on refunds.php (initRefunds). */
function initRefundFlag() {
  $$('.refund-btn[data-tx]').forEach(btn => btn.addEventListener('click', async () => {
    if (btn.dataset.busy) return;
    btn.dataset.busy = '1';
    const pending = btn.dataset.status === 'pending';
    const out = await postJSON('/api/account.php', {
      action: pending ? 'refund_unflag' : 'refund_flag',
      transaction_id: btn.dataset.tx,
    });
    delete btn.dataset.busy;
    if (out && out.ok) {
      if (pending) {
        btn.dataset.status = 'none';
        btn.classList.remove('is-pending');
        btn.textContent = '⟳ expect refund';
      } else {
        btn.dataset.status = 'pending';
        btn.classList.add('is-pending');
        btn.textContent = '⟳ refund pending';
        toast('Tracking this refund — see Refunds.');
      }
    } else {
      toast((out && out.error) || 'Could not update refund flag');
    }
  }));
}

/* The refunds.php page: confirm a suggested match / mark received / dismiss / reopen.
   Each mutates via api/account.php then reloads so the lists + totals refresh. */
function initRefunds() {
  if (!$('.refund-list')) return;
  const act = async (btn, body, confirmMsg) => {
    if (btn.dataset.busy) return;
    if (confirmMsg && !confirm(confirmMsg)) return;
    btn.dataset.busy = '1';
    const out = await postJSON('/api/account.php', body);
    if (out && out.ok) { location.reload(); return; }
    delete btn.dataset.busy;
    toast((out && out.error) || 'Could not update');
  };
  $$('.refund-confirm[data-tx][data-match]').forEach(b => b.addEventListener('click', () =>
    act(b, { action: 'refund_resolve', transaction_id: b.dataset.tx, status: 'received', matched_tx_id: b.dataset.match })));
  $$('.refund-received[data-tx]').forEach(b => b.addEventListener('click', () =>
    act(b, { action: 'refund_resolve', transaction_id: b.dataset.tx, status: 'received' })));
  $$('.refund-dismiss[data-tx]').forEach(b => b.addEventListener('click', () =>
    act(b, { action: 'refund_unflag', transaction_id: b.dataset.tx }, 'Remove the refund flag from this purchase?')));
  $$('.refund-reopen[data-tx]').forEach(b => b.addEventListener('click', () =>
    act(b, { action: 'refund_resolve', transaction_id: b.dataset.tx, status: 'pending' })));
}

/* ---- What-if sliders (#35) ----------------------------------------------- */
// A range input mirrors its live value into [data-out] as you drag (data-fmt
// controls formatting), and submits its GET form on release (via data-autosubmit,
// wired in initAutoSubmit) so the server re-runs the projection. Pure progressive
// enhancement — the form still works without JS (a noscript Apply button submits it).
function initWhatif() {
  $$('input[type="range"][data-out]').forEach(el => {
    const out = $(el.dataset.out);
    if (!out) return;
    const fmt = el.dataset.fmt || '';
    const render = () => {
      const v = Number(el.value);
      const p1 = x => x.toFixed(1).replace(/\.0$/, '');
      if (fmt === 'permonth')     out.textContent = v > 0 ? '+' + usdCompact(v) + '/mo' : 'none';
      else if (fmt === 'pct')     out.textContent = p1(v) + '%';
      else if (fmt === 'pctyr')   out.textContent = (v > 0 ? '+' : '') + p1(v) + '%/yr';
      else if (fmt === 'year')    out.textContent = String(v);
      else if (fmt === 'years')   out.textContent = v + (v === 1 ? ' year' : ' years');
      else                        out.textContent = el.value;
    };
    el.addEventListener('input', render);
    render();
  });
}

/* ---- Boot ---------------------------------------------------------------- */
initDrawer();
initCharts();
initChartSwatches();
initFilters();
initAutoSubmit();
initRecategorize();
initVisibility();
initRetirement();
initAllocation();
initRename();
initStatementCadence();
initRefresh();
initUnlink();
initBudgets();
initGoals();
initAlertSettings();
initTheme();
initDashDesigner();
initTxNotes();
initTxTags();
initTxSplits();
initRules();
initTxRules();
initRefundFlag();
initRefunds();
initCategories();
initStatementImport();
initCreditImport();
initAssistant();
initWhatif();
