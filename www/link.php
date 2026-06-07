<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require_login();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link a bank · Budget Tracker</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: time() ?>">
    <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
</head>
<body class="link-page">
    <main class="link-card">
        <h1 id="title">Link a bank account</h1>
        <p class="muted" id="status">Preparing secure connection…</p>
        <script>if (new URLSearchParams(location.search).get('item_id')) { document.getElementById('title').textContent = 'Re-link / update bank'; }</script>
        <p class="error" id="error" hidden></p>
        <a class="btn" id="start" href="#" hidden>Connect a bank</a>
        <p style="margin-top:1.5rem"><a href="/">← Back to dashboard</a></p>
    </main>

    <script>
    async function init() {
        const statusEl = document.getElementById('status');
        const errEl = document.getElementById('error');
        const startBtn = document.getElementById('start');
        try {
            const itemId = new URLSearchParams(location.search).get('item_id');
            const r = await fetch('/api/link_token.php' + (itemId ? ('?item_id=' + encodeURIComponent(itemId)) : ''), { credentials: 'same-origin' });
            const j = await r.json();
            if (!j.link_token) throw new Error(j.error || 'No link token');

            const handler = Plaid.create({
                token: j.link_token,
                onSuccess: async (public_token, metadata) => {
                    statusEl.textContent = 'Saving connection…';
                    const res = await fetch('/api/exchange.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ public_token, institution: metadata.institution })
                    });
                    const out = await res.json();
                    if (out.ok) {
                        statusEl.textContent = 'Linked! Loading your data…';
                        location.href = '/';
                    } else {
                        showError(out.error || 'Could not save the connection.');
                    }
                },
                onExit: (err) => {
                    if (err) showError(err.display_message || err.error_message || 'Link cancelled.');
                    else statusEl.textContent = 'Link cancelled.';
                }
            });

            statusEl.textContent = 'Ready.';
            startBtn.hidden = false;
            startBtn.addEventListener('click', e => { e.preventDefault(); handler.open(); });
            handler.open(); // open immediately
        } catch (e) {
            showError('Could not start Plaid Link. Please try again.');
        }
        function showError(m) { errEl.textContent = m; errEl.hidden = false; statusEl.hidden = true; startBtn.hidden = false; startBtn.textContent = 'Try again'; }
    }
    init();
    </script>
</body>
</html>
