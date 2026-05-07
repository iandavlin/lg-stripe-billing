<?php
/**
 * Programmatic affiliate feature tests.
 * Run: php tests/affiliate_tests.php
 *
 * Tests that don't require a live Stripe session:
 *   T1 - Slug collision returns 409 (API)
 *   T2 - Double-fire idempotency (DB layer direct)
 *   T3 - Non-existent ref is silently ignored (DB layer direct)
 *   T4 - XSS/injection slug is sanitized before it reaches the DB (API)
 *   T5 - Listing returns created affiliates with conversion counts (API)
 *   T6 - Conversion count increments after recording (DB layer direct)
 */

$env = parse_ini_file(__DIR__ . '/../.env');
$token   = $env['LGMS_SHARED_SECRET'] ?? '';
$base    = 'http://localhost/billing/v1';
$dsn     = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_PORT'] ?? '3306',
    $env['DB_NAME'] ?? 'lg_membership',
);
$pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pass = 0;
$fail = 0;

function assert_test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $pass++;
    } else {
        echo "  FAIL  {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

function api(string $method, string $url, array $body = [], string $token = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            $token ? "X-LGMS-Token: {$token}" : null,
        ]),
        CURLOPT_POSTFIELDS     => $body ? json_encode($body) : null,
    ]);
    $body_raw = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body_raw ?: '{}', true) ?? []];
}

// Clean up any test data from previous runs.
$pdo->exec("DELETE FROM affiliate_conversions WHERE stripe_session_id LIKE 'test_session_%'");
$pdo->exec("DELETE FROM affiliates WHERE slug LIKE 'test-%'");

echo "\n=== Affiliate Tests ===\n\n";

// ─────────────────────────────────────────────
// T1 — Slug collision → 409
// ─────────────────────────────────────────────
echo "T1: Slug collision\n";
$r1 = api('POST', "{$base}/affiliates", ['slug' => 'test-collision', 'label' => 'First'], $token);
$r2 = api('POST', "{$base}/affiliates", ['slug' => 'test-collision', 'label' => 'Second'], $token);
assert_test('First creation returns 201', $r1['code'] === 201);
assert_test('Second creation returns 409', $r2['code'] === 409);
assert_test('Error message mentions slug', str_contains((string)($r2['body']['error'] ?? ''), 'test-collision'));

// ─────────────────────────────────────────────
// T2 — Double-fire idempotency
// ─────────────────────────────────────────────
echo "\nT2: Double-fire idempotency (same stripe_session_id inserted twice)\n";
$affRow = $pdo->query("SELECT id FROM affiliates WHERE slug = 'test-collision' LIMIT 1")->fetch();
$affId  = (int) ($affRow['id'] ?? 0);
// Simulate a real customer ID (use 0 — FK not enforced on this column in dev).
$pdo->prepare(
    'INSERT IGNORE INTO affiliate_conversions (affiliate_id, customer_id, stripe_session_id, tier) VALUES (?, 0, ?, ?)'
)->execute([$affId, 'test_session_idem', 'looth2']);
$pdo->prepare(
    'INSERT IGNORE INTO affiliate_conversions (affiliate_id, customer_id, stripe_session_id, tier) VALUES (?, 0, ?, ?)'
)->execute([$affId, 'test_session_idem', 'looth2']);
$count = (int) $pdo->query(
    "SELECT COUNT(*) FROM affiliate_conversions WHERE stripe_session_id = 'test_session_idem'"
)->fetchColumn();
assert_test('Only one row exists after two inserts', $count === 1);

// ─────────────────────────────────────────────
// T3 — Non-existent ref is silently ignored
// ─────────────────────────────────────────────
echo "\nT3: Non-existent ref is silently ignored\n";
require_once __DIR__ . '/../vendor/autoload.php';
$repo = new LGSB\Adapters\PdoAffiliateRepository($pdo);
$before = (int) $pdo->query('SELECT COUNT(*) FROM affiliate_conversions')->fetchColumn();
$repo->recordConversion('this-slug-does-not-exist', 1, 'test_session_noref', 'looth2');
$after  = (int) $pdo->query('SELECT COUNT(*) FROM affiliate_conversions')->fetchColumn();
assert_test('No row written for unknown slug', $after === $before);

// ─────────────────────────────────────────────
// T4 — XSS / injection slug sanitized by API
// ─────────────────────────────────────────────
echo "\nT4: Malicious slug is sanitized before hitting DB\n";
$dirty  = '<script>alert(1)</script>';
$r3     = api('POST', "{$base}/affiliates", ['slug' => $dirty, 'label' => 'XSS Test'], $token);
// After sanitization the slug becomes '-script-alert-1---script-'; either it
// creates with the sanitized slug, or it fails validation — both are acceptable.
// What must NOT happen: a 500, or the literal '<' making it into the DB.
assert_test('Does not return 500', $r3['code'] !== 500);
$rawInDb = $pdo->query("SELECT slug FROM affiliates WHERE slug LIKE '%script%' OR slug LIKE '%<%'")->fetchAll();
assert_test('No raw HTML/JS in DB slug column', $rawInDb === []);

// ─────────────────────────────────────────────
// T5 — List returns affiliates with counts
// ─────────────────────────────────────────────
echo "\nT5: Listing includes affiliates and conversion counts\n";
// Create a fresh affiliate and give it one conversion.
$r4  = api('POST', "{$base}/affiliates", ['slug' => 'test-list-check', 'label' => 'List Check'], $token);
$newId = (int) ($r4['body']['id'] ?? 0);
$pdo->prepare(
    'INSERT IGNORE INTO affiliate_conversions (affiliate_id, customer_id, stripe_session_id, tier) VALUES (?, 0, ?, ?)'
)->execute([$newId, 'test_session_list', 'looth3']);

$r5   = api('GET', "{$base}/affiliates", [], $token);
$list = $r5['body'] ?? [];
$found = array_filter($list, fn($a) => ($a['slug'] ?? '') === 'test-list-check');
$found = array_values($found);
assert_test('GET /v1/affiliates returns 200', $r5['code'] === 200);
assert_test('Created affiliate appears in list', count($found) === 1);
assert_test('Conversion count is 1', (int)($found[0]['conversions'] ?? -1) === 1);

// ─────────────────────────────────────────────
// T6 — Unauthorized requests are rejected
// ─────────────────────────────────────────────
echo "\nT6: Unauthorized requests rejected\n";
$r6 = api('GET',  "{$base}/affiliates", [], 'wrong-token');
$r7 = api('POST', "{$base}/affiliates", ['slug' => 'test-unauth'], 'wrong-token');
assert_test('GET with wrong token → 401', $r6['code'] === 401);
assert_test('POST with wrong token → 401', $r7['code'] === 401);

// ─────────────────────────────────────────────
// T7 — Empty slug rejected
// ─────────────────────────────────────────────
echo "\nT7: Empty slug is rejected\n";
$r8 = api('POST', "{$base}/affiliates", ['slug' => '', 'label' => 'No slug'], $token);
assert_test('Empty slug → 400', $r8['code'] === 400);

// ─────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────
$pdo->exec("DELETE FROM affiliate_conversions WHERE stripe_session_id LIKE 'test_session_%'");
$pdo->exec("DELETE FROM affiliates WHERE slug LIKE 'test-%'");

echo "\n";
echo "─────────────────────────────\n";
echo "  {$pass} passed  |  {$fail} failed\n";
echo "─────────────────────────────\n\n";
exit($fail > 0 ? 1 : 0);
