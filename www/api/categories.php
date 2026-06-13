<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/queries.php';   // normalize_category_tag(), pfc_primary_categories(), q_custom_categories()

/**
 * Custom (user-defined) categories (migration 024) — household-shared, like budgets/rules/
 * goals (NO owner check). POST JSON, auth + CSRF.
 *   action=add    : {label, exclude_from_spending?}            → create a category
 *   action=update : {id, label?, exclude_from_spending?}       → edit; a label change that
 *                   regenerates the tag REWRITES every reference (overrides/rules/splits/budgets)
 *   action=delete : {id}                                       → REVERT references
 *                   (transactions → Plaid default, splits → UNCATEGORIZED, rules/budgets removed)
 *
 * A tag is the canonical code stored in the four free-text category slots; EFF_CAT
 * (lib/queries.php) resolves it, so there is no apply/backfill step — a create/rename/delete
 * changes the reads on the next page load.
 */
header('Content-Type: application/json');
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid csrf token']);
    exit;
}

$pdo = db();
$uid = current_user_id();
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

/** Trim + cap a label to the column width (96). */
$clipLabel = function (string $s): string {
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, 96) : substr($s, 0, 96);
};

/** Return the single custom_categories row (assoc) by tag, or null. */
$rowByTag = function (PDO $pdo, string $tag): ?array {
    $st = $pdo->prepare('SELECT id FROM custom_categories WHERE tag = ?');
    $st->execute([$tag]);
    $id = $st->fetchColumn();
    return $id === false ? null : ['id' => (int)$id];
};

try {
    if ($action === 'add') {
        $label = $clipLabel((string)($in['label'] ?? ''));
        $tag   = normalize_category_tag($label);
        $excl  = !empty($in['exclude_from_spending']) ? 1 : 0;

        if ($label === '' || $tag === '') {
            http_response_code(400);
            echo json_encode(['error' => 'a category name is required (it must contain a letter or number)']);
            exit;
        }
        if (in_array($tag, pfc_primary_categories(), true)) {
            http_response_code(422);
            echo json_encode(['error' => 'that name matches a built-in category', 'tag' => $tag]);
            exit;
        }
        if ($rowByTag($pdo, $tag)) {
            http_response_code(422);
            echo json_encode(['error' => 'a category with that code already exists', 'tag' => $tag]);
            exit;
        }
        $st = $pdo->prepare(
            'INSERT INTO custom_categories (tag, label, exclude_from_spending, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([$tag, $label, $excl, $uid]);
        echo json_encode(['ok' => true, 'category' => [
            'id'                    => (int)$pdo->lastInsertId(),
            'tag'                   => $tag,
            'label'                 => $label,
            'exclude_from_spending' => $excl,
        ]]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'valid id required']);
            exit;
        }
        $cur = $pdo->prepare('SELECT id, tag, label, exclude_from_spending FROM custom_categories WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'category not found']);
            exit;
        }
        $oldTag    = (string)$row['tag'];
        $newLabel  = array_key_exists('label', $in) ? $clipLabel((string)$in['label']) : (string)$row['label'];
        $newExcl   = array_key_exists('exclude_from_spending', $in)
            ? (!empty($in['exclude_from_spending']) ? 1 : 0)
            : (int)$row['exclude_from_spending'];

        if ($newLabel === '') {
            http_response_code(400);
            echo json_encode(['error' => 'a category name is required']);
            exit;
        }
        $newTag = normalize_category_tag($newLabel);
        if ($newTag === '') {
            http_response_code(400);
            echo json_encode(['error' => 'that name has no usable letters or numbers']);
            exit;
        }

        if ($newTag !== $oldTag) {
            // Renaming the code — validate the new code is free, then rewrite every reference.
            if (in_array($newTag, pfc_primary_categories(), true)) {
                http_response_code(422);
                echo json_encode(['error' => 'that name matches a built-in category', 'tag' => $newTag]);
                exit;
            }
            $clash = $rowByTag($pdo, $newTag);
            if ($clash && $clash['id'] !== $id) {
                http_response_code(422);
                echo json_encode(['error' => 'another category already uses that code', 'tag' => $newTag]);
                exit;
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE transactions      SET category_override = ? WHERE category_override = ?')->execute([$newTag, $oldTag]);
            $pdo->prepare('UPDATE category_rules     SET category          = ? WHERE category          = ?')->execute([$newTag, $oldTag]);
            $pdo->prepare('UPDATE transaction_splits SET category          = ? WHERE category          = ?')->execute([$newTag, $oldTag]);
            $pdo->prepare('UPDATE budgets            SET category          = ? WHERE category          = ?')->execute([$newTag, $oldTag]);
            $pdo->prepare('UPDATE custom_categories  SET tag = ?, label = ?, exclude_from_spending = ? WHERE id = ?')
                ->execute([$newTag, $newLabel, $newExcl, $id]);
            $pdo->commit();
        } else {
            // Cosmetic label / flag change only — tag (identity) unchanged.
            $pdo->prepare('UPDATE custom_categories SET label = ?, exclude_from_spending = ? WHERE id = ?')
                ->execute([$newLabel, $newExcl, $id]);
        }
        echo json_encode(['ok' => true, 'category' => [
            'id'                    => $id,
            'tag'                   => $newTag,
            'label'                 => $newLabel,
            'exclude_from_spending' => $newExcl,
        ]]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'valid id required']);
            exit;
        }
        $cur = $pdo->prepare('SELECT tag FROM custom_categories WHERE id = ?');
        $cur->execute([$id]);
        $tag = $cur->fetchColumn();
        if ($tag === false) {
            http_response_code(404);
            echo json_encode(['error' => 'category not found']);
            exit;
        }
        $tag = (string)$tag;

        $pdo->beginTransaction();
        $sTx = $pdo->prepare('UPDATE transactions      SET category_override = NULL          WHERE category_override = ?');
        $sTx->execute([$tag]);
        $sSp = $pdo->prepare("UPDATE transaction_splits SET category = 'UNCATEGORIZED'        WHERE category = ?");
        $sSp->execute([$tag]);
        $sRu = $pdo->prepare('DELETE FROM category_rules WHERE category = ?');
        $sRu->execute([$tag]);
        $sBu = $pdo->prepare('DELETE FROM budgets        WHERE category = ?');
        $sBu->execute([$tag]);
        $pdo->prepare('DELETE FROM custom_categories WHERE id = ?')->execute([$id]);
        $pdo->commit();

        echo json_encode(['ok' => true, 'deleted' => 1, 'impact' => [
            'transactions' => $sTx->rowCount(),
            'splits'       => $sSp->rowCount(),
            'rules'        => $sRu->rowCount(),
            'budgets'      => $sBu->rowCount(),
        ]]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'could not save category']);
}
