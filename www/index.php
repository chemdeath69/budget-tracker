<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require_login();

$who = $_SESSION['name'] ?? current_user_email();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Budget Tracker</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <header class="topbar">
        <div class="brand">Budget Tracker</div>
        <nav>
            <a href="/link.php" class="btn-sm">+ Link a bank</a>
            <span class="who"><?= e($who) ?></span>
            <a href="/logout.php">Sign out</a>
        </nav>
    </header>

    <main class="container" id="app">
        <div id="loading" class="muted">Loading…</div>

        <section id="stats" class="stats-grid" hidden></section>

        <section id="empty" class="card" hidden>
            <h2>No bank accounts linked yet</h2>
            <p class="muted">Connect your first bank to start tracking balances, transactions, spending and net worth.</p>
            <a class="btn" href="/link.php">Link a bank account</a>
        </section>

        <div class="charts-grid">
            <div class="card" id="networth-card" hidden>
                <h2>Net worth over time</h2>
                <canvas id="networth-chart"></canvas>
            </div>
            <div class="card" id="spending-card" hidden>
                <h2>Spending by category (last 30 days)</h2>
                <canvas id="spending-chart"></canvas>
            </div>
        </div>

        <section class="card" id="accounts-card" hidden>
            <h2>Accounts</h2>
            <div class="table-wrap">
                <table id="accounts-table">
                    <thead><tr><th>Account</th><th>Type</th><th class="num">Available</th><th class="num">Current</th><th>Visibility</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="card" id="liabilities-card" hidden>
            <h2>Liabilities</h2>
            <div class="table-wrap">
                <table id="liabilities-table">
                    <thead><tr><th>Account</th><th>Type</th><th class="num">Balance</th><th class="num">APR</th><th>Due date</th><th class="num">Min payment</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="card" id="holdings-card" hidden>
            <h2>Investments</h2>
            <div class="table-wrap">
                <table id="holdings-table">
                    <thead><tr><th>Security</th><th>Account</th><th class="num">Quantity</th><th class="num">Price</th><th class="num">Value</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="card" id="budgets-card" hidden>
            <div class="table-head">
                <h2>Monthly budgets</h2>
                <button class="btn-sm" id="add-budget-btn" type="button">+ Add budget</button>
            </div>
            <form id="add-budget-form" hidden style="margin-bottom:1rem; display:flex; gap:.5rem; flex-wrap:wrap; align-items:center">
                <input id="budget-cat" placeholder="Category (e.g. FOOD_AND_DRINK)" style="flex:1; min-width:220px; padding:.45rem .7rem; border:1px solid var(--line); border-radius:8px">
                <input id="budget-limit" type="number" min="1" step="1" placeholder="Monthly $ limit" style="width:160px; padding:.45rem .7rem; border:1px solid var(--line); border-radius:8px">
                <button class="btn" type="submit" style="margin:0">Save</button>
            </form>
            <div id="budgets-list" class="budgets-list"></div>
            <p class="muted" id="budgets-empty" hidden>No budgets yet. Add one to track spending against a monthly limit.</p>
        </section>

        <section class="card" id="recurring-card" hidden>
            <h2>Recurring & subscriptions</h2>
            <div class="table-wrap">
                <table id="recurring-table">
                    <thead><tr><th>Merchant</th><th>Category</th><th>Frequency</th><th>Account</th><th class="num">Avg amount</th><th>Last</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <section class="card" id="tx-card" hidden>
            <div class="table-head">
                <h2>Recent transactions</h2>
                <div style="display:flex; gap:.5rem; align-items:center">
                    <input type="search" id="table-search" placeholder="Search merchant / category…">
                    <a class="btn-sm" id="export-csv" href="/api/export.php">Export CSV</a>
                </div>
            </div>
            <div class="table-wrap">
                <table id="tx-table">
                    <thead><tr><th>Date</th><th>Merchant</th><th>Category</th><th>Account</th><th class="num">Amount</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="/assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
</body>
</html>
