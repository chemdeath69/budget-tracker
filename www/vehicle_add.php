<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/vehicles.php';
require __DIR__ . '/lib/layout.php';
require_login();

/**
 * Add or edit a manually-tracked vehicle (TODO2 #40). A vehicle is a manual account
 * (items.source='manual', manual_type='vehicle'; accounts.type='vehicle') so it counts
 * in net worth automatically once balance_current is set — like a manual 401(k). The
 * depreciation BASIS lives in vehicle_assets; balance_current is recomputed from it on
 * save + nightly (lib/vehicles.php). Identity can be auto-filled from the FREE NHTSA
 * vPIC VIN decode, or typed by hand. `?account_id=` = edit an existing vehicle (owner only).
 *
 * Flow (server-rendered, no app.js): the "Decode VIN" button POSTs action=decode → fills
 * the identity fields → re-renders the form for review; "Save" POSTs action=save.
 */

$pdo = db();
$uid = current_user_id();

$accountId = (string)($_GET['account_id'] ?? ($_POST['account_id'] ?? ''));
$editing   = false;
$acct      = null;
if ($accountId !== '') {
    $acct = q_account($pdo, $uid, $accountId);
    if (!$acct || ($acct['type'] ?? '') !== 'vehicle' || (int)($acct['owner_id'] ?? 0) !== $uid) {
        flash_set('error', 'Vehicle not found, or you can only edit your own.');
        header('Location: /index.php');
        exit;
    }
    $editing = true;
}

// Working copy of the form fields (defaults → DB on edit-GET → $_POST below).
$f = [
    'vin' => '', 'year' => '', 'make' => '', 'model' => '', 'trim' => '', 'body_class' => '',
    'nickname' => '', 'visibility' => 'shared',
    'purchase_price' => '', 'purchase_date' => '',
    'depreciation_method' => 'declining', 'annual_rate' => '15',
    'manual_value' => '',
];

/** Prefill a decimal field without a thousands separator (the S25 comma-in-number trap). */
$dec = static fn($v) => ($v === null || $v === '') ? '' : number_format((float)$v, 2, '.', '');

if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $va = q_vehicle_asset($pdo, $accountId) ?? [];
    $f['vin']        = (string)($va['vin'] ?? '');
    $f['year']       = ($va['year'] ?? null) ? (string)(int)$va['year'] : '';
    $f['make']       = (string)($va['make'] ?? '');
    $f['model']      = (string)($va['model'] ?? '');
    $f['trim']       = (string)($va['trim'] ?? '');
    $f['body_class'] = (string)($va['body_class'] ?? '');
    $f['purchase_price'] = $dec($va['purchase_price'] ?? '');
    $f['purchase_date']  = (string)($va['purchase_date'] ?? '');
    $f['depreciation_method'] = ($va['depreciation_method'] ?? 'declining') === 'straight' ? 'straight' : 'declining';
    $f['annual_rate']  = isset($va['annual_rate']) ? (string)(float)$va['annual_rate'] : '15';
    $f['manual_value'] = $dec($va['manual_value'] ?? '');
}

$decodeError = '';
$decodeNote  = '';
$formError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_request()) {
        flash_set('error', 'Your session expired — please try again.');
        header('Location: /vehicle_add.php' . ($editing ? '?account_id=' . urlencode($accountId) : ''));
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    // Pull the submitted fields into $f (so a decode/validation re-render keeps the user's input).
    foreach (array_keys($f) as $k) {
        if (isset($_POST[$k])) $f[$k] = trim((string)$_POST[$k]);
    }
    $f['depreciation_method'] = $f['depreciation_method'] === 'straight' ? 'straight' : 'declining';
    $f['visibility'] = $f['visibility'] === 'private' ? 'private' : 'shared';

    if ($action === 'decode') {
        $res = vehicle_vin_decode($f['vin']);
        if (!empty($res['ok'])) {
            $f['vin'] = (string)$res['vin'];
            if ($res['year'])       $f['year']       = (string)$res['year'];
            if ($res['make'])       $f['make']       = (string)$res['make'];
            if ($res['model'])      $f['model']      = (string)$res['model'];
            if ($res['trim'])       $f['trim']       = (string)$res['trim'];
            if ($res['body_class']) $f['body_class'] = (string)$res['body_class'];
            $decodeNote = 'Decoded: ' . trim($f['year'] . ' ' . $f['make'] . ' ' . $f['model'])
                        . ($f['body_class'] !== '' ? ' · ' . $f['body_class'] : '') . '. Review and save.';
        } else {
            $decodeError = (string)($res['error'] ?? 'Could not decode that VIN.');
        }
        // fall through to re-render the form (never saves on decode)
    } elseif ($action === 'save') {
        // ---- Normalise + validate -----------------------------------------
        $cap  = static fn($s, $n) => function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);

        $year = null;
        if (ctype_digit($f['year'])) {
            $y = (int)$f['year'];
            if ($y >= 1900 && $y <= (int)date('Y') + 2) $year = $y;
        }
        $vinStore = vehicle_clean_vin($f['vin']);
        $vinStore = $vinStore !== '' ? $cap($vinStore, 32) : null;
        $make  = $f['make']  !== '' ? $cap($f['make'], 64)  : null;
        $model = $f['model'] !== '' ? $cap($f['model'], 96) : null;
        $trim  = $f['trim']  !== '' ? $cap($f['trim'], 96)  : null;
        $body  = $f['body_class'] !== '' ? $cap($f['body_class'], 64) : null;

        $pp = (is_numeric($f['purchase_price']) && is_finite((float)$f['purchase_price']) && (float)$f['purchase_price'] > 0)
            ? round((float)$f['purchase_price'], 2) : null;
        $pd = null;
        if ($f['purchase_date'] !== '' && ($ts = strtotime($f['purchase_date'])) !== false) {
            $pd = date('Y-m-d', $ts);
        }
        $method = $f['depreciation_method'];
        $rate = (is_numeric($f['annual_rate']) && is_finite((float)$f['annual_rate']))
            ? max(0.0, min(VEHICLE_MAX_RATE, (float)$f['annual_rate'])) : VEHICLE_DEFAULT_RATE;
        $mv = (is_numeric($f['manual_value']) && is_finite((float)$f['manual_value']) && (float)$f['manual_value'] >= 0)
            ? round((float)$f['manual_value'], 2) : null;

        // Need a value source so the vehicle isn't a $0 phantom asset (honest-number).
        if ($mv === null && !($pp !== null && $pd !== null)) {
            $formError = 'Enter a current value, OR a purchase price and date so we can estimate one.';
        }

        if ($formError === '') {
            $name = $editing
                ? (string)$acct['name']
                : ($f['nickname'] !== '' ? $cap($f['nickname'], 255)
                                         : trim($f['year'] . ' ' . ($make ?? '') . ' ' . ($model ?? '')));
            if ($name === '') $name = 'Vehicle';
            $inst = trim(($year ?: '') . ' ' . ($make ?? '') . ' ' . ($model ?? ''));
            if ($inst === '') $inst = 'Vehicle';
            $mvDate = $mv !== null ? date('Y-m-d') : null;

            try {
                $pdo->beginTransaction();
                if (!$editing) {
                    $accountId = 'mnl_' . bin2hex(random_bytes(16));
                    $itemId    = 'mnl_' . bin2hex(random_bytes(16));
                    $pdo->prepare(
                        'INSERT INTO items (item_id, user_id, source, manual_type, institution_name, status)
                         VALUES (?,?,"manual","vehicle",?,"active")'
                    )->execute([$itemId, $uid, $inst]);
                    $pdo->prepare(
                        'INSERT INTO accounts (account_id, item_id, name, type, subtype, iso_currency_code, visibility)
                         VALUES (?,?,?,"vehicle","vehicle","USD",?)'
                    )->execute([$accountId, $itemId, $name, $f['visibility']]);
                }
                // Upsert the basis row (positional binds + VALUES() → HY093-safe).
                $pdo->prepare(
                    'INSERT INTO vehicle_assets
                       (account_id, vin, year, make, model, trim, body_class,
                        purchase_price, purchase_date, depreciation_method, annual_rate,
                        manual_value, manual_value_date, updated_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       vin=VALUES(vin), year=VALUES(year), make=VALUES(make), model=VALUES(model),
                       trim=VALUES(trim), body_class=VALUES(body_class),
                       purchase_price=VALUES(purchase_price), purchase_date=VALUES(purchase_date),
                       depreciation_method=VALUES(depreciation_method), annual_rate=VALUES(annual_rate),
                       manual_value=VALUES(manual_value), manual_value_date=VALUES(manual_value_date),
                       updated_by=VALUES(updated_by)'
                )->execute([
                    $accountId, $vinStore, $year, $make, $model, $trim, $body,
                    $pp, $pd, $method, $rate, $mv, $mvDate, $uid,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('vehicle_add error: ' . $e->getMessage());
                flash_set('error', 'Could not save the vehicle.');
                header('Location: /vehicle_add.php' . ($editing ? '?account_id=' . urlencode($accountId) : ''));
                exit;
            }
            // Recompute the modelled value into balance_current (so net worth picks it up now).
            vehicle_save_balance($pdo, $accountId);
            flash_set('ok', $editing ? 'Vehicle updated.' : 'Vehicle added. It now counts toward your net worth.');
            header('Location: /account.php?account_id=' . urlencode($accountId));
            exit;
        }
    }
}

render_header($editing ? 'Edit vehicle' : 'Add a vehicle', $editing ? '' : 'settings',
    ['back' => $editing ? '/account.php?account_id=' . urlencode($accountId) : '/settings.php', 'narrow' => true]);
?>

<?php foreach (flash_take() as $fl): ?>
    <div class="notice <?= $fl['type'] === 'error' ? 'warn' : ($fl['type'] === 'ok' ? 'ok' : '') ?>"><?= e($fl['msg']) ?></div>
<?php endforeach; ?>
<?php if ($formError !== ''): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>
<?php if ($decodeError !== ''): ?><div class="notice warn"><?= e($decodeError) ?></div><?php endif; ?>
<?php if ($decodeNote !== ''): ?><div class="notice ok"><?= e($decodeNote) ?></div><?php endif; ?>

<section class="card">
    <h2><?= $editing ? 'Edit vehicle' : 'Track a vehicle' ?></h2>
    <p class="muted">Add a car, truck or motorcycle so it counts toward your net worth. There's no free
        market-value feed (KBB/Black Book are paid), so we estimate today's value from what you paid plus
        a depreciation rate you choose — or you can enter a value you looked up yourself.</p>

    <!-- VIN decode (optional) — fills the identity fields from the free NHTSA database. -->
    <form method="post" class="stack-form veh-vin-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="decode">
        <?php if ($editing): ?><input type="hidden" name="account_id" value="<?= e($accountId) ?>"><?php endif; ?>
        <?php foreach (['nickname','visibility','year','make','model','trim','body_class','purchase_price','purchase_date','depreciation_method','annual_rate','manual_value'] as $k): ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($f[$k]) ?>">
        <?php endforeach; ?>
        <label class="field">
            <span class="field-label">VIN <span class="muted">(optional — decodes the year/make/model)</span></span>
            <div class="veh-vin-row">
                <input class="input" type="text" name="vin" maxlength="32" value="<?= e($f['vin']) ?>"
                       placeholder="e.g. 1FTFW1EF6BFA44564" autocomplete="off" autocapitalize="characters">
                <button class="btn-ghost" type="submit">Decode VIN</button>
            </div>
        </label>
    </form>

    <!-- The actual save form. -->
    <form method="post" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="account_id" value="<?= e($accountId) ?>"><?php endif; ?>
        <input type="hidden" name="vin" value="<?= e($f['vin']) ?>">
        <input type="hidden" name="body_class" value="<?= e($f['body_class']) ?>">

        <?php if (!$editing): ?>
        <label class="field">
            <span class="field-label">Nickname <span class="muted">(optional — defaults to year/make/model)</span></span>
            <input class="input" type="text" name="nickname" maxlength="255" value="<?= e($f['nickname']) ?>" placeholder="e.g. My truck">
        </label>
        <?php endif; ?>

        <div class="veh-grid">
            <label class="field">
                <span class="field-label">Year</span>
                <input class="input" type="number" name="year" min="1900" max="<?= (int)date('Y') + 2 ?>" value="<?= e($f['year']) ?>" placeholder="2011">
            </label>
            <label class="field">
                <span class="field-label">Make</span>
                <input class="input" type="text" name="make" maxlength="64" value="<?= e($f['make']) ?>" placeholder="Ford">
            </label>
            <label class="field">
                <span class="field-label">Model</span>
                <input class="input" type="text" name="model" maxlength="96" value="<?= e($f['model']) ?>" placeholder="F-150">
            </label>
            <label class="field">
                <span class="field-label">Trim <span class="muted">(optional)</span></span>
                <input class="input" type="text" name="trim" maxlength="96" value="<?= e($f['trim']) ?>" placeholder="XLT">
            </label>
        </div>

        <?php if (!$editing): ?>
        <label class="field">
            <span class="field-label">Visibility</span>
            <select class="select" name="visibility">
                <option value="shared"<?= $f['visibility'] === 'shared' ? ' selected' : '' ?>>Shared — both of you see it (counts in the combined total)</option>
                <option value="private"<?= $f['visibility'] === 'private' ? ' selected' : '' ?>>Private — only you</option>
            </select>
        </label>
        <?php endif; ?>

        <hr class="veh-sep">
        <p class="muted veh-section-note">How we value it. Either enter a <strong>current value</strong> you looked up, or a
            <strong>purchase price + date</strong> and we'll depreciate it from there (you can change the rate).</p>

        <div class="veh-grid">
            <label class="field">
                <span class="field-label">Purchase price</span>
                <input class="input" type="number" step="0.01" min="0" inputmode="decimal" name="purchase_price" value="<?= e($f['purchase_price']) ?>" placeholder="32000">
            </label>
            <label class="field">
                <span class="field-label">Purchase date</span>
                <input class="input date-input" type="date" name="purchase_date" value="<?= e($f['purchase_date']) ?>">
            </label>
            <label class="field">
                <span class="field-label">Depreciation</span>
                <select class="select" name="depreciation_method">
                    <option value="declining"<?= $f['depreciation_method'] === 'declining' ? ' selected' : '' ?>>Declining balance (typical for cars)</option>
                    <option value="straight"<?= $f['depreciation_method'] === 'straight' ? ' selected' : '' ?>>Straight line</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">Rate (%/yr)</span>
                <input class="input" type="number" step="0.1" min="0" max="<?= (int)VEHICLE_MAX_RATE ?>" inputmode="decimal" name="annual_rate" value="<?= e($f['annual_rate']) ?>" placeholder="15">
            </label>
        </div>

        <label class="field">
            <span class="field-label">Current value override <span class="muted">(optional — wins over the estimate)</span></span>
            <input class="input" type="number" step="0.01" min="0" inputmode="decimal" name="manual_value" value="<?= e($f['manual_value']) ?>" placeholder="e.g. a KBB / dealer quote">
        </label>

        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Add vehicle' ?></button>
    </form>
</section>

<?php render_footer(); ?>
