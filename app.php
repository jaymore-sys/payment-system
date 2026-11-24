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
    // one-off name lists dropped on the server (kept out of the public repo): add them to the dropdowns, then delete the file
    $seed = __DIR__ . '/seed-names.php';
    if (is_file($seed)) {
        $names = require $seed;
        foreach (['parties' => $names['parties'] ?? [], 'products' => $names['products'] ?? []] as $table => $list) {
            $st = $db->prepare("INSERT IGNORE INTO $table (name) VALUES (?)");
            foreach ($list as $n) if (($n = trim(preg_replace('/\s+/', ' ', (string) $n))) !== '') $st->execute([mb_substr($n, 0, 190)]);
        }
        @unlink($seed);
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
    4 => [ // product list, "send request to" approver, approval for receipts and balance entries, imported payouts
        "DROP VIEW IF EXISTS requests, upcoming_dues, current_balance, statement, payment_register",
        "CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE,
           created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT IGNORE INTO products (name) SELECT DISTINCT LEFT(TRIM(products), 190) FROM payments WHERE TRIM(products) <> ''",
        "ALTER TABLE payments ADD COLUMN approver_id INT AFTER status, ADD FOREIGN KEY (approver_id) REFERENCES users(id)",
        "ALTER TABLE payouts ADD COLUMN in_balance BOOLEAN NOT NULL DEFAULT TRUE AFTER note",
        "ALTER TABLE receivables ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER note,
           ADD COLUMN approver_id INT AFTER status, ADD COLUMN reject_reason TEXT AFTER approver_id, ADD COLUMN reviewed_by INT AFTER reject_reason,
           ADD FOREIGN KEY (approver_id) REFERENCES users(id), ADD FOREIGN KEY (reviewed_by) REFERENCES users(id),
           ADD CONSTRAINT receipt_reject_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> '')",
        "UPDATE receivables SET status = 'approved'", // existing receipts already counted in the balance
        "ALTER TABLE cash_entries ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER note,
           ADD COLUMN approver_id INT AFTER status, ADD COLUMN reject_reason TEXT AFTER approver_id, ADD COLUMN reviewed_by INT AFTER reject_reason,
           ADD FOREIGN KEY (approver_id) REFERENCES users(id), ADD FOREIGN KEY (reviewed_by) REFERENCES users(id),
           ADD CONSTRAINT cash_reject_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> '')",
        "UPDATE cash_entries SET status = 'approved'",
    ],
    5 => [ // balance entries no longer need approval: administrator and admin add them directly
        "UPDATE cash_entries SET status = 'approved' WHERE status = 'pending'",
        "ALTER TABLE cash_entries ALTER status SET DEFAULT 'approved'",
    ],
    6 => [], // requests view gained party / ref / due date / file count columns (views are rebuilt after any migration)
    7 => [ // a request can go to several admins; all must approve
        "CREATE TABLE request_approvers (type ENUM('invoice','receipt') NOT NULL, request_id INT NOT NULL, user_id INT NOT NULL,
           decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', reason TEXT, decided_at TIMESTAMP NULL,
           PRIMARY KEY (type, request_id, user_id), FOREIGN KEY (user_id) REFERENCES users(id), INDEX (user_id, decision)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT INTO request_approvers (type, request_id, user_id, decision, reason, decided_at)
           SELECT 'invoice', id, approver_id, status, reject_reason, IF(status = 'pending', NULL, NOW()) FROM payments WHERE approver_id IS NOT NULL",
        "INSERT INTO request_approvers (type, request_id, user_id, decision, reason, decided_at)
           SELECT 'receipt', id, approver_id, status, reject_reason, IF(status = 'pending', NULL, NOW()) FROM receivables WHERE approver_id IS NOT NULL",
    ],
    8 => [ // everyone votes (a reject no longer ends it early) + history log + send again
        "CREATE TABLE request_log (id INT AUTO_INCREMENT PRIMARY KEY, type ENUM('invoice','receipt') NOT NULL, request_id INT NOT NULL, user_id INT NOT NULL,
           action ENUM('sent','resent','approved','rejected') NOT NULL, note TEXT, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
           FOREIGN KEY (user_id) REFERENCES users(id), INDEX (type, request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // history so far: who sent each request, then each vote already cast
        "INSERT INTO request_log (type, request_id, user_id, action, created_at)
           SELECT 'invoice', pm.id, pm.created_by, 'sent', pm.created_at FROM payments pm WHERE EXISTS (SELECT 1 FROM request_approvers ra WHERE ra.type = 'invoice' AND ra.request_id = pm.id)",
        "INSERT INTO request_log (type, request_id, user_id, action, created_at)
           SELECT 'receipt', r.id, r.created_by, 'sent', r.created_at FROM receivables r WHERE EXISTS (SELECT 1 FROM request_approvers ra WHERE ra.type = 'receipt' AND ra.request_id = r.id)",
        "INSERT INTO request_log (type, request_id, user_id, action, note, created_at)
           SELECT type, request_id, user_id, decision, reason, COALESCE(decided_at, NOW()) FROM request_approvers WHERE decision <> 'pending'",
        // requests rejected early while other admins had not voted yet: reopen them for those admins
        "UPDATE payments pm SET pm.status = 'pending' WHERE pm.status = 'rejected' AND EXISTS (SELECT 1 FROM request_approvers ra JOIN users u ON u.id = ra.user_id
           WHERE ra.type = 'invoice' AND ra.request_id = pm.id AND ra.decision = 'pending' AND u.role = 'admin')",
        "UPDATE receivables r SET r.status = 'pending' WHERE r.status = 'rejected' AND EXISTS (SELECT 1 FROM request_approvers ra JOIN users u ON u.id = ra.user_id
           WHERE ra.type = 'receipt' AND ra.request_id = r.id AND ra.decision = 'pending' AND u.role = 'admin')",
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
    'view'       => ['superadmin', 'admin', 'administrator'], // invoices, receipts, party outstanding, files
    'request'    => ['administrator'],                        // create/edit invoices and receipts
    'approve'    => ['admin'],                                // yes / no on requests sent to them
    'pay'        => ['administrator'],                        // record payments against approved invoices
    'files'      => ['administrator'],
    'chat'       => ['superadmin', 'admin', 'administrator'],
    'manage'     => ['admin'],                                // corrections: remove a payment, delete an invoice/receipt
    'balance'    => ['administrator'],                        // add / delete balance entries directly, no approval
    'see_cash'   => ['superadmin', 'administrator'],
    'statement'  => ['superadmin', 'administrator'],
    'import'     => ['administrator'],
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
    echo '<div class="error-box">⚠️ ' . h($msg) . '</div><p><a href="javascript:history.back()">← Go back</a></p>';
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
// <select> from a master list (parties / products) with an "Add new…" choice that shows a text box (JS in foot())
function pick(string $name, string $table, ?string $current, string $label): string
{
    $names = q("SELECT name FROM $table ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    if ((string) $current !== '' && !in_array($current, $names, true)) $names[] = $current;
    $o = '<option value="">— Select ' . h(strtolower($label)) . ' —</option>';
    foreach ($names as $n) $o .= '<option value="' . h($n) . '"' . ($n === $current ? ' selected' : '') . '>' . h($n) . '</option>';
    $o .= '<option value="__new">➕ Add new ' . h(strtolower($label)) . '…</option>';
    return "<label>$label<select name=\"$name\" data-addnew required>$o</select>"
        . "<input name=\"{$name}_new\" placeholder=\"Type the new " . h(strtolower($label)) . ' name" maxlength="190" hidden></label>';
}
function picked(string $name): string
{
    $v = trim((string) ($_POST[$name] ?? ''));
    if ($v === '__new') $v = trim((string) ($_POST[$name . '_new'] ?? ''));
    return $v !== '' ? mb_substr(preg_replace('/\s+/', ' ', $v), 0, 190) : fail("Please choose or add a $name");
}
function product_name(string $n): string
{
    q('INSERT IGNORE INTO products (name) VALUES (?)', [$n]);
    return $n;
}

// "Send request to": one or more admins, ticked. ALL of them must approve; one reject rejects.
function approver_select(array $current = []): string
{
    $admins = q("SELECT id, name FROM users WHERE role = 'admin' ORDER BY name")->fetchAll();
    if (!$admins) return '<p class="neg">No Admin login exists yet. Ask the Developer to create one.</p>';
    if (!$current && count($admins) === 1) $current = [(int) $admins[0]['id']];
    $o = '';
    foreach ($admins as $u) {
        $o .= '<label class="chip"><input type="checkbox" name="approvers[]" value="' . $u['id'] . '"' . (in_array((int) $u['id'], $current, true) ? ' checked' : '') . '> ' . h($u['name']) . '</label>';
    }
    return "<fieldset class=\"approvers\"><legend>📨 Send request to <small>(all ticked admins must approve)</small></legend>$o</fieldset>";
}
function approver_in(): array
{
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['approvers'] ?? []))));
    if (!$ids) fail('Tick at least one admin to send the request to');
    $ok = q('SELECT id FROM users WHERE role = \'admin\' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll(PDO::FETCH_COLUMN);
    if (count($ok) !== count($ids)) fail('One of the chosen people is not an admin');
    return $ids;
}
// (re)send a request: everyone starts at "pending"
function set_approvers(string $type, int $id, array $ids): void
{
    q('DELETE FROM request_approvers WHERE type = ? AND request_id = ?', [$type, $id]);
    foreach ($ids as $u) q('INSERT INTO request_approvers (type, request_id, user_id) VALUES (?, ?, ?)', [$type, $id, $u]);
}
function approvers_of(string $type, int $id): array
{
    return array_map('intval', q('SELECT user_id FROM request_approvers WHERE type = ? AND request_id = ?', [$type, $id])->fetchAll(PDO::FETCH_COLUMN));
}
// "Asha ✓ · Sumit ⏳" from the view's "Asha:approved|Sumit:pending"
function approvers_html(?string $list): string
{
    if (!$list) return 'any admin';
    $icon = ['approved' => '<b class="pos">✓</b>', 'rejected' => '<b class="neg">✕</b>', 'pending' => '⏳'];
    return implode(' · ', array_map(function ($p) use ($icon) {
        [$name, $d] = explode(':', $p, 2) + [1 => 'pending'];
        return h($name) . ' ' . ($icon[$d] ?? '');
    }, explode('|', $list)));
}
const REVIEWABLE = ['invoice' => 'payments', 'receipt' => 'receivables'];
// mine = I was asked and haven't answered yet, or none of the people asked is an admin any more (role changed)
const MINE_SQL = "(EXISTS (SELECT 1 FROM request_approvers ra WHERE ra.type = r.type AND ra.request_id = r.id AND ra.user_id = ? AND ra.decision = 'pending')
    OR NOT EXISTS (SELECT 1 FROM request_approvers ra JOIN users au ON au.id = ra.user_id WHERE ra.type = r.type AND ra.request_id = r.id AND au.role = 'admin'))";
function can_decide(string $type, int $id): bool
{
    return can('approve') && (bool) q("SELECT 1 FROM requests r WHERE r.type = ? AND r.id = ? AND r.status = 'pending' AND " . MINE_SQL, [$type, $id, me()['id']])->fetch();
}
function log_request(string $type, int $id, string $action, ?string $note = null): void
{
    q('INSERT INTO request_log (type, request_id, user_id, action, note) VALUES (?, ?, ?, ?, ?)', [$type, $id, me()['id'], $action, $note]);
}
function names_of(array $ids): string
{
    return $ids ? implode(', ', q('SELECT name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', $ids)->fetchAll(PDO::FETCH_COLUMN)) : '';
}
// everyone asked votes; when nobody (still an admin) is waiting: all yes = approved, any no = rejected
function review(string $type, int $id, bool $approve, string $reason = ''): string
{
    need('approve');
    $table = REVIEWABLE[$type] ?? fail('Unknown request type');
    if (!$approve && $reason === '') fail('Please give a reason for rejecting');
    $uid = (int) me()['id'];
    db()->beginTransaction(); // lock the request so admins voting at the same moment are counted correctly
    $r = q("SELECT status FROM $table WHERE id = ? FOR UPDATE", [$id])->fetch() ?: fail('Request not found', 404);
    if ($r['status'] !== 'pending') fail("This request is already {$r['status']}.");
    if (!can_decide($type, $id)) fail(in_array($uid, approvers_of($type, $id), true) ? 'You have already voted on this request.' : 'This request was sent to other admins.', 403);
    if (!$approve && $type === 'invoice' && q('SELECT 1 FROM payouts WHERE payment_id = ?', [$id])->fetch()) fail('Payments were already made against this invoice, so it cannot be rejected.');
    q("INSERT INTO request_approvers (type, request_id, user_id, decision, reason, decided_at) VALUES (?, ?, ?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE decision = VALUES(decision), reason = VALUES(reason), decided_at = NOW()", [$type, $id, $uid, $approve ? 'approved' : 'rejected', $approve ? null : $reason]);
    log_request($type, $id, $approve ? 'approved' : 'rejected', $approve ? null : $reason);
    $waiting = q("SELECT u.name FROM request_approvers ra JOIN users u ON u.id = ra.user_id
                  WHERE ra.type = ? AND ra.request_id = ? AND ra.decision = 'pending' AND u.role = 'admin' ORDER BY u.name", [$type, $id])->fetchAll(PDO::FETCH_COLUMN);
    if ($waiting) {
        db()->commit();
        return 'Your vote is saved. Still waiting for: ' . implode(', ', $waiting);
    }
    $no = q("SELECT u.name, ra.reason FROM request_approvers ra JOIN users u ON u.id = ra.user_id
             WHERE ra.type = ? AND ra.request_id = ? AND ra.decision = 'rejected' ORDER BY u.name", [$type, $id])->fetchAll();
    if ($no) {
        q("UPDATE $table SET status = 'rejected', reviewed_by = ?, reject_reason = ? WHERE id = ?",
          [$uid, implode('; ', array_map(fn($v) => "{$v['name']}: {$v['reason']}", $no)), $id]);
    } else {
        q("UPDATE $table SET status = 'approved', reviewed_by = ?, reject_reason = NULL WHERE id = ?", [$uid, $id]);
    }
    $n = count(approvers_of($type, $id));
    db()->commit();
    return ($no ? 'Rejected' : 'Approved') . ($n > 1 ? " — all $n votes are in" . ($no ? ' (no from ' . implode(', ', array_column($no, 'name')) . ')' : '') : '');
}
// send a request (again): everyone asked starts at "waiting", history keeps the old votes
function send_request(string $type, int $id, array $approvers, bool $again, string $note = ''): void
{
    set_approvers($type, $id, $approvers);
    if ($again) q('UPDATE ' . REVIEWABLE[$type] . " SET status = 'pending', reviewed_by = NULL, reject_reason = NULL, approver_id = ? WHERE id = ?", [$approvers[0], $id]);
    log_request($type, $id, $again ? 'resent' : 'sent', 'to ' . names_of($approvers) . ($note !== '' ? " — $note" : ''));
}
// votes for many requests at once: ["invoice:12" => [[name, decision, reason, decided_at], ...]]
function votes_for(array $rows): array
{
    $out = [];
    foreach (['invoice', 'receipt'] as $type) {
        $ids = array_map(fn($r) => (int) $r['id'], array_filter($rows, fn($r) => $r['type'] === $type));
        if (!$ids) continue;
        $sql = 'SELECT ra.request_id, u.name, ra.decision, ra.reason, ra.decided_at FROM request_approvers ra JOIN users u ON u.id = ra.user_id
                WHERE ra.type = ? AND ra.request_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY FIELD(ra.decision, 'rejected', 'pending', 'approved'), u.name";
        foreach (q($sql, [$type, ...$ids])->fetchAll() as $v) $out["$type:{$v['request_id']}"][] = $v;
    }
    return $out;
}
// one line per admin: ✓ approved / ⏳ waiting / ✕ rejected + reason
function votes_html(array $votes, bool $times = false): string
{
    if (!$votes) return '';
    $n = count($votes);
    $yes = count(array_filter($votes, fn($v) => $v['decision'] === 'approved'));
    $no = count(array_filter($votes, fn($v) => $v['decision'] === 'rejected'));
    $o = '<div class="votes"><div class="votes-sum">🗳 ' . ($yes + $no) . " of $n voted" . ($yes ? " · <span class=\"pos\">$yes yes</span>" : '') . ($no ? " · <span class=\"neg\">$no no</span>" : '') . '</div><ul>';
    foreach ($votes as $v) {
        [$icon, $text] = match ($v['decision']) { 'approved' => ['✓', 'approved'], 'rejected' => ['✕', 'rejected'], default => ['⏳', 'waiting'] };
        $when = $times && $v['decided_at'] ? ' <small>' . h(date('d M, H:i', strtotime($v['decided_at']))) . '</small>' : '';
        $o .= "<li class=\"v-{$v['decision']}\"><span class=\"vi\">$icon</span><div><b>" . h($v['name']) . "</b> <span class=\"vt\">$text</span>$when"
            . ($v['decision'] === 'rejected' && $v['reason'] !== null ? '<div class="why">“' . h($v['reason']) . '”</div>' : '') . '</div></li>';
    }
    return $o . '</ul></div>';
}
function history_html(string $type, int $id): string
{
    $rows = q('SELECT l.*, u.name FROM request_log l JOIN users u ON u.id = l.user_id WHERE l.type = ? AND l.request_id = ? ORDER BY l.id DESC', [$type, $id])->fetchAll();
    if (!$rows) return '';
    $label = ['sent' => '📨 sent for approval', 'resent' => '🔁 sent again', 'approved' => '✓ approved', 'rejected' => '✕ rejected'];
    $o = '<ol class="history">';
    foreach ($rows as $l) {
        $o .= '<li class="h-' . $l['action'] . '"><b>' . h($l['name']) . '</b> ' . $label[$l['action']] . ' <small>' . h(date('d M Y, H:i', strtotime($l['created_at']))) . '</small>'
            . ($l['note'] ? '<div class="why">' . h($l['note']) . '</div>' : '') . '</li>';
    }
    return $o . '</ol>';
}
function pending_for_me(): int
{
    return can('approve') ? (int) q("SELECT COUNT(*) FROM requests r WHERE r.status = 'pending' AND " . MINE_SQL, [me()['id']])->fetchColumn() : 0;
}
const REQUESTS_SQL = 'SELECT r.*, u.name AS by_name FROM requests r JOIN users u ON u.id = r.created_by';
function requests_for_me(): array
{
    return q(REQUESTS_SQL . " WHERE r.status = 'pending' AND " . MINE_SQL . " ORDER BY r.created_at", [me()['id']])->fetchAll();
}
// one card per request: who / what / how much, everyone's vote, amount + decision
function requests_list(array $rows, callable $date, bool $actions = true): void
{
    $votes = votes_for($rows); ?>
    <div class="reqs">
    <?php foreach ($rows as $r):
        $link = $r['type'] === 'invoice' ? "?p=payment&id={$r['id']}" : '?p=receivables';
        $days = $r['due_date'] ? (int) round((strtotime($r['due_date']) - strtotime('today')) / 86400) : null; ?>
      <article class="req <?= $r['type'] ?> s-<?= $r['status'] ?>">
        <div class="req-main">
          <div class="req-top"><span class="badge type-<?= $r['type'] ?>"><?= $r['type'] ?></span><?= $r['status'] !== 'pending' ? badge($r['status']) : '' ?></div>
          <a class="req-title" href="<?= $link ?>"><?= h($r['party']) ?></a>
          <div class="req-line"><strong><?= h($r['ref']) ?></strong><?= $r['item'] !== '' ? ' · ' . h($r['item']) : '' ?><?= $r['bill_type'] ? ' · ' . strtoupper($r['bill_type']) . ' bill' : '' ?></div>
          <div class="req-meta">
            <span>👤 <?= h($r['by_name']) ?> · <?= $date($r['created_at']) ?></span>
            <?php if ($days !== null): ?><span class="<?= $days < 0 ? 'neg' : '' ?>">⏰ <?= $days < 0 ? abs($days) . ' days overdue' : ($days === 0 ? 'due today' : 'due ' . $date($r['due_date'])) ?></span><?php endif ?>
            <?php if ($r['files']): ?><a href="<?= $link ?>">📎 <?= (int) $r['files'] ?> file<?= $r['files'] > 1 ? 's' : '' ?></a><?php endif ?>
          </div>
          <?php $v = $votes["{$r['type']}:{$r['id']}"] ?? []; // one admin, still waiting: nothing new to show
          if (count($v) > 1 || $r['status'] !== 'pending') echo votes_html($v) ?>
        </div>
        <div class="req-side">
          <div class="req-amt"><?= money($r['amount']) ?></div>
          <?php if (!$actions): // no buttons (e.g. on the send-again page itself)
                elseif (can_decide($r['type'], (int) $r['id'])): echo review_buttons($r['type'], (int) $r['id'], $r['status']);
                elseif ($r['status'] === 'rejected' && can('request')): ?>
            <a role="button" class="resend" href="?p=resend&type=<?= $r['type'] ?>&id=<?= (int) $r['id'] ?>">🔁 Send again</a>
          <?php endif ?>
        </div>
      </article>
    <?php endforeach ?>
    </div>
<?php
}
// Approve = one tap; Reject first shows a reason box (JS in foot()), then "Confirm reject"
function review_buttons(string $type, int $id, string $status): string
{
    $f = fn($a, $inner, $cls = '') => "<form method=\"post\" action=\"?p=approvals\" class=\"inline $cls\"" . ($cls ? ' hidden' : '') . '>' . csrf_field()
        . "<input type=\"hidden\" name=\"type\" value=\"$type\"><input type=\"hidden\" name=\"id\" value=\"$id\"><input type=\"hidden\" name=\"a\" value=\"$a\">$inner</form>";
    return '<div class="review">'
        . ($status !== 'approved' ? $f('approve', '<button class="ok">✓ Approve</button>') : '')
        . ($status !== 'rejected' ? '<button type="button" class="no" data-reject>✕ Reject</button>'
            . $f('reject', '<input name="reason" placeholder="Reason for rejecting" required><button class="no">Confirm reject</button>', 'rej-form') : '')
        . '</div>';
}

function msg_html(array $m): string
{
    $mine = (int) $m['user_id'] === (int) (me()['id'] ?? 0);
    return '<div class="msg' . ($mine ? ' mine' : '') . ($m['kind'] === 'followup' ? ' followup' : '') . '" data-id="' . (int) $m['id'] . '"><b>' . h($m['name']) . '</b> <small>'
        . h(date('d M, H:i', strtotime($m['created_at']))) . ($m['kind'] === 'followup' ? ' · 📌 follow-up' : '') . '</small><div>' . nl2br(h($m['body'])) . '</div></div>';
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

function head(string $title, string $actions = ''): void
{
    $u = me();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $cur = $_GET['p'] ?? 'home';
    $nav = [['home', '📊', 'Dashboard', 'view'], ['approvals', '✅', 'Approvals', 'view'], ['payments', '🧾', 'Invoices', 'view'],
            ['outstanding', '📌', 'Party outstanding', 'view'], ['receivables', '💰', 'Receipts', 'view'], ['cash', '🏦', 'Balance', 'see_cash'],
            ['statement', '📒', 'Statement', 'statement'], ['chat', '💬', 'Team chat', 'chat'], ['import', '📥', 'Import Excel', 'import'],
            ['users', '👥', 'Users', 'see_users']];
    $count = $u ? pending_for_me() : 0; ?>
<!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Valencia Payment Desk</title>
<link rel="icon" href="icon-192.png">
<link rel="manifest" href="manifest.json"><link rel="apple-touch-icon" href="icon-180.png">
<meta name="theme-color" content="#ff5733"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Payment Desk">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="pico.min.css">
<link rel="stylesheet" href="style.css?v=24">
</head>
<body class="<?= $u ? 'app' : 'auth' ?>">
<?php if ($u): ?>
<input type="checkbox" id="nav-toggle" hidden>
<aside class="side">
  <a class="brand" href="?"><img src="vnl-logo.webp" alt="Valencia Nutrition"><small>Payment Desk</small></a>
  <nav>
  <?php foreach ($nav as [$p, $icon, $label, $perm]): if (!can($perm)) continue; ?>
    <a href="?p=<?= $p ?>" class="<?= $p === $cur ? 'active' : '' ?>"><span class="ico"><?= $icon ?></span><?= $label ?>
      <?php if ($p === 'approvals' && $count): ?><b class="count"><?= $count ?></b><?php endif ?></a>
  <?php endforeach ?>
  </nav>
  <div class="me"><span class="avatar"><?= h(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
    <div><strong><?= h($u['name']) ?></strong><small><?= h(ucfirst($u['role'])) ?></small></div>
  </div>
  <a class="logout" href="?p=logout">⏻ Logout</a>
</aside>
<header class="topbar"><label for="nav-toggle" class="burger" aria-label="Menu">☰</label><img src="vnl-logo.webp" alt="Valencia Nutrition"><strong>Payment Desk</strong>
  <?php if ($count): ?><a class="count" href="?p=approvals"><?= $count ?></a><?php endif ?></header>
<label for="nav-toggle" class="scrim"></label>
<main class="content">
<?php else: ?>
<main class="auth-box"><div class="brand"><img src="vnl-logo.webp" alt="Valencia Nutrition"><small>Payment Desk</small></div>
<?php endif ?>
<?php if ($flash): ?><div class="flash">✅ <?= h($flash) ?></div><?php endif ?>
<div class="page-head"><h1><?= h($title) ?></h1><?php if ($actions): ?><div class="actions"><?= $actions ?></div><?php endif ?></div>
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
// phones: tables turn into cards (CSS); give each cell its column name as a label
document.querySelectorAll('.tbl table').forEach(t => {
  const heads = [...t.querySelectorAll('thead th')].map(th => th.textContent.trim());
  t.querySelectorAll('tbody tr, tfoot tr').forEach(tr => {
    let col = 0;
    [...tr.cells].forEach(c => { if (col > 0 && heads[col] && !c.dataset.label) c.dataset.label = heads[col]; col += c.colSpan; });
  });
});
// Reject: show the reason box (button turns into Cancel)
document.querySelectorAll('[data-reject]').forEach(btn => btn.addEventListener('click', () => {
  const form = btn.nextElementSibling, open = form.hidden;
  form.hidden = !open; btn.textContent = open ? 'Cancel' : '✕ Reject';
  if (open) form.querySelector('input[name=reason]').focus();
}));
// "Add new…" in party/product dropdowns shows a text box
document.querySelectorAll('select[data-addnew]').forEach(s => {
  const box = s.nextElementSibling;
  const sync = () => { const on = s.value === '__new'; box.hidden = !on; box.required = on; if (on) box.focus(); };
  s.addEventListener('change', sync); sync();
});
</script>
</body></html>
<?php
    exit;
}
