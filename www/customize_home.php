<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/queries.php';
require __DIR__ . '/lib/dashboard.php';
require __DIR__ . '/lib/layout.php';
require_login();

$pdo = db();
$uid = current_user_id();
$layout  = dash_layout(q_user_prefs($pdo, $uid));
$catalog = dash_widgets();
$order   = array_flip(array_keys($catalog));   // catalog index → for Reset ordering
// The household partner's first name (for the "X sets up their own" note).
$otherFirst = 'the other user';
foreach (household_users() as $hid => $hnm) {
    if ((int)$hid !== (int)$uid) { $otherFirst = trim(explode(' ', (string)$hnm)[0]); break; }
}

render_header('Customize home', 'settings', ['narrow' => true, 'back' => '/index.php']);
?>

<p class="greet">Customize your <em>home</em></p>
<p class="cust-intro">Choose which cards appear on Home and how prominent each is, and use ▲ ▼ to reorder.
   <b>Feature</b> = a large card, <b>Wide</b> = double-width, <b>Small</b> = one cell, <b>Off</b> = hidden
   (still one tap away in the menu). This is <b>your</b> layout — <?= e($otherFirst ?: 'the other user') ?> sets up their own.</p>

<form id="dash-designer" autocomplete="off">
    <!-- Pinned Needs-Attention feed (special: not a bento card, can't be reordered). -->
    <div class="des-row pinned" data-attention>
        <span class="des-icon"><?= nav_icon('home') ?></span>
        <span class="des-m">
            <span class="des-n">Needs Attention feed</span>
            <span class="des-s">Bills due · refunds · overdue statements · broken connections</span>
        </span>
        <span class="seg" role="group" aria-label="Needs Attention feed">
            <?php foreach (['off' => 'Off', 'on' => 'Pinned on top'] as $v => $lbl):
                $sel = $layout['attention_on'] ? ($v === 'on') : ($v === 'off'); ?>
            <button type="button" class="seg-btn<?= $sel ? ' on' : '' ?>" data-v="<?= e($v) ?>"
                    aria-pressed="<?= $sel ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
            <?php endforeach; ?>
        </span>
    </div>

    <div class="des-list" id="des-list">
        <?php foreach ($layout['cards'] as $c):
            $key = $c['widget']; $w = $catalog[$key]; ?>
        <div class="des-row<?= $c['size'] === 'off' ? ' off' : '' ?>" data-widget="<?= e($key) ?>" data-default-size="<?= e($w['size']) ?>" data-order="<?= (int)($order[$key] ?? 0) ?>">
            <span class="des-move">
                <button type="button" class="des-up" aria-label="Move up">▲</button>
                <button type="button" class="des-down" aria-label="Move down">▼</button>
            </span>
            <span class="des-icon"><?= nav_icon($w['icon']) ?></span>
            <span class="des-m">
                <span class="des-n"><?= e($w['label']) ?></span>
                <span class="des-s"><?= e($w['desc']) ?></span>
            </span>
            <span class="seg" role="group" aria-label="<?= e($w['label']) ?> size">
                <?php foreach (DASH_SIZE_LABELS as $v => $lbl):
                    $on = $c['size'] === $v; ?>
                <button type="button" class="seg-btn<?= $on ? ' on' : '' ?>" data-v="<?= e($v) ?>"
                        aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
                <?php endforeach; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="cust-actions">
        <button type="button" class="btn-ghost" id="dash-reset">Reset to default</button>
        <button type="submit" class="btn" id="dash-save">Save layout</button>
    </div>
</form>

<?php render_footer(); ?>
