'use strict';

const usd = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const $ = sel => document.querySelector(sel);

const PALETTE = ['#3b82f6','#ef4444','#10b981','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16','#06b6d4','#a855f7'];

let allTx = [];
let currentUserId = null;

async function load() {
    let data;
    try {
        const res = await fetch('/api/data.php', { credentials: 'same-origin' });
        if (res.status === 401) { location.href = '/login.php'; return; }
        data = await res.json();
    } catch (e) {
        $('#loading').textContent = 'Could not load data.';
        return;
    }
    $('#loading').hidden = true;
    render(data);
}

function render(d) {
    currentUserId = d.user_id;
    const hasAccounts = (d.accounts || []).length > 0;
    if (!hasAccounts) { $('#empty').hidden = false; return; }

    renderStats(d.stats);
    renderAccounts(d.accounts);
    renderLiabilities(d.liabilities);
    renderHoldings(d.holdings);
    renderNetWorth(d.networth);
    renderSpending(d.spending);
    renderRecurring(d.recurring);
    renderTx(d.transactions);
    loadBudgets();
    setupBudgetForm();
}

function renderStats(s) {
    const el = $('#stats');
    el.innerHTML = '';
    for (const [label, val] of [['Net worth', usd(s.net_worth)], ['Assets', usd(s.assets)], ['Liabilities', usd(s.liabilities)], ['Accounts', s.accounts]]) {
        const div = document.createElement('div');
        div.className = 'stat';
        div.innerHTML = `<div class="stat-val">${val}</div><div class="stat-label">${label}</div>`;
        el.appendChild(div);
    }
    el.hidden = false;
}

function renderAccounts(accounts) {
    const tb = $('#accounts-table tbody');
    tb.innerHTML = '';
    for (const a of accounts) {
        const tr = document.createElement('tr');
        const label = (a.name || a.official_name || 'Account') + (a.mask ? ` ••${a.mask}` : '');
        const owned = Number(a.owner_id) === Number(currentUserId);
        const relink = owned ? ` · <a href="/link.php?item_id=${encodeURIComponent(a.item_id)}" title="Re-link to grant consent (e.g. cards, investments) or fix a connection">↻ re-link</a>` : '';
        const visCell = owned
            ? `<select class="vis-select" data-account="${esc(a.account_id)}">
                 <option value="shared"${a.visibility === 'shared' ? ' selected' : ''}>shared</option>
                 <option value="private"${a.visibility === 'private' ? ' selected' : ''}>private</option>
               </select>`
            : `<span class="tag">${esc(a.visibility)}</span>`;
        tr.innerHTML =
            `<td>${esc(label)}<div class="muted" style="font-size:.8rem">${esc(a.institution_name || '')}${relink}</div></td>` +
            `<td>${esc([a.type, a.subtype].filter(Boolean).join(' / '))}</td>` +
            `<td class="num">${a.balance_available != null ? usd(a.balance_available) : '—'}</td>` +
            `<td class="num">${a.balance_current != null ? usd(a.balance_current) : '—'}</td>` +
            `<td>${visCell}</td>`;
        tb.appendChild(tr);
    }
    tb.querySelectorAll('.vis-select').forEach(sel => sel.addEventListener('change', async ev => {
        await postJSON('/api/account.php', { action: 'visibility', account_id: ev.target.dataset.account, visibility: ev.target.value });
    }));
    $('#accounts-card').hidden = false;
}

function renderLiabilities(rows) {
    if (!rows || rows.length < 1) return;
    const tb = $('#liabilities-table tbody');
    tb.innerHTML = '';
    for (const l of rows) {
        const bal = l.outstanding_balance != null ? l.outstanding_balance : l.balance_current;
        const tr = document.createElement('tr');
        tr.innerHTML =
            `<td>${esc(l.account_name || '')}${l.mask ? ` ••${esc(l.mask)}` : ''}</td>` +
            `<td><span class="tag">${esc(l.liability_type)}</span></td>` +
            `<td class="num">${bal != null ? usd(bal) : '—'}</td>` +
            `<td class="num">${l.apr_percentage != null ? Number(l.apr_percentage).toFixed(2) + '%' : '—'}</td>` +
            `<td>${esc(l.next_payment_due_date || '—')}</td>` +
            `<td class="num">${l.minimum_payment_amount != null ? usd(l.minimum_payment_amount) : '—'}</td>`;
        tb.appendChild(tr);
    }
    $('#liabilities-card').hidden = false;
}

function renderHoldings(rows) {
    if (!rows || rows.length < 1) return;
    const tb = $('#holdings-table tbody');
    tb.innerHTML = '';
    for (const h of rows) {
        const tr = document.createElement('tr');
        const sec = (h.ticker_symbol ? h.ticker_symbol + ' — ' : '') + (h.security_name || '');
        tr.innerHTML =
            `<td>${esc(sec || '—')}</td>` +
            `<td>${esc(h.account_name || '')}${h.mask ? ` ••${esc(h.mask)}` : ''}</td>` +
            `<td class="num">${h.quantity != null ? Number(h.quantity).toLocaleString() : '—'}</td>` +
            `<td class="num">${h.institution_price != null ? usd(h.institution_price) : '—'}</td>` +
            `<td class="num">${h.institution_value != null ? usd(h.institution_value) : '—'}</td>`;
        tb.appendChild(tr);
    }
    $('#holdings-card').hidden = false;
}

function renderNetWorth(series) {
    if (!series || series.length < 1) return;
    $('#networth-card').hidden = false;
    new Chart($('#networth-chart'), {
        type: 'line',
        data: { labels: series.map(p => p.snapshot_date), datasets: [{ label: 'Net worth', data: series.map(p => p.net_worth), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.1)', fill: true, tension: .25 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: v => usd(v) } } } }
    });
}

function renderSpending(rows) {
    if (!rows || rows.length < 1) return;
    $('#spending-card').hidden = false;
    new Chart($('#spending-chart'), {
        type: 'doughnut',
        data: { labels: rows.map(r => prettyCat(r.category)), datasets: [{ data: rows.map(r => Number(r.total)), backgroundColor: PALETTE }] },
        options: { plugins: { legend: { position: 'right' } } }
    });
}

function renderRecurring(rows) {
    if (!rows || rows.length < 1) return;
    const tb = $('#recurring-table tbody');
    tb.innerHTML = '';
    for (const r of rows) {
        const amt = Math.abs(Number(r.average_amount));
        const amtCell = r.direction === 'inflow' ? `<span style="color:#059669">+${usd(amt)}</span>` : usd(amt);
        const tr = document.createElement('tr');
        tr.innerHTML =
            `<td>${esc(r.merchant_name || r.description || '—')}</td>` +
            `<td>${r.category_primary ? `<span class="tag">${esc(prettyCat(r.category_primary))}</span>` : ''}</td>` +
            `<td>${esc(prettyCat(r.frequency || ''))}</td>` +
            `<td>${esc(r.account_name || '')}${r.mask ? ` ••${esc(r.mask)}` : ''}</td>` +
            `<td class="num">${amtCell}</td>` +
            `<td>${esc(r.last_date || '')}</td>`;
        tb.appendChild(tr);
    }
    $('#recurring-card').hidden = false;
}

function renderTx(tx) {
    allTx = tx || [];
    drawTx(allTx);
    $('#tx-card').hidden = false;
    const search = $('#table-search');
    search.addEventListener('input', ev => {
        const q = ev.target.value.toLowerCase();
        drawTx(allTx.filter(t =>
            (t.merchant_name || t.name || '').toLowerCase().includes(q) ||
            (t.category || '').toLowerCase().includes(q)
        ));
        $('#export-csv').href = '/api/export.php' + (q ? ('?q=' + encodeURIComponent(ev.target.value)) : '');
    });
}

function drawTx(rows) {
    const tb = $('#tx-table tbody');
    tb.innerHTML = '';
    for (const t of rows) {
        const tr = document.createElement('tr');
        const merchant = t.merchant_name || t.name || '—';
        const amt = Number(t.amount);
        const amtCell = amt < 0 ? `<span style="color:#059669">+${usd(-amt)}</span>` : usd(amt);
        const catInner = t.category ? `<span class="tag">${esc(prettyCat(t.category))}</span>` : '<span class="muted">set…</span>';
        tr.innerHTML =
            `<td>${esc(t.date)}${t.pending == 1 ? ' <span class="tag">pending</span>' : ''}</td>` +
            `<td>${esc(merchant)}</td>` +
            `<td class="cat-cell" data-tx="${esc(t.transaction_id)}">${catInner}<span class="cat-edit-hint">✎</span></td>` +
            `<td>${esc(t.account_name || '')}${t.mask ? ` ••${esc(t.mask)}` : ''}</td>` +
            `<td class="num">${amtCell}</td>`;
        tb.appendChild(tr);
    }
    tb.querySelectorAll('.cat-cell').forEach(cell => cell.addEventListener('click', () => recategorize(cell)));
}

async function recategorize(cell) {
    const txId = cell.dataset.tx;
    const current = (cell.querySelector('.tag')?.textContent || '').toUpperCase().replace(/ /g, '_');
    const val = prompt('Category for this transaction (blank = revert to Plaid):', current);
    if (val === null) return;
    const out = await postJSON('/api/account.php', { action: 'recategorize', transaction_id: txId, category: val.trim() });
    if (out && out.ok) {
        const t = allTx.find(x => x.transaction_id === txId);
        if (t) t.category = out.category;
        drawTx(filteredTx());
    }
}

function filteredTx() {
    const q = ($('#table-search').value || '').toLowerCase();
    if (!q) return allTx;
    return allTx.filter(t => (t.merchant_name || t.name || '').toLowerCase().includes(q) || (t.category || '').toLowerCase().includes(q));
}

/* ---- Budgets ---- */
async function loadBudgets() {
    let data;
    try { data = await (await fetch('/api/budgets.php', { credentials: 'same-origin' })).json(); }
    catch (e) { return; }
    const list = $('#budgets-list');
    list.innerHTML = '';
    const budgets = data.budgets || [];
    $('#budgets-empty').hidden = budgets.length > 0;
    for (const b of budgets) {
        const pct = b.monthly_limit > 0 ? Math.min(100, (b.spent / b.monthly_limit) * 100) : 0;
        const over = b.spent > b.monthly_limit;
        const row = document.createElement('div');
        row.className = 'budget-row';
        row.innerHTML =
            `<div class="b-head"><span>${esc(prettyCat(b.category))} ${over ? '⚠️' : ''}</span>` +
            `<span class="muted">${usd(b.spent)} / ${usd(b.monthly_limit)} <button class="budget-del" data-id="${b.id}">✕</button></span></div>` +
            `<div class="budget-bar${over ? ' over' : ''}"><span style="width:${pct}%"></span></div>`;
        list.appendChild(row);
    }
    list.querySelectorAll('.budget-del').forEach(btn => btn.addEventListener('click', async () => {
        await postJSON('/api/budgets.php', { id: Number(btn.dataset.id) }, 'DELETE');
        loadBudgets();
    }));
    $('#budgets-card').hidden = false;
}

function setupBudgetForm() {
    const btn = $('#add-budget-btn'), form = $('#add-budget-form');
    if (btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', () => { form.hidden = !form.hidden; });
    form.addEventListener('submit', async ev => {
        ev.preventDefault();
        const category = $('#budget-cat').value.trim();
        const monthly_limit = Number($('#budget-limit').value);
        if (!category || !(monthly_limit > 0)) return;
        await postJSON('/api/budgets.php', { category, monthly_limit });
        $('#budget-cat').value = ''; $('#budget-limit').value = '';
        form.hidden = true;
        loadBudgets();
    });
}

async function postJSON(url, body, method = 'POST') {
    try {
        const res = await fetch(url, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return await res.json();
    } catch (e) { return null; }
}

function prettyCat(c) { return (c || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, m => m.toUpperCase()); }
function esc(s) { return String(s ?? '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m])); }

load();
