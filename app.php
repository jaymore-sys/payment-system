<?php
// Bootstrap + helpers. index.php holds the pages.
declare(strict_types=1);

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo 'Something went wrong. Please try again.';
});
date_default_timezone_set('Asia/Kolkata');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);

const STATUSES   = ['pending', 'approved', 'rejected'];             // approval
const PAY_STATES = ['Unpaid', 'Part Paid', 'Paid'];                  // from payouts
const AGEING     = ['not_due' => 'Not Yet Due', 'd1_30' => '1–30 Days Overdue', 'd31_60' => '31–60 Days Overdue',
                    'd61_90' => '61–90 Days Overdue', 'd90' => 'Over 90 Days Overdue'];
const FILE_TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'webp' => 'image/webp', 'xls' => 'application/vnd.ms-excel',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

function cfg(): array
{
    static $c;
    if ($c) return $c;
    // keep config above public_html so Git deploys can't overwrite it and the web can't reach it
    $safe = dirname(__DIR__) . '/payment-config.php';
    if (!is_file($safe) && is_file(__DIR__ . '/config.php')) @rename(__DIR__ . '/config.php', $safe);
    return $c = require (is_file($safe) ? $safe : __DIR__ . '/config.php');
}

function db(): PDO
{
    static $db;
    if ($db) return $db;
    $c = cfg();
    $db = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("SET time_zone = '+05:30'");
    // first run: create everything from schema.sql
    if (!$db->query("SHOW TABLES LIKE 'users'")->fetch()) {
        foreach (schema_statements() as $stmt) $db->exec($stmt);
        return $db;
    }
    // existing database: apply migrations newer than its version, then rebuild the views
    $ver = $db->query("SHOW TABLES LIKE 'app_meta'")->fetch() ? (int) $db->query("SELECT v FROM app_meta WHERE k = 'schema'")->fetchColumn() : 1;
    if ($ver < max(array_keys(MIGRATIONS))) {
        foreach (MIGRATIONS as $n => $stmts) {
            if ($n <= $ver) continue;
            foreach ($stmts as $s) $db->exec($s);
            $db->exec("REPLACE INTO app_meta VALUES ('schema', '$n')");
        }
        foreach (schema_statements() as $s) if (stripos($s, 'CREATE OR REPLACE VIEW') === 0) $db->exec($s);
    }
    return $db;
}

function schema_statements(): array
{
    $sql = preg_replace('/--.*$/m', '', file_get_contents(__DIR__ . '/schema.sql'));
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

// Changes for databases created by older versions. schema.sql already holds the result for new installs.
const MIGRATIONS = [
    2 => [ // roles: admin/staff → superadmin/admin/administrator/developer
        "CREATE TABLE IF NOT EXISTS app_meta (k VARCHAR(30) PRIMARY KEY, v VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE users MODIFY role ENUM('superadmin','admin','administrator','developer','staff') NOT NULL DEFAULT 'administrator'",
        "UPDATE users SET role = 'administrator' WHERE role = 'staff'",
        "ALTER TABLE users MODIFY role ENUM('superadmin','admin','administrator','developer') NOT NULL DEFAULT 'administrator'",
    ],
    3 => [ // invoice no/date + part payments (payouts) replace the single 'paid' status
        "DROP VIEW IF EXISTS upcoming_dues, current_balance, statement",
        "ALTER TABLE payments ADD COLUMN invoice_no VARCHAR(60) AFTER party_id, ADD COLUMN invoice_date DATE AFTER invoice_no",
        "CREATE TABLE payouts (id INT AUTO_INCREMENT PRIMARY KEY, payment_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL, paid_on DATE NOT NULL,
           note VARCHAR(255), created_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
           FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE, FOREIGN KEY (created_by) REFERENCES users(id),
           CONSTRAINT payout_positive CHECK (amount > 0), INDEX (paid_on)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO payouts (payment_id, amount, paid_on, note, created_by) SELECT id, amount, paid_on, 'Paid in full', created_by FROM payments WHERE status = 'paid'",
        "ALTER TABLE payments DROP CONSTRAINT paid_has_date",
        "UPDATE payments SET status = 'approved' WHERE status = 'paid'",
        "ALTER TABLE payments MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', DROP COLUMN paid_on",
    ],
];

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function upload_dir(): string
{
    return cfg()['upload_dir'] ?? dirname(__DIR__) . '/payment-uploads'; // outside public_html
}

// ── auth ──────────────────────────────────────────────────────────────────
function me(): ?array
{
    static $u = false;
    if ($u === false) $u = isset($_SESSION['uid']) ? (q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null) : null;
    return $u;
}
const ROLES = ['administrator' => 'Administrator: makes requests', 'admin' => 'Admin: approves', 'superadmin' => 'Super admin: views everything',
               'developer' => 'Developer: manages logins'];
// who may do what; every permission check in the app goes through can()/need()
const PERMS = [
    'view'       => ['superadmin', 'admin', 'administrator'], // payments, receipts, statement, files
    'request'    => ['administrator'],                        // create/edit payments & receipts, due dates
    'approve'    => ['admin'],
    'pay'        => ['admin', 'administrator'],
    'files'      => ['admin', 'administrator'],
    'chat'       => ['superadmin', 'admin', 'administrator'],
    'manage'     => ['admin'],                                // balance entries, undo paid, deletes
    'see_cash'   => ['superadmin', 'admin'],
    'users'      => ['developer'],
    'see_users'  => ['superadmin', 'developer'],
];
function can(string $perm): bool { return in_array(me()['role'] ?? '', PERMS[$perm], true); }
function need(string $perm): void { if (!can($perm)) fail('Your role does not allow this.', 403); }
function login(int|string $id): void
{
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int) $id];
}
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }

// ── input validation (fail() stops the request with a message) ───────────
function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    head('Error');
    echo '<p>' . h($msg) . '</p><p><a href="javascript:history.back()">← Go back</a></p>';
    foot();
}
function req(string $k): string
{
    $v = trim((string) ($_POST[$k] ?? ''));
    return $v !== '' ? $v : fail("Please fill in: $k");
}
function amount_in(string $v, bool $signed = false): string
{
    $v = str_replace([',', ' '], '', trim($v));
    $re = $signed ? '/^-?\d{1,12}(\.\d{1,2})?$/' : '/^\d{1,12}(\.\d{1,2})?$/';
    if (!preg_match($re, $v) || (float) $v == 0) fail('Enter a valid amount, e.g. 1500 or 1500.50');
    return $v; // kept as string so MySQL DECIMAL gets it exactly
}
function date_in(string $v): string
{
    return date_or_null($v) ?? fail('Enter a valid date');
}
function date_or_null(?string $v): ?string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}
function enum_in(?string $v, array $allowed): string
{
    return in_array($v, $allowed, true) ? $v : fail('Invalid choice');
}
function party_id(string $name, ?bool $gst = null, string $gstin = ''): int
{
    $gstin = strtoupper(trim($gstin));
    if ($gstin !== '' && !preg_match('/^[0-9A-Z]{15}$/', $gstin)) fail('GSTIN must be 15 letters/digits');
    $p = q('SELECT id FROM parties WHERE name = ?', [$name])->fetch();
    if (!$p) {
        q('INSERT INTO parties (name, gst_registered, gstin) VALUES (?, ?, ?)', [$name, (int) $gst, $gstin ?: null]);
        return (int) db()->lastInsertId();
    }
    if ($gst !== null) q('UPDATE parties SET gst_registered = ?, gstin = COALESCE(?, gstin) WHERE id = ?', [(int) $gst, $gstin ?: null, $p['id']]);
    return (int) $p['id'];
}

// ── output ────────────────────────────────────────────────────────────────
function h(mixed $s): string { return htmlspecialchars((string) $s, ENT_QUOTES); }
function go(string $url, ?string $flash = null): never
{
    if ($flash) $_SESSION['flash'] = $flash;
    header("Location: $url");
    exit;
}
function money(mixed $n): string // Indian grouping: ₹12,34,567.00
{
    [$i, $d] = explode('.', number_format(abs((float) $n), 2, '.', ''));
    if (strlen($i) > 3) $i = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($i, 0, -3)) . ',' . substr($i, -3);
    return ((float) $n < 0 ? '-' : '') . '₹' . $i . '.' . $d;
}
function badge(string $status): string
{
    $cls = ['Paid' => 'paid', 'Part Paid' => 'part', 'Unpaid' => 'unpaid'][$status] ?? $status;
    return '<span class="badge ' . h($cls) . '">' . h($status) . '</span>';
}
function paise(mixed $n): int { return (int) round((float) $n * 100); } // money math in whole paise, no float drift

// Totals + ageing + party-wise outstanding, same rules as the Excel tracker (rejected invoices left out).
function summarize(array $rows): array
{
    $t = array_fill_keys(['invoiced', 'paid', 'outstanding', 'overdue', ...array_keys(AGEING)], 0) + ['with_balance' => 0, 'fully_paid' => 0];
    $parties = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'rejected') continue;
        [$amt, $paid, $out, $od] = [paise($r['amount']), paise($r['paid']), paise($r['outstanding']), (int) $r['days_overdue']];
        $bucket = $od === 0 ? 'not_due' : ($od <= 30 ? 'd1_30' : ($od <= 60 ? 'd31_60' : ($od <= 90 ? 'd61_90' : 'd90')));
        $t['invoiced'] += $amt; $t['paid'] += $paid; $t['outstanding'] += $out; $t[$bucket] += $out;
        if ($od > 0) $t['overdue'] += $out;
        $t['with_balance'] += (int) ($out > 0);
        $t['fully_paid'] += (int) ($r['pay_status'] === 'Paid');
        $p = &$parties[$r['party']];
        $p ??= ['invoices' => 0, 'invoiced' => 0, 'paid' => 0, 'outstanding' => 0, 'overdue' => 0, 'oldest' => 0];
        $p['invoices']++; $p['invoiced'] += $amt; $p['paid'] += $paid; $p['outstanding'] += $out;
        if ($od > 0) $p['overdue'] += $out;
        $p['oldest'] = max($p['oldest'], $od);
        unset($p);
    }
    foreach ($t as $k => $v) if (!in_array($k, ['with_balance', 'fully_paid'], true)) $t[$k] = $v / 100;
    foreach ($parties as &$p) foreach (['invoiced', 'paid', 'outstanding', 'overdue'] as $k) $p[$k] /= 100;
    unset($p);
    uasort($parties, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
    return [$t, $parties];
}
function parties_datalist(): string
{
    $o = '';
    foreach (q('SELECT name FROM parties ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $n) $o .= '<option value="' . h($n) . '">';
    return "<datalist id=\"parties\">$o</datalist>";
}
function msg_html(array $m): string
{
    return '<div class="msg' . ($m['kind'] === 'followup' ? ' followup' : '') . '" data-id="' . (int) $m['id'] . '"><b>' . h($m['name']) . '</b> <small>'
        . h(date('d M, H:i', strtotime($m['created_at']))) . ($m['kind'] === 'followup' ? ' · follow-up' : '') . '</small><br>' . nl2br(h($m['body'])) . '</div>';
}
function csv(string $name, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$name-" . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    if ($rows) fputcsv($f, array_keys($rows[0]));
    foreach ($rows as $r) {
        // stop Excel treating text like "=SUM(..)" as a formula
        fputcsv($f, array_map(fn($v) => is_string($v) && !is_numeric($v) && preg_match('/^[=+\-@]/', $v) ? "'$v" : $v, $r));
    }
    exit;
}

function head(string $title): void
{
    $u = me();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Payments</title>
<link rel="stylesheet" href="pico.min.css">
<style>
nav ul{flex-wrap:wrap}
.badge{padding:.1rem .55rem;border-radius:1rem;font-size:.8rem;color:#111;white-space:nowrap}
.pending{background:#fde68a}.approved{background:#bfdbfe}.rejected{background:#fecaca}.paid{background:#bbf7d0}.part{background:#fed7aa}.unpaid{background:#e5e7eb}
.neg{color:#dc2626}.pos{color:#16a34a}.right{text-align:right}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
.cards article{margin:0}.big{font-size:clamp(1.3rem,2.4vw,1.8rem);font-weight:700;white-space:nowrap}
nav li{padding-block:.3rem}td:first-child{white-space:nowrap}
.filters{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end}.filters>*{flex:1 1 140px;margin:0}
#chat{max-height:55vh;overflow:auto;border:1px solid var(--pico-muted-border-color);padding:.75rem;border-radius:.5rem;margin-bottom:1rem}
.msg{margin-bottom:.7rem}.followup{border-left:3px solid #f59e0b;padding-left:.5rem}
.flash{background:#bbf7d0;color:#111;padding:.5rem 1rem;border-radius:.5rem}
form.inline{display:inline}form.inline button{padding:.2rem .6rem;margin:0;width:auto}
td form{margin:0}
table.striped td,table.striped th{white-space:nowrap}details{margin-top:1rem}
</style></head>
<body><header class="container"><nav>
<ul><li><strong>💰 Payments</strong></li></ul>
<?php if ($u): ?><ul>
<?php if (can('view')): ?>
<li><a href="?">Dashboard</a></li><li><a href="?p=payments">Invoices</a></li><li><a href="?p=outstanding">Outstanding</a></li><li><a href="?p=receivables">Receivables</a></li>
<li><a href="?p=statement">Statement</a></li><li><a href="?p=chat">Chat</a></li>
<?php endif ?>
<?php if (can('see_cash')): ?><li><a href="?p=cash">Balance entries</a></li><?php endif ?>
<?php if (can('see_users')): ?><li><a href="?p=users">Users</a></li><?php endif ?>
<li><a href="?p=logout">Logout (<?= h($u['name']) ?> · <?= h($u['role']) ?>)</a></li>
</ul><?php endif ?>
</nav></header>
<main class="container">
<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif ?>
<h2><?= h($title) ?></h2>
<?php
}

function foot(): never
{ ?>
</main>
<script>
// live chat: fetch new messages every 5 s
const box = document.getElementById('chat');
if (box) {
  box.scrollTop = box.scrollHeight;
  setInterval(async () => {
    const r = await fetch(`?p=msgs&payment=${box.dataset.payment}&after=${box.dataset.after}`);
    if (!r.ok || r.redirected) return;
    const html = (await r.text()).trim();
    if (!html) return;
    box.insertAdjacentHTML('beforeend', html);
    box.dataset.after = box.lastElementChild.dataset.id;
    box.scrollTop = box.scrollHeight;
  }, 5000);
}
</script>
</body></html>
<?php
    exit;
}
