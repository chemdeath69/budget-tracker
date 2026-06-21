<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/home_value.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * UI-managed home configuration (migration 031). Moves config['home'] (address +
 * ownership %) into the home_config DB row so the household can add / edit / remove
 * the home here instead of editing config.php. Household-shared (no owner check, like
 * alert_settings / spending_plan). Server-rendered, no app.js — the page handles its
 * own POST (the vehicle_add.php / retirement_settings.php pattern).
 *
 * Valuation: RentCast AVM fills home_values nightly when a key is set; you can ALSO
 * enter a value by hand (stored as a source='manual' home_values row) so the feature
 * works without a RentCast key. Remove (sold) offers a CHOICE — keep net-worth history
 * up to the removal date, or erase the home (and its cached data) entirely.
 */

$pdo = db();
$uid = current_user_id();
$hc  = home_config($pdo);

/** Prefill a decimal without a thousands separator (the S25 comma-in-number trap). */
$dec = static fn($v) => ($v === null || $v === '') ? '' : number_format((float)$v, 2, '.', '');

// Working copy of the form fields (defaults → DB on GET → $_POST on a re-render).
$f = [
    'address'        => $hc['address'],
    'ownership_pct'  => ($hc['value_factor'] !== null && $hc['value_factor'] !== '')
        ? rtrim(rtrim(number_format((float)$hc['value_factor'] * 100, 2, '.', ''), '0'), '.') : '',
    'manual_value'   => $dec($hc['manual_value']),
    'purchase_price' => $dec($hc['purchase_price']),
    'purchase_date'  => (string)($hc['purchase_date'] ?? ''),
];
$removed   = $hc['removed_on'] !== null;
$hasHome   = $hc['address'] !== '';
$hasKey    = hv_api_key() !== '';
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /home_settings.php');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if (function_exists('access_log_action')) {
        access_log_action($pdo, (int)$uid, 'home', $action === 'remove' ? 'remove' : 'save');
    }

    // Keep submitted input on a validation re-render.
    foreach (array_keys($f) as $k) {
        if (isset($_POST[$k])) $f[$k] = trim((string)$_POST[$k]);
    }

    if ($action === 'remove') {
        // ---- Remove the home (the user chooses what happens to history) ---------
        $mode    = ($_POST['mode'] ?? 'keep') === 'erase' ? 'erase' : 'keep';
        $oldAddr = $hc['address'];
        try {
            if ($mode === 'erase') {
                // Drop the home AND its cached history → gone from net worth entirely.
                if ($oldAddr !== '') {
                    $pdo->prepare("DELETE FROM home_values WHERE address = :a")->execute([':a' => $oldAddr]);
                    $pdo->prepare("DELETE FROM property_facts WHERE address = :a")->execute([':a' => $oldAddr]);
                    $zip = hv_zip_from_address($oldAddr);
                    if ($zip !== '') $pdo->prepare("DELETE FROM market_stats WHERE zip = :z")->execute([':z' => $zip]);
                }
                $pdo->prepare(
                    "INSERT INTO home_config (id, address, value_factor, manual_value, manual_value_date,
                        purchase_price, purchase_date, removed_on, updated_by)
                     VALUES (1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, :by)
                     ON DUPLICATE KEY UPDATE address=NULL, value_factor=NULL, manual_value=NULL,
                        manual_value_date=NULL, purchase_price=NULL, purchase_date=NULL,
                        removed_on=NULL, updated_by=VALUES(updated_by)"
                )->execute([':by' => $uid]);
                flash_set('ok', 'Home removed. Its value and history are gone from your net worth.');
            } else {
                // Keep history: just stamp a removal date (the address + home_values stay).
                $rd = ($_POST['removed_on'] ?? '') !== '' && ($ts = strtotime((string)$_POST['removed_on'])) !== false
                    ? date('Y-m-d', $ts) : date('Y-m-d');
                $pdo->prepare(
                    "INSERT INTO home_config (id, removed_on, updated_by) VALUES (1, :d, :by)
                     ON DUPLICATE KEY UPDATE removed_on=VALUES(removed_on), updated_by=VALUES(updated_by)"
                )->execute([':d' => $rd, ':by' => $uid]);
                flash_set('ok', 'Home removed. Net-worth history is kept up to ' . $rd . '.');
            }
        } catch (Throwable $e) {
            error_log('home_settings remove error: ' . $e->getMessage());
            flash_set('error', 'Could not remove the home.');
        }
        header('Location: /home_settings.php');
        exit;
    }

    if ($action === 'save') {
        // ---- Normalise + validate ----------------------------------------------
        $address = function_exists('mb_substr') ? mb_substr($f['address'], 0, 255) : substr($f['address'], 0, 255);
        $address = trim($address);

        $vf = null;   // ownership fraction (0,1]; blank = 100% = null = full value
        if ($f['ownership_pct'] !== '') {
            if (is_numeric($f['ownership_pct']) && is_finite((float)$f['ownership_pct'])) {
                $pct = (float)$f['ownership_pct'];
                if ($pct > 0 && $pct <= 100) $vf = round($pct / 100, 4);
                else $formError = 'Ownership share must be between 1 and 100%.';
            } else {
                $formError = 'Ownership share must be a number.';
            }
        }
        $mv = ($f['manual_value'] !== '' && is_numeric($f['manual_value']) && is_finite((float)$f['manual_value']) && (float)$f['manual_value'] >= 0)
            ? round((float)$f['manual_value'], 2) : null;
        if ($f['manual_value'] !== '' && $mv === null) $formError = $formError ?: 'Enter a valid home value (or leave it blank).';
        $pp = ($f['purchase_price'] !== '' && is_numeric($f['purchase_price']) && is_finite((float)$f['purchase_price']) && (float)$f['purchase_price'] > 0)
            ? round((float)$f['purchase_price'], 2) : null;
        $pd = null;
        if ($f['purchase_date'] !== '' && ($ts = strtotime($f['purchase_date'])) !== false) $pd = date('Y-m-d', $ts);

        if ($address === '') $formError = 'Enter the property address.';

        if ($formError === '') {
            $oldAddr = $hc['address'];
            $addrChanged = strcasecmp(trim($oldAddr), $address) !== 0;
            $mvDate = $mv !== null ? date('Y-m-d') : null;
            try {
                // Upsert the single config row; saving always re-activates a removed home.
                $pdo->prepare(
                    "INSERT INTO home_config (id, address, value_factor, manual_value, manual_value_date,
                        purchase_price, purchase_date, removed_on, updated_by)
                     VALUES (1, :a, :vf, :mv, :mvd, :pp, :pd, NULL, :by)
                     ON DUPLICATE KEY UPDATE address=VALUES(address), value_factor=VALUES(value_factor),
                        manual_value=VALUES(manual_value), manual_value_date=VALUES(manual_value_date),
                        purchase_price=VALUES(purchase_price), purchase_date=VALUES(purchase_date),
                        removed_on=NULL, updated_by=VALUES(updated_by)"
                )->execute([':a' => $address, ':vf' => $vf, ':mv' => $mv, ':mvd' => $mvDate,
                            ':pp' => $pp, ':pd' => $pd, ':by' => $uid]);

                // A hand-entered value → a source='manual' home_values row so every read
                // (net worth, equity card, property page) picks it up. De-dupe today's
                // manual row first so repeated edits don't stack.
                if ($mv !== null) {
                    $pdo->prepare("DELETE FROM home_values WHERE address = :a AND source = 'manual' AND as_of = :d")
                        ->execute([':a' => $address, ':d' => date('Y-m-d')]);
                    home_value_store($pdo, $address, ['value' => $mv, 'value_low' => null, 'value_high' => null], null, 'manual');
                }
            } catch (Throwable $e) {
                error_log('home_settings save error: ' . $e->getMessage());
                flash_set('error', 'Could not save the home.');
                header('Location: /home_settings.php');
                exit;
            }

            // Fetch a fresh RentCast value immediately when the address is new/changed
            // (1 of the 50/mo quota; reserve-before-send caps it). RentCast wins over a
            // same-day manual row (higher id) — it's authoritative when a key is set.
            $note = '';
            if ($addrChanged && $hasKey) {
                try {
                    $r = home_value_refresh_if_stale($pdo, $address);
                    if (empty($r['ok'])) $note = ' (couldn\'t fetch a RentCast value just now: ' . ($r['error'] ?? 'error') . ')';
                } catch (Throwable $e) {
                    error_log('home_settings rentcast error: ' . $e->getMessage());
                }
            }
            flash_set('ok', 'Home saved.' . $note);
            header('Location: /home_settings.php');
            exit;
        }
    }
}

render_header('Home value', 'settings', ['back' => '/settings.php', 'narrow' => true]);
?>

<div class="page-head">
    <p class="eyebrow">Setup</p>
    <h1>Home value &amp; property</h1>
</div>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>
<?php if ($formError !== ''): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>
<?php if ($removed): ?>
    <div class="notice"><strong>This home was removed on <?= e((string)$hc['removed_on']) ?>.</strong>
        Net-worth history still shows it up to that date. Save below to set it up again, or leave it as is.</div>
<?php endif; ?>

<section class="card">
    <h2><?= $hasHome && !$removed ? 'Edit your home' : 'Add your home' ?></h2>
    <p class="muted">Track your home's value against the linked mortgage. The value comes from the
        RentCast estimate<?= $hasKey ? '' : ' (no RentCast key configured — enter a value yourself below)' ?>,
        and you can always set a value by hand. The ownership share only scales how much of the value
        counts toward <strong>net worth</strong> — the Property page always shows the full value.</p>

    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <label class="field">
            <span class="field-label">Address</span>
            <input class="input" type="text" name="address" maxlength="255" value="<?= e($f['address']) ?>"
                   placeholder="123 Main St, Springfield, IL, 62704" autocomplete="off">
        </label>
        <p class="muted home-hint">Format: Street, City, State, Zip — used for the RentCast estimate.</p>

        <label class="field">
            <span class="field-label">Your ownership share <span class="muted">(optional — blank = 100%)</span></span>
            <input class="input" type="number" step="0.01" min="1" max="100" inputmode="decimal"
                   name="ownership_pct" value="<?= e($f['ownership_pct']) ?>" placeholder="100">
        </label>
        <p class="muted home-hint">Set e.g. 50 for a 50/50 co-owned home — only your share counts toward net worth.</p>

        <hr class="veh-sep">
        <p class="muted veh-section-note">Value. RentCast updates this nightly when a key is set; or enter a
            current value you looked up (it applies until the next RentCast refresh).</p>

        <label class="field">
            <span class="field-label">Current value <span class="muted">(optional<?= $hasKey ? '' : ' — recommended, no RentCast key' ?>)</span></span>
            <input class="input" type="number" step="0.01" min="0" inputmode="decimal"
                   name="manual_value" value="<?= e($f['manual_value']) ?>" placeholder="e.g. 450000">
        </label>

        <div class="veh-grid">
            <label class="field">
                <span class="field-label">Purchase price <span class="muted">(optional)</span></span>
                <input class="input" type="number" step="0.01" min="0" inputmode="decimal"
                       name="purchase_price" value="<?= e($f['purchase_price']) ?>" placeholder="380000">
            </label>
            <label class="field">
                <span class="field-label">Purchase date <span class="muted">(optional)</span></span>
                <input class="input date-input" type="date" name="purchase_date" value="<?= e($f['purchase_date']) ?>">
            </label>
        </div>
        <p class="muted home-hint">Purchase price/date anchor the net-worth history before the first valuation
            (otherwise taken from the RentCast property record when available).</p>

        <button class="btn" type="submit"><?= $hasHome && !$removed ? 'Save changes' : 'Add home' ?></button>
    </form>
</section>

<?php if ($hasHome && !$removed): ?>
<section class="card">
    <h2>Remove this home</h2>
    <p class="muted">Sold it, or no longer want to track it? Choose what happens to your past net worth.</p>
    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove">

        <label class="field radio-row">
            <input type="radio" name="mode" value="keep" checked>
            <span><strong>Keep history to a date</strong> — the home stays in net-worth history up to the
                removal date below, then drops off. Recommended when you sold it.</span>
        </label>
        <label class="field">
            <span class="field-label">Removal / sale date</span>
            <input class="input date-input" type="date" name="removed_on" value="<?= e(date('Y-m-d')) ?>">
        </label>

        <label class="field radio-row">
            <input type="radio" name="mode" value="erase">
            <span><strong>Erase entirely</strong> — also delete the cached value/property/market data so the
                home disappears from net-worth history too. Can't be undone.</span>
        </label>

        <button class="btn-ghost danger" type="submit">Remove home</button>
    </form>
</section>
<?php endif; ?>

<?php render_footer(); ?>
