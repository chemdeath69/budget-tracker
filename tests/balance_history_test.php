<?php
declare(strict_types=1);

$testPdo = null;
function db(): PDO
{
    global $testPdo;
    if (!$testPdo instanceof PDO) throw new RuntimeException('test database not initialized');
    return $testPdo;
}

require __DIR__ . '/../www/lib/queries.php';
require __DIR__ . '/../www/lib/assistant.php';

function expect_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function expect_true(bool $actual, string $message): void
{
    expect_same(true, $actual, $message);
}

$pdo = new PDO('sqlite::memory:');
$testPdo = $pdo;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE items (
    item_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, institution_name TEXT,
    institution_id TEXT, status TEXT, error_code TEXT, source TEXT, manual_type TEXT
)');
$pdo->exec('CREATE TABLE accounts (
    account_id TEXT PRIMARY KEY, item_id TEXT NOT NULL, name TEXT, display_name TEXT,
    official_name TEXT, mask TEXT, type TEXT, subtype TEXT, retirement_flag INTEGER,
    balance_available REAL, balance_current REAL, balance_limit REAL, visibility TEXT
)');
$pdo->exec('CREATE TABLE account_balance_history (
    account_id TEXT NOT NULL, snapshot_date TEXT NOT NULL, balance REAL NOT NULL,
    PRIMARY KEY (account_id, snapshot_date)
)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$pdo->exec("INSERT INTO users (id,name,email) VALUES (1,'Test User','test@example.com')");
$pdo->exec('CREATE TABLE goals (
    id INTEGER PRIMARY KEY, name TEXT, target_amount REAL, account_id TEXT,
    current_amount REAL, created_by INTEGER, created_at TEXT
)');

$items = [
    ['mine', 1, 'Mine Bank', 'plaid', null],
    ['theirs', 2, 'Their Bank', 'plaid', null],
    ['manual', 1, 'Manual Broker', 'manual', 'brokerage'],
];
$st = $pdo->prepare('INSERT INTO items
    (item_id,user_id,institution_name,source,manual_type,status) VALUES (?,?,?,?,?,"ok")');
foreach ($items as [$id, $owner, $institution, $source, $manualType]) {
    $st->execute([$id, $owner, $institution, $source, $manualType]);
}

$accounts = [
    // id, item, name, type, subtype, retirement flag, current, visibility
    ['checking', 'mine', 'Checking', 'depository', 'checking', null, 150.0, 'shared'],
    ['savings', 'mine', 'Private savings', 'depository', 'savings', null, 220.0, 'private'],
    ['credit', 'theirs', 'Shared card', 'credit', 'credit card', null, 40.0, 'shared'],
    ['invest', 'mine', 'Taxable brokerage', 'investment', 'brokerage', null, 600.0, 'shared'],
    ['manual_invest', 'manual', 'Manual brokerage', 'investment', 'brokerage', null, 300.0, 'shared'],
    ['new_checking', 'mine', 'New checking', 'depository', 'checking', null, 75.0, 'shared'],
    ['retirement', 'mine', '401k', 'investment', '401k', null, 9999.0, 'shared'],
    ['their_private', 'theirs', 'Their private savings', 'depository', 'savings', null, 8888.0, 'private'],
    ['their_nohist', 'theirs', 'Their new loan', 'loan', 'personal', null, 1234.0, 'private'],
    ['today_only', 'mine', 'Brand new loan', 'loan', 'personal', null, 1000.0, 'shared'],
    ['hidden', 'mine', 'Hidden checking', 'depository', 'checking', null, 7777.0, 'hidden'],
];
$st = $pdo->prepare('INSERT INTO accounts
    (account_id,item_id,name,type,subtype,retirement_flag,balance_current,visibility)
    VALUES (?,?,?,?,?,?,?,?)');
foreach ($accounts as $account) $st->execute($account);

$today = new DateTimeImmutable('today');
$oldDate = $today->modify('first day of -2 months')->format('Y-m-d');
$baseDate = $today->modify('first day of -2 months')->modify('+14 days')->format('Y-m-d');
$missingDate = $today->modify('first day of last month')->modify('+9 days')->format('Y-m-d');
$monthEnd = $today->modify('last day of last month')->format('Y-m-d');
$latestDate = $today->modify('-2 days')->format('Y-m-d');

$selected = ['checking', 'savings', 'credit', 'invest', 'manual_invest', 'new_checking'];
$balances = [
    $oldDate => ['checking'=>100, 'savings'=>200, 'credit'=>50, 'invest'=>500, 'manual_invest'=>250],
    $baseDate => ['checking'=>110, 'savings'=>205, 'credit'=>48, 'invest'=>520, 'manual_invest'=>250, 'new_checking'=>70],
    // Missing credit: this day must never become a comparison/checkpoint.
    $missingDate => ['checking'=>120, 'savings'=>210, 'invest'=>540, 'manual_invest'=>250, 'new_checking'=>72],
    $monthEnd => ['checking'=>130, 'savings'=>215, 'credit'=>45, 'invest'=>560, 'manual_invest'=>275, 'new_checking'=>73],
    $latestDate => ['checking'=>140, 'savings'=>218, 'credit'=>42, 'invest'=>590, 'manual_invest'=>300, 'new_checking'=>74],
];
$st = $pdo->prepare('INSERT INTO account_balance_history (account_id,snapshot_date,balance) VALUES (?,?,?)');
foreach ($balances as $date => $byAccount) {
    foreach ($byAccount as $accountId => $balance) $st->execute([$accountId, $date, $balance]);
}
// Rows that must not leak into the cohort or arithmetic.
foreach ([
    ['retirement', $baseDate, 9000], ['their_private', $baseDate, 8000], ['hidden', $baseDate, 7000],
    ['today_only', $today->format('Y-m-d'), 1000],
] as $row) $st->execute($row);

$groups = ['checking', 'savings', 'credit', 'investments'];
$result = q_balance_history($pdo, 1, $groups, 365);

expect_same('complete', $result['coverage_status'], 'complete visible cohort is comparable');
expect_same(6, $result['account_count'], 'retirement, hidden, and another owner private account are excluded');
expect_same($baseDate, $result['earliest_complete_date'], 'new account moves baseline to first complete overlap');
expect_same(3, $result['complete_snapshot_count'], 'only full-cohort dates count as complete');

$roster = array_column($result['accounts'], null, 'id');
expect_true(isset($roster['manual_invest']), 'manual account is disclosed in roster');
expect_same(true, $roster['manual_invest']['manual'], 'manual source is identified');
expect_same($baseDate, $roster['new_checking']['first_snapshot'], 'per-account coverage reports first snapshot');

expect_same(1305.0, $result['current']['net'], 'assets add and credit liability subtracts');
expect_same([
    'checking' => 225.0,
    'savings' => 220.0,
    'credit' => -40.0,
    'investments' => 900.0,
], $result['current']['groups'], 'current group contributions are exact and signed');

$eligibleDates = array_column($result['complete_snapshots'], 'date');
expect_true(!in_array($oldDate, $eligibleDates, true), 'pre-new-account date is incomplete');
expect_true(!in_array($missingDate, $eligibleDates, true), 'missing daily row is not zero-filled or carried forward');
expect_same([$baseDate, $monthEnd, $latestDate], $eligibleDates, 'only full-cohort dates are emitted');

expect_same($baseDate, $result['baseline']['date'], 'baseline uses earliest complete snapshot');
expect_same(1107.0, $result['baseline']['net'], 'baseline includes the same signed cohort');
expect_same(198.0, $result['comparison']['change'], 'comparison is application-computed');
expect_same(17.9, $result['comparison']['percent'], 'comparison percent is application-computed');
expect_same([
    'checking' => 45.0,
    'savings' => 15.0,
    'credit' => 8.0,
    'investments' => 130.0,
], $result['comparison']['group_changes'], 'comparison includes exact signed per-group changes');
expect_same($baseDate, $result['monthly'][0]['date'], 'partial first month includes overlap baseline');
expect_same('baseline', $result['monthly'][0]['kind'], 'first monthly point is labelled baseline');
expect_same($today->format('Y-m-d'), $result['monthly'][count($result['monthly']) - 1]['date'], 'current endpoint is included');
expect_same('current', $result['monthly'][count($result['monthly']) - 1]['kind'], 'last monthly point is current');
expect_same(101.0, $result['monthly'][1]['change'], 'monthly net change is computed by application code');
expect_true(str_contains(implode(' ', $result['caveats']), 'Manual'), 'manual-account flat-balance caveat is explicit');
expect_true(str_contains(implode(' ', $result['caveats']), 'shortens'), 'shortened comparison window is explained');

$none = q_balance_history($pdo, 2, ['loans'], 365);
expect_same('no_overlap', $none['coverage_status'], 'visible account without history returns explicit no-overlap');
expect_same(2, $none['account_count'], 'private/shared visibility is applied to the asking user');
expect_true(str_contains(implode(' ', $none['caveats']), 'Their new loan'), 'unavailable overlap names the uncovered visible account');

$insufficient = q_balance_history($pdo, 1, ['loans'], 365);
expect_same('insufficient_history', $insufficient['coverage_status'], 'today-only overlap is explicitly insufficient');
expect_true(str_contains(implode(' ', $insufficient['caveats']), 'begins today'), 'insufficient-history reason is explicit');

$tools = array_column(assistant_tools(), null, 'name');
expect_true(isset($tools['get_balance_history']), 'tool is registered under the referenced name');
$dispatch = assistant_dispatch($pdo, 1, 'get_balance_history', ['groups' => $groups, 'lookback_days' => 365]);
expect_same($baseDate, $dispatch['earliest_complete_date'], 'dispatcher exposes the stable-overlap result');
expect_true(!array_key_exists('complete_snapshots', $dispatch), 'dispatcher keeps raw daily history out of model context');
expect_same('Reviewing balance history', assistant_tool_label('get_balance_history'), 'streaming label is specific');

$httpRound = 0;
$seenToolResult = null;
$fakeHttp = function (string $key, array $body) use (&$httpRound, &$seenToolResult): array {
    $httpRound++;
    expect_same('test-key', $key, 'end-to-end loop uses configured transport key');
    if ($httpRound === 1) {
        $names = array_column($body['tools'], 'name');
        expect_true(in_array('get_balance_history', $names, true), 'end-to-end request exposes balance-history tool');
        expect_true(str_contains((string)$body['messages'][0]['content'], 'checking, savings, credit'), 'reported question reaches the model loop');
        return [
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'content' => [[
                'type' => 'tool_use', 'id' => 'tool-1', 'name' => 'get_balance_history',
                'input' => ['groups' => ['checking', 'savings', 'credit', 'investments'], 'lookback_days' => 365],
            ]],
        ];
    }
    $last = $body['messages'][count($body['messages']) - 1]['content'][0] ?? [];
    $seenToolResult = json_decode((string)($last['content'] ?? ''), true);
    $baseline = $seenToolResult['baseline']['net'] ?? null;
    $current = $seenToolResult['current']['net'] ?? null;
    $months = count($seenToolResult['monthly'] ?? []);
    return [
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 200, 'output_tokens' => 40],
        'content' => [['type' => 'text', 'text' => "Combined baseline {$baseline}, current {$current}, with {$months} monthly checkpoints."]],
    ];
};
$answer = assistant_respond($pdo, 1, [[
    'role' => 'user',
    'content' => 'Reviewing checking, savings, credit, and non-retirement investments, compare today with the earliest complete date and report monthly changes.',
]], ['anthropic' => ['api_key' => 'test-key', 'assistant_model' => 'test-model']], null, $fakeHttp);
expect_same(true, $answer['ok'], 'end-to-end assistant loop completes');
expect_same(['get_balance_history'], $answer['tools'], 'reported question uses balance-history tool');
expect_same($baseDate, $seenToolResult['baseline']['date'], 'tool result reaches the model turn');
expect_true(str_contains($answer['reply'], 'Combined baseline 1107'), 'final answer reports the combined baseline');
expect_true(str_contains($answer['reply'], 'current 1305'), 'final answer reports the combined current value');
expect_true(str_contains($answer['reply'], 'monthly checkpoints'), 'final answer reports monthly figures');
expect_true(!str_contains(strtolower($answer['reply']), 'unavailable'), 'final answer no longer claims stored history is unavailable');

$pdo->exec('DELETE FROM accounts');
$empty = q_balance_history($pdo, 1, ['checking'], 365);
expect_same('no_accounts', $empty['coverage_status'], 'an empty selected cohort is explicit');

echo "balance_history_test: PASS\n";
