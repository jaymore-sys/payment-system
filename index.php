<?php
require __DIR__ . '/app.php';
require __DIR__ . '/xlsx.php';

$p    = $_GET['p'] ?? 'home';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$a    = $_POST['a'] ?? '';

$has_users = (bool) q('SELECT 1 FROM users LIMIT 1')->fetch();
if (!$has_users && $p !== 'setup') go('?p=setup');
if ($has_users && !me() && $p !== 'login') {
    if ($p === 'msgs') { http_response_code(401); exit; }
    go('?p=login');
}
if ($post && !hash_equals(csrf(), $_POST['csrf'] ?? '')) fail('Your session expired. Go back, refresh the page and try again.');
// developer only manages logins: send them to Users instead of the money pages
if (me() && !can('view') && in_array($p, ['home', 'approvals', 'payments', 'outstanding', 'payment', 'file', 'msgs', 'chat', 'receivables', 'statement'], true)) {
    if ($p === 'msgs') { http_response_code(403); exit; }
    go(can('upload') ? '?p=uploads' : '?p=users'); // 'user' logins only send invoice photos
}
$date = fn(?string $d) => $d ? h(date('d M Y', strtotime($d))) : '';
$sel  = fn(string $k, string $v) => ($_GET[$k] ?? '') === $v ? 'selected' : '';

switch ($p) {

// ── first run: create the developer, who then creates everyone else ──────
case 'setup':
    if ($has_users) go('?p=login');
    if ($post) {
        $email = filter_var(req('email'), FILTER_VALIDATE_EMAIL) ?: fail('Invalid email');
        if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
        q("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'developer')",
          [req('name'), $email, password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        login(db()->lastInsertId());
        go('?p=users', 'Developer account created. Now add the Super admin, Admin and Administrator logins.');
    }
    head('Create developer account'); ?>
    <p>This first account is the <strong>Developer</strong>, who creates the logins for the Super admin, Admin and Administrator.</p>
    <form method="post"><?= csrf_field() ?>
      <label>Your name<input name="name" required></label>
      <label>Email<input name="email" type="email" required></label>
      <label>Password<input name="password" type="password" placeholder="At least 8 characters" minlength="8" required></label>
      <button>Create developer account</button>
    </form>
    <?php foot();

case 'login':
    $err = '';
    if ($post) {
        $u = q('SELECT * FROM users WHERE email = ?', [trim($_POST['email'] ?? '')])->fetch();
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) { login($u['id']); go('?'); }
        $err = 'Wrong email or password';
    }
    head('Sign in'); ?>
    <form method="post"><?= csrf_field() ?>
      <?php if ($err): ?><div class="error-box"><?= h($err) ?></div><br><?php endif ?>
      <label>Email<input name="email" type="email" required autofocus></label>
      <label>Password<input name="password" type="password" required></label>
      <button>Sign in</button>
    </form>
    <?php foot();

case 'logout':
    session_destroy();
    go('?p=login');

// ── dashboard: admin sees alerts + approvals, everyone else the money overview ─
case 'home':
    $dues = q('SELECT * FROM upcoming_dues ORDER BY due_date')->fetchAll();
    [$t] = summarize(q('SELECT * FROM payment_register')->fetchAll());
    $mine = pending_for_me();
    $popup = ($dues || $mine) && empty($_SESSION['dues_seen']);
    if ($popup) $_SESSION['dues_seen'] = true;
    $due_table = function (int $limit = 10, bool $approval = true) use ($dues, $date) { ?>
      <div class="tbl"><table><thead><tr><th>Party</th><th>Invoice</th><th class="right">Outstanding</th><th>Due</th><?= $approval ? '<th>Approval</th>' : '' ?></tr></thead><tbody>
      <?php foreach (array_slice($dues, 0, $limit) as $d): ?>
        <tr><td class="clip"><a href="?p=payment&id=<?= $d['id'] ?>" title="<?= h($d['party']) ?>"><?= h($d['party']) ?></a></td><td><?= h($d['invoice_no'] ?: $d['products']) ?></td>
          <td class="right"><strong><?= money($d['outstanding']) ?></strong></td>
          <td><?= $date($d['due_date']) ?><div class="sub <?= $d['days_left'] < 0 ? 'neg' : '' ?>">
            <?= $d['days_left'] < 0 ? abs($d['days_left']) . ' days overdue' : ($d['days_left'] == 0 ? 'due today' : "due in {$d['days_left']} days") ?></div></td>
          <?= $approval ? '<td>' . badge($d['status']) . '</td>' : '' ?></tr>
      <?php endforeach ?></tbody></table></div>
      <?php if (count($dues) > $limit): ?><p><a href="?p=payments&age=overdue">View all <?= count($dues) ?> overdue / due-soon invoices →</a></p><?php endif ?>
    <?php };
    $ageing = function (bool $header = true) use ($t) { ?>
      <article class="<?= $header ? '' : 'flush' ?>"><?= $header ? '<header>⏳ Outstanding ageing</header>' : '' ?><table class="kv">
        <?php $colors = ['not_due' => '#10b981', 'd1_30' => '#f59e0b', 'd31_60' => '#f97316', 'd61_90' => '#ef4444', 'd90' => '#b91c1c'];
        foreach (AGEING as $k => $label): $pct = $t['outstanding'] > 0 ? round($t[$k] / $t['outstanding'] * 100) : 0; ?>
          <tr><td><a href="?p=payments&age=<?= $k ?>"><?= h($label) ?></a><div class="bar" style="--c:<?= $colors[$k] ?>"><i style="width:<?= $pct ?>%"></i></div></td>
            <td class="right"><?= money($t[$k]) ?><br><small><?= $pct ?>%</small></td></tr>
        <?php endforeach ?>
      </table></article>
    <?php };

    if (can('approve')):
        $pending = requests_for_me();
        $pend_amt = array_sum(array_map(fn($r) => paise($r['amount']), $pending)) / 100;
        $overdue = array_filter($dues, fn($d) => $d['days_left'] < 0);
        $soon = array_filter($dues, fn($d) => $d['days_left'] >= 0);
        $sum = fn($rows) => array_sum(array_map(fn($d) => paise($d['outstanding']), $rows)) / 100;
        head('Dashboard'); ?>
        <div class="stats">
          <article class="stat green" data-icon="✅"><small>Waiting for your approval</small>
            <div class="big"><a href="?p=approvals"><?= count($pending) ?></a></div><div class="sub"><?= money($pend_amt) ?></div></article>
          <article class="stat red" data-icon="⏰"><small>Overdue invoices</small>
            <div class="big neg"><a href="?p=payments&age=overdue"><?= count($overdue) ?></a></div><div class="sub neg"><?= money($sum($overdue)) ?> outstanding</div></article>
          <article class="stat" data-icon="📅"><small>Due in next 7 days</small>
            <div class="big"><?= count($soon) ?></div><div class="sub"><?= money($sum($soon)) ?> outstanding</div></article>
          <article class="stat" data-icon="📌"><small>Total outstanding</small>
            <div class="big"><a href="?p=outstanding"><?= money($t['outstanding']) ?></a></div><div class="sub"><?= $t['with_balance'] ?> invoices</div></article>
        </div>
        <h3>✅ Waiting for your approval</h3>
        <?php if ($pending): requests_list(array_slice($pending, 0, 10), $date);
            if (count($pending) > 10): ?><p><a href="?p=approvals">Open all <?= count($pending) ?> requests →</a></p><?php endif;
        else: ?><div class="panel empty">All caught up — no requests waiting for you. 🎉</div><?php endif ?>
        <div class="two split">
          <div><h3>⏰ Overdue &amp; due in 7 days</h3><?php $dues ? $due_table(8, false) : print('<div class="panel empty">Nothing due. 🎉</div>') ?></div>
          <div><h3>⏳ Outstanding ageing</h3><?php $ageing(false) ?></div>
        </div>
    <?php else:
        $bal = q('SELECT balance FROM current_balance')->fetchColumn();
        $mon = q("SELECT (SELECT COALESCE(SUM(amount), 0) FROM receivables WHERE status = 'approved' AND received_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS received,
                         (SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE in_balance AND paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid")->fetch();
        $open_req = can('request') ? (int) q("SELECT COUNT(*) FROM requests WHERE created_by = ? AND status = 'pending'", [me()['id']])->fetchColumn()
                                   : (int) q("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();
        $acts = can('request') ? '<a href="?p=payment" role="button">＋ New invoice</a><a href="?p=receivables#add" role="button" class="secondary">＋ Receipt</a>' : '';
        head('Dashboard', $acts);
        if (can('inbox') && ($n = (int) q('SELECT COUNT(*) FROM inbox WHERE closed_at IS NULL')->fetchColumn())): ?>
          <a class="alert-link" href="?p=inbox">📨 <strong><?= $n ?> new invoice photo<?= $n > 1 ? 's' : '' ?></strong> sent in — make the invoice<?= $n > 1 ? 's' : '' ?> →</a>
        <?php endif ?>
        <div class="stats">
          <article class="stat" data-icon="🏦"><small>Current balance</small><div class="big <?= $bal < 0 ? 'neg' : '' ?>"><?= money($bal) ?></div></article>
          <article class="stat" data-icon="📌"><small>Total outstanding</small><div class="big"><a href="?p=outstanding"><?= money($t['outstanding']) ?></a></div></article>
          <article class="stat red" data-icon="⏰"><small>Overdue outstanding</small><div class="big neg"><a href="?p=payments&age=overdue"><?= money($t['overdue']) ?></a></div></article>
          <article class="stat green" data-icon="📨"><small><?= can('request') ? 'My pending requests' : 'Pending approvals' ?></small>
            <div class="big"><a href="?p=approvals"><?= $open_req ?></a></div></article>
        </div>
        <div class="two">
          <article><header>🧾 Payables</header><table class="kv">
            <tr><td>Total invoiced</td><td class="right"><?= money($t['invoiced']) ?></td></tr>
            <tr><td>Total paid</td><td class="right pos"><?= money($t['paid']) ?></td></tr>
            <tr><td>Total outstanding</td><td class="right"><strong><?= money($t['outstanding']) ?></strong></td></tr>
            <tr><td>Invoices with balance</td><td class="right"><?= $t['with_balance'] ?></td></tr>
            <tr><td>Fully paid invoices</td><td class="right"><?= $t['fully_paid'] ?></td></tr>
            <?php [$rc, $ra] = q('SELECT COUNT(*), COALESCE(SUM(payable), 0) FROM payment_register WHERE payable > 0')->fetch(PDO::FETCH_NUM); ?>
            <tr><td><a href="?p=payments&pay=ready">💸 Approved, ready to pay (<?= (int) $rc ?>)</a></td><td class="right"><strong><?= money($ra) ?></strong></td></tr>
            <tr><td>Paid this month</td><td class="right neg"><?= money($mon['paid']) ?></td></tr>
            <tr><td>Received this month</td><td class="right pos"><?= money($mon['received']) ?></td></tr>
          </table></article>
          <?php $ageing() ?>
        </div>
        <h3>⏰ Due within 7 days / overdue</h3>
        <?php $dues ? $due_table() : print('<div class="panel empty">Nothing due in the next 7 days. 🎉</div>');
    endif;
    if ($popup): ?>
      <dialog open><article>
        <header>🔔 Reminders</header>
        <?php if ($mine): ?><p class="note">📨 <strong><?= $mine ?></strong> request<?= $mine > 1 ? 's are' : ' is' ?> waiting for your approval. <a href="?p=approvals">Open approvals →</a></p><?php endif ?>
        <?php if ($dues): ?><p><strong>Payments due within 7 days or overdue:</strong></p><?php $due_table(5); endif ?>
        <footer><button onclick="this.closest('dialog').close()">OK</button></footer>
      </article></dialog>
    <?php endif;
    foot();

// ── approvals: everything sent to an admin for yes / no ──────────────────
case 'approvals':
    if ($post) {
        $type = enum_in($_POST['type'] ?? '', array_keys(REVIEWABLE));
        $id = (int) ($_POST['id'] ?? 0);
        $msg = review($type, $id, $a === 'approve', trim($_POST['reason'] ?? ''));
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $back = $type === 'invoice' && str_contains($ref, 'p=payment&') ? "?p=payment&id=$id" : (str_contains($ref, 'p=approvals') ? '?p=approvals' : '?');
        go($back, $msg);
    }
    $voted = [];
    if (can('approve')) {
        $rows = requests_for_me();
        $voted = q(REQUESTS_SQL . " WHERE r.status = 'pending' AND EXISTS (SELECT 1 FROM request_approvers ra WHERE ra.type = r.type AND ra.request_id = r.id
                   AND ra.user_id = ? AND ra.decision <> 'pending') ORDER BY r.created_at", [me()['id']])->fetchAll();
        $title = 'Waiting for your vote';
    } elseif (can('request')) {
        $rows = q(REQUESTS_SQL . " WHERE r.created_by = ? AND r.approvers IS NOT NULL ORDER BY r.status = 'pending' DESC, r.created_at DESC LIMIT 200", [me()['id']])->fetchAll();
        $title = 'My requests';
    } else {
        $rows = q(REQUESTS_SQL . " WHERE r.status = 'pending' ORDER BY r.created_at")->fetchAll();
        $title = 'Pending approvals';
    }
    head($title);
    $rows ? requests_list($rows, $date) : print('<div class="panel empty">Nothing here right now. 🎉</div>');
    if ($voted): ?><h3>🗳 You voted — waiting for others</h3><?php requests_list($voted, $date); endif;
    foot();

// ── send a rejected request again (administrator) ─────────────────────────
case 'resend':
    need('request');
    $type = enum_in($_GET['type'] ?? '', array_keys(REVIEWABLE));
    $id = (int) ($_GET['id'] ?? 0);
    $req = q(REQUESTS_SQL . ' WHERE r.type = ? AND r.id = ?', [$type, $id])->fetch() ?: fail('Request not found', 404);
    if ($req['status'] !== 'rejected') fail('Only a rejected request can be sent again. This one is ' . $req['status'] . '.');
    $inv = q('SELECT amount, approved_amount, request_amount FROM payments WHERE id = ?', [$id])->fetch();
    $max = number_format((paise($inv['amount']) - paise($inv['approved_amount'])) / 100, 2, '.', '');
    if ($post) {
        $req = request_in($max);
        $approvers = approver_in();
        q('UPDATE payments SET request_amount = ? WHERE id = ?', [$req, $id]);
        send_request($type, $id, $approvers, true, pay_text($req, $inv['amount']) . (trim($_POST['note'] ?? '') !== '' ? ' — ' . trim($_POST['note']) : ''));
        go($type === 'invoice' ? "?p=payment&id=$id" : '?p=approvals', 'Sent again for approval');
    }
    head('Send again for approval'); ?>
    <?php requests_list([$req], $date, false) ?>
    <form method="post" class="panel"><?= csrf_field() ?>
      <?= pay_mode_html($max, $inv['request_amount']) ?>
      <?= approver_select(approvers_of($type, $id)) ?>
      <label>Note to the admins <small>(what changed, or your answer to the rejection)</small>
        <textarea name="note" rows="3" placeholder="e.g. Corrected the amount as per the party's revised bill"></textarea></label>
      <div class="row"><a href="javascript:history.back()" role="button" class="secondary">Cancel</a><button>🔁 Send again</button></div>
      <small>Everyone ticked votes again from the start. Earlier votes and reasons stay in the history.</small>
    </form>
    <?php if ($hist = history_html($type, $id)): ?><h3>History</h3><div class="panel"><?= $hist ?></div><?php endif;
    foot();

// ── invoice register (list + filters + Excel) ─────────────────────────────
case 'payments':
    $by = ($_GET['by'] ?? '') === 'invoice_date' ? 'invoice_date' : 'due_date';
    $w = ['1=1']; $args = []; $desc = [];
    if ($d = date_or_null($_GET['from'] ?? '')) { $w[] = "$by >= ?"; $args[] = $d; $desc[] = "$by from $d"; }
    if ($d = date_or_null($_GET['to'] ?? ''))   { $w[] = "$by <= ?"; $args[] = $d; $desc[] = "$by to $d"; }
    if (in_array($_GET['status'] ?? '', STATUSES, true)) { $w[] = 'status = ?'; $args[] = $_GET['status']; $desc[] = $_GET['status']; }
    if (in_array($_GET['pay'] ?? '', PAY_STATES, true)) { $w[] = 'pay_status = ?'; $args[] = $_GET['pay']; $desc[] = $_GET['pay']; }
    if (($_GET['pay'] ?? '') === 'ready') { $w[] = 'payable > 0'; $desc[] = 'ready to pay'; }
    if (in_array($_GET['bill'] ?? '', ['gst', 'cash'], true)) { $w[] = 'bill_type = ?'; $args[] = $_GET['bill']; $desc[] = strtoupper($_GET['bill']) . ' bills'; }
    if (($s = trim($_GET['party'] ?? '')) !== '') { $w[] = 'party LIKE ?'; $args[] = "%$s%"; $desc[] = "party: $s"; }
    if (($s = trim($_GET['product'] ?? '')) !== '') { $w[] = 'products = ?'; $args[] = $s; $desc[] = "product: $s"; }
    $ages = ['overdue' => 'days_overdue > 0', 'not_due' => 'outstanding > 0 AND days_overdue = 0', 'd1_30' => 'days_overdue BETWEEN 1 AND 30',
             'd31_60' => 'days_overdue BETWEEN 31 AND 60', 'd61_90' => 'days_overdue BETWEEN 61 AND 90', 'd90' => 'days_overdue > 90'];
    if (isset($ages[$_GET['age'] ?? ''])) { $w[] = $ages[$_GET['age']] . " AND status <> 'rejected'"; $desc[] = AGEING[$_GET['age']] ?? 'Overdue'; }
    // ponytail: no paging; add LIMIT/OFFSET when the register passes a few thousand invoices
    $rows = q('SELECT * FROM payment_register WHERE ' . implode(' AND ', $w) . ' ORDER BY due_date, id', $args)->fetchAll();
    $qs = http_build_query(array_diff_key($_GET, ['p' => 1]));
    if (isset($_GET['xlsx'])) register_workbook($rows, implode(', ', $desc));
    [$t] = summarize($rows);
    $acts = '<a href="?p=payments&xlsx=1' . ($qs ? '&' . h($qs) : '') . '" role="button" class="secondary">⬇ Excel</a>'
          . (can('import') ? '<a href="?p=import" role="button" class="secondary">📥 Import</a>' : '')
          . (can('request') ? '<a href="?p=payment" role="button">＋ New invoice</a>' : '');
    head('Invoice register', $acts); ?>
    <form class="filters panel"><input type="hidden" name="p" value="payments">
      <label>Date of<select name="by"><option value="due_date">Due date</option><option value="invoice_date" <?= $sel('by', 'invoice_date') ?>>Invoice date</option></select></label>
      <label>From<input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
      <label>Payment<select name="pay"><option value="">All</option>
        <option value="ready" <?= $sel('pay', 'ready') ?>>💸 Ready to pay</option>
        <?php foreach (PAY_STATES as $st): ?><option <?= $sel('pay', $st) ?>><?= $st ?></option><?php endforeach ?></select></label>
      <label>Approval<select name="status"><option value="">All</option>
        <?php foreach (STATUSES as $st): ?><option <?= $sel('status', $st) ?>><?= $st ?></option><?php endforeach ?></select></label>
      <label>Overdue<select name="age"><option value="">All</option><option value="overdue" <?= $sel('age', 'overdue') ?>>Any overdue</option>
        <?php foreach (AGEING as $k => $label): ?><option value="<?= $k ?>" <?= $sel('age', $k) ?>><?= h($label) ?></option><?php endforeach ?></select></label>
      <label>Bill<select name="bill"><option value="">All</option><option value="gst" <?= $sel('bill', 'gst') ?>>GST</option><option value="cash" <?= $sel('bill', 'cash') ?>>Cash</option></select></label>
      <label>Party<input name="party" value="<?= h($_GET['party'] ?? '') ?>" placeholder="Search"></label>
      <label>Product<select name="product"><option value="">All</option>
        <?php foreach (q('SELECT name FROM products ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $n): ?><option <?= $sel('product', $n) ?>><?= h($n) ?></option><?php endforeach ?></select></label>
      <button>Filter</button>
    </form>
    <div class="tbl"><table class="dense"><thead><tr><th>Invoice</th><th>Party</th><th class="right">Amount</th><th class="right">Paid</th>
      <th class="right">Outstanding</th><th>Due</th><th>Status</th><th>Product / remarks</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="?p=payment&id=<?= $r['id'] ?>"><strong><?= h($r['invoice_no'] ?: '#' . $r['id']) ?></strong></a><div class="sub"><?= $date($r['invoice_date']) ?></div></td>
        <td class="wrap"><a href="?p=payment&id=<?= $r['id'] ?>"><?= h($r['party']) ?></a></td>
        <td class="right"><?= money($r['amount']) ?></td><td class="right pos"><?= money($r['paid']) ?></td><td class="right"><strong><?= money($r['outstanding']) ?></strong></td>
        <td><?= $date($r['due_date']) ?><?= $r['days_overdue'] > 0 ? '<div class="sub neg">' . (int) $r['days_overdue'] . ' days overdue</div>' : '' ?></td>
        <td><?= badge($r['pay_status']) ?><?= $r['status'] !== 'approved' ? ' ' . badge($r['status']) : '' ?>
          <?= $r['payable'] > 0 && can('pay') ? '<a class="pay-btn" href="?p=payment&id=' . $r['id'] . '#pay">💸 Pay</a>' : '' ?></td>
        <td class="wrap"><?= h(mb_strimwidth($r['products'], 0, 32, '…')) ?></td></tr>
    <?php endforeach;
    if (!$rows): ?><tr><td colspan="8" class="empty">No invoices match.</td></tr><?php endif ?></tbody>
    <tfoot><tr><th colspan="2"><?= count($rows) ?> invoices · totals exclude rejected</th><th class="right"><?= money($t['invoiced']) ?></th>
      <th class="right"><?= money($t['paid']) ?></th><th class="right"><?= money($t['outstanding']) ?></th><th colspan="3"></th></tr></tfoot></table></div>
    <?php foot();

// ── party-wise outstanding ────────────────────────────────────────────────
case 'outstanding':
    [$t, $parties] = summarize(q('SELECT * FROM payment_register')->fetchAll());
    head('Party-wise outstanding', '<a href="?p=payments&xlsx=1" role="button" class="secondary">⬇ Excel (all 3 sheets)</a>'); ?>
    <div class="tbl"><table><thead><tr><th>Party</th><th class="right">Invoices</th><th class="right">Total invoiced</th><th class="right">Paid</th>
      <th class="right">Outstanding</th><th class="right">Overdue amount</th><th class="right">Oldest days overdue</th></tr></thead><tbody>
    <?php foreach ($parties as $name => $pt): ?>
      <tr><td><a href="?p=payments&party=<?= urlencode((string) $name) ?>"><?= h($name) ?></a></td><td class="right"><?= $pt['invoices'] ?></td>
        <td class="right"><?= money($pt['invoiced']) ?></td><td class="right pos"><?= money($pt['paid']) ?></td><td class="right"><strong><?= money($pt['outstanding']) ?></strong></td>
        <td class="right <?= $pt['overdue'] > 0 ? 'neg' : '' ?>"><?= money($pt['overdue']) ?></td><td class="right"><?= $pt['oldest'] ?></td></tr>
    <?php endforeach;
    if (!$parties): ?><tr><td colspan="7" class="empty">No invoices yet.</td></tr><?php endif ?></tbody>
    <tfoot><tr><th><?= count($parties) ?> parties</th><th></th><th class="right"><?= money($t['invoiced']) ?></th><th class="right"><?= money($t['paid']) ?></th>
      <th class="right"><?= money($t['outstanding']) ?></th><th class="right"><?= money($t['overdue']) ?></th><th></th></tr></tfoot></table></div>
    <?php foot();

// ── one invoice: create / edit / approve / pay (part or full) / follow-up / files / chat ─
case 'payment':
    $id = (int) ($_GET['id'] ?? 0);
    $pm = $id ? (q("SELECT r.*, u.name AS creator, v.name AS reviewer, (SELECT approvers FROM requests WHERE type = 'invoice' AND id = r.id) AS approvers
                    FROM payment_register r JOIN users u ON u.id = r.created_by LEFT JOIN users v ON v.id = r.reviewed_by WHERE r.id = ?", [$id])->fetch() ?: fail('Invoice not found', 404)) : null;
    if (!$pm) need('request');
    $from = $pm ? null : (int) ($_GET['from'] ?? 0); // new invoice typed from an invoice photo in the inbox
    $inb = $from ? (q('SELECT i.*, u.name AS by_name FROM inbox i JOIN users u ON u.id = i.uploaded_by WHERE i.id = ? AND i.closed_at IS NULL', [$from])->fetch()
                    ?: fail('That invoice photo was already handled.')) : null;
    // administrator can edit until fully paid; any edit goes back for approval
    $editable = can('request') && (!$pm || $pm['outstanding'] > 0);
    $uid = me()['id'];

    if ($post) {
        if (!$pm && $a !== 'save') fail('Invoice not found', 404);
        switch ($a) {
        case 'save':
            if (!$editable) fail('This invoice cannot be edited by you', 403);
            $amount = amount_in($_POST['amount'] ?? '');
            if ($pm && paise($amount) < paise($pm['paid'])) fail('Amount cannot be less than what is already paid (' . money($pm['paid']) . ')');
            [$party, $product, $files] = [picked('party'), picked('product'), checked_uploads('files')];
            $f = [trim($_POST['invoice_no'] ?? '') ?: null, date_in($_POST['invoice_date'] ?? ''), enum_in($_POST['bill_type'] ?? '', ['gst', 'cash']),
                  $amount, date_in($_POST['due_date'] ?? ''), ($approvers = approver_in())[0]];
            // same invoice no. already entered for this party? warn (possible double payment), allow "Save anyway"
            if ($f[0] !== null && empty($_POST['dup_ok'])) {
                $dups = q('SELECT pm.id, pm.invoice_date, pm.amount FROM payments pm JOIN parties p ON p.id = pm.party_id
                           WHERE p.name = ? AND pm.invoice_no = ? AND pm.id <> ?', [$party, $f[0], $id])->fetchAll();
                if ($dups) {
                    head('Possible duplicate invoice'); ?>
                    <div class="error-box">⚠️ Invoice no. <strong><?= h($f[0]) ?></strong> already exists for <strong><?= h($party) ?></strong>. Entering it again could mean paying twice.</div>
                    <div class="panel" style="margin-top:1rem"><strong>Already in the app:</strong><ul>
                      <?php foreach ($dups as $d): ?><li><a href="?p=payment&id=<?= $d['id'] ?>" target="_blank">Invoice #<?= $d['id'] ?></a> · <?= $date($d['invoice_date']) ?> · <?= money($d['amount']) ?></li><?php endforeach ?>
                    </ul>
                    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
                      <?php if ($files): ?><label>📎 Attach the invoice copy again<input type="file" name="files[]" accept="image/*,.pdf" multiple></label><?php endif ?>
                      <?php foreach ($_POST as $k => $v) if ($k !== 'csrf') foreach ((array) $v as $one) echo '<input type="hidden" name="' . h($k) . (is_array($v) ? '[]' : '') . '" value="' . h((string) $one) . '">'; ?>
                      <input type="hidden" name="dup_ok" value="1">
                      <div class="row"><a href="javascript:history.back()" role="button" class="secondary">← Go back and fix</a><button>Save anyway</button></div>
                    </form></div>
                    <?php foot();
                }
            }
            // full or part: new invoice → up to the amount; edited → up to what is not paid yet (unpaid approvals are asked again)
            $req = request_in(number_format((paise($amount) - paise($pm['paid'] ?? 0)) / 100, 2, '.', ''));
            $f = [party_id($party), $f[0], $f[1], product_name($product), ...array_slice($f, 2)]; // names saved only once everything is valid
            if (!$pm) {
                q('INSERT INTO payments (party_id, invoice_no, invoice_date, products, bill_type, amount, due_date, approver_id, request_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$f, $req, $uid]);
                $id = (int) db()->lastInsertId();
            } else {
                q("UPDATE payments SET party_id = ?, invoice_no = ?, invoice_date = ?, products = ?, bill_type = ?, amount = ?, due_date = ?, approver_id = ?,
                   request_amount = ?, approved_amount = ?, status = 'pending', reviewed_by = NULL, reject_reason = NULL WHERE id = ?", [...$f, $req, $pm['paid'], $id]);
            }
            save_uploads($id, $files);
            if ($inb) { // the photo becomes the invoice copy (a copy, so the sender still sees what they sent)
                $copy = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($inb['file_path'], PATHINFO_EXTENSION));
                if (copy(upload_dir() . '/' . basename($inb['file_path']), upload_dir() . "/$copy"))
                    q('INSERT INTO attachments (payment_id, file_path, file_name, uploaded_by) VALUES (?, ?, ?, ?)', [$id, $copy, $inb['file_name'], $inb['uploaded_by']]);
                q('UPDATE inbox SET payment_id = ?, closed_by = ?, closed_at = NOW() WHERE id = ?', [$id, $uid, $inb['id']]);
            }
            send_request('invoice', $id, $approvers, (bool) $pm, pay_text($req, $amount) . ($pm ? ' — invoice details were edited' : ''));
            go("?p=payment&id=$id", 'Saved and sent for approval');
        case 'pay': // record a full or part payment
            need('pay');
            $amt = amount_in($_POST['amount'] ?? '');
            $on = date_in($_POST['paid_on'] ?? '');
            db()->beginTransaction(); // lock the invoice so two clicks can't overpay it
            $inv = q('SELECT approved_amount FROM payments WHERE id = ? FOR UPDATE', [$id])->fetch();
            $paid = q('SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE payment_id = ?', [$id])->fetchColumn();
            $left = paise($inv['approved_amount']) - paise($paid);
            if ($left <= 0) fail('Nothing is approved to pay right now. Request the next part first.');
            if (paise($amt) > $left) fail('That is more than the approved amount still to pay (' . money($left / 100) . ').');
            q('INSERT INTO payouts (payment_id, amount, paid_on, note, created_by) VALUES (?, ?, ?, ?, ?)', [$id, $amt, $on, trim($_POST['note'] ?? '') ?: null, $uid]);
            db()->commit();
            break;
        case 'next':
            need('request');
            if ($pm['status'] === 'pending') fail('A request for this invoice is already waiting for votes.');
            $req = request_in(number_format((paise($pm['amount']) - paise($pm['approved_amount'])) / 100, 2, '.', ''));
            $approvers = approver_in();
            q('UPDATE payments SET request_amount = ? WHERE id = ?', [$req, $id]);
            send_request('invoice', $id, $approvers, true, 'next ' . pay_text($req, $pm['amount']) . (trim($_POST['note'] ?? '') !== '' ? ' — ' . trim($_POST['note']) : ''));
            go("?p=payment&id=$id", 'Next part sent for approval');
        case 'unpay':
            need('manage');
            q('DELETE FROM payouts WHERE id = ? AND payment_id = ?', [(int) ($_POST['payout'] ?? 0), $id])->rowCount() ?: fail('Payment not found', 404);
            break;
        case 'delete':
            need('manage');
            if (q('SELECT 1 FROM payouts WHERE payment_id = ? AND in_balance', [$id])->fetch()) fail('Payments are recorded against this invoice. Remove them first, then delete the invoice.');
            foreach (q('SELECT file_path FROM attachments WHERE payment_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(upload_dir() . '/' . basename($fp));
            q('DELETE FROM payments WHERE id = ?', [$id]);
            q("DELETE FROM request_approvers WHERE type = 'invoice' AND request_id = ?", [$id]);
            q("DELETE FROM request_log WHERE type = 'invoice' AND request_id = ?", [$id]);
            go('?p=payments', "Invoice #$id deleted");
        case 'due':
            if (!$editable) fail('This invoice cannot be edited by you', 403);
            $new = date_in($_POST['due_date'] ?? '');
            q('UPDATE payments SET due_date = ? WHERE id = ?', [$new, $id]);
            $note = trim($_POST['note'] ?? '');
            q("INSERT INTO messages (payment_id, user_id, kind, body) VALUES (?, ?, 'followup', ?)",
              [$id, $uid, 'Due date changed ' . date('d M Y', strtotime($pm['due_date'])) . ' → ' . date('d M Y', strtotime($new)) . ($note !== '' ? ". $note" : '')]);
            break;
        case 'msg':
            need('chat');
            q('INSERT INTO messages (payment_id, user_id, kind, body) VALUES (?, ?, ?, ?)', [$id, $uid, enum_in($_POST['kind'] ?? 'chat', ['chat', 'followup']), req('body')]);
            break;
        case 'upload':
            need('files');
            save_uploads($id, checked_uploads('file') ?: fail('Choose a file to upload'));
            break;
        case 'unattach':
            $att = q('SELECT * FROM attachments WHERE id = ? AND payment_id = ?', [(int) ($_POST['att'] ?? 0), $id])->fetch() ?: fail('File not found', 404);
            if ($att['uploaded_by'] != $uid && !can('manage')) fail('Only the uploader or admin can remove this file', 403);
            @unlink(upload_dir() . '/' . basename($att['file_path']));
            q('DELETE FROM attachments WHERE id = ?', [$att['id']]);
            break;
        default:
            fail('Unknown action');
        }
        go("?p=payment&id=$id", 'Saved');
    }

    head($pm ? 'Invoice ' . ($pm['invoice_no'] ?: "#$id") : 'New invoice', $pm ? '<a href="?p=payments" role="button" class="secondary">← All invoices</a>' : '');
    if ($pm):
        $payouts = q('SELECT po.*, u.name FROM payouts po JOIN users u ON u.id = po.created_by WHERE po.payment_id = ? ORDER BY po.paid_on, po.id', [$id])->fetchAll(); ?>
      <p><?= badge($pm['pay_status']) ?> <?= badge($pm['status']) ?> &nbsp; <strong><?= h($pm['party']) ?></strong> · <?= strtoupper($pm['bill_type']) ?> bill
        <?= $pm['invoice_date'] ? ' · dated ' . $date($pm['invoice_date']) : '' ?> · <?= h($pm['products']) ?><br>
        <small>Added by <?= h($pm['creator']) ?> on <?= $date($pm['created_at']) ?></small></p>
      <?php $votes = votes_for([['type' => 'invoice', 'id' => $id]])["invoice:$id"] ?? [];
      if ($votes || $pm['status'] !== 'approved'): ?>
        <section class="panel approval s-<?= $pm['status'] ?>">
          <div class="approval-head"><strong>🗳 Approval · <?= ucfirst(pay_text($pm['request_amount'] ?? $pm['amount'], $pm['amount'])) ?></strong>
            <span><?= ['pending' => '⏳ Voting in progress', 'approved' => '✅ Approved by everyone', 'rejected' => '❌ Rejected'][$pm['status']] ?></span></div>
          <?= votes_html($votes, true) ?>
          <?php if (can_decide('invoice', $id)): ?><div class="your-vote"><strong>Your vote</strong><?= review_buttons('invoice', $id, $pm['status']) ?></div>
          <?php elseif ($pm['status'] === 'rejected' && can('request')): ?>
            <a role="button" class="resend" href="?p=resend&type=invoice&id=<?= $id ?>">🔁 Send again for approval</a>
          <?php endif ?>
          <?php if ($hist = history_html('invoice', $id)): ?><details class="hist"><summary>History</summary><?= $hist ?></details><?php endif ?>
        </section>
      <?php endif ?>
      <div class="stats">
        <article class="stat" data-icon="🧾"><small>Invoice amount</small><div class="big"><?= money($pm['amount']) ?></div></article>
        <article class="stat blue" data-icon="✅"><small>Approved to pay</small><div class="big"><?= money($pm['approved_amount']) ?></div>
          <?php if ($pm['payable'] > 0): ?><div class="sub pos"><?= money($pm['payable']) ?> ready to pay</div><?php endif ?></article>
        <article class="stat green" data-icon="💸"><small>Paid</small><div class="big pos"><?= money($pm['paid']) ?></div></article>
        <article class="stat" data-icon="📌"><small>Outstanding</small><div class="big <?= $pm['outstanding'] > 0 ? 'neg' : '' ?>"><?= money($pm['outstanding']) ?></div></article>
        <article class="stat <?= $pm['days_overdue'] > 0 ? 'red' : 'blue' ?>" data-icon="📅"><small>Due date</small><div class="big"><?= $date($pm['due_date']) ?></div>
          <?php if ($pm['days_overdue'] > 0): ?><div class="sub neg"><?= (int) $pm['days_overdue'] ?> days overdue</div><?php endif ?></article>
      </div>

      <h3>💸 Payments made</h3>
      <?php if ($payouts): ?>
        <div class="tbl"><table><thead><tr><th>Date</th><th class="right">Amount</th><th>Note</th><th>By</th><?= can('manage') ? '<th></th>' : '' ?></tr></thead><tbody>
        <?php foreach ($payouts as $po): ?>
          <tr><td><?= $date($po['paid_on']) ?></td><td class="right"><strong><?= money($po['amount']) ?></strong></td>
            <td><?= h($po['note']) ?><?= $po['in_balance'] ? '' : ' <small>(before using the app — not in balance)</small>' ?></td><td><?= h($po['name']) ?></td>
            <?php if (can('manage')): ?><td><form method="post" class="inline" onsubmit="return confirm('Remove this payment? The amount goes back into the balance.')"><?= csrf_field() ?>
              <input type="hidden" name="a" value="unpay"><input type="hidden" name="payout" value="<?= $po['id'] ?>"><button class="no">remove</button></form></td><?php endif ?></tr>
        <?php endforeach ?></tbody></table></div>
      <?php else: ?><p><small>No payments yet.</small></p><?php endif ?>
      <?php if ($pm['payable'] > 0 && can('pay')): ?>
        <form method="post" class="panel" id="pay"><?= csrf_field() ?><input type="hidden" name="a" value="pay">
          <strong>💸 Record payment</strong> <small>— <?= money($pm['payable']) ?> approved and not paid yet; pay all of it or a part</small><br><br>
          <div class="grid">
            <label>Amount (₹) — full or part<input name="amount" inputmode="decimal" value="<?= h($pm['payable']) ?>" required></label>
            <label>Paid on<input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" required></label>
            <label>Note<input name="note" placeholder="e.g. NEFT ref / cheque no."></label>
          </div>
          <button>💸 Record payment</button></form>
      <?php endif ?>

      <?php $left = (paise($pm['amount']) - paise($pm['approved_amount'])) / 100;
      if (can('request') && $pm['status'] === 'approved' && $left > 0): ?>
        <details class="panel" id="next"><summary>➕ Request next part <small>(<?= money($left) ?> not approved yet)</small></summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="next">
            <?= pay_mode_html(number_format($left, 2, '.', '')) ?>
            <?= approver_select(approvers_of('invoice', $id)) ?>
            <label>Note <small>(optional)</small><input name="note" placeholder="e.g. second instalment as agreed"></label>
            <button>📨 Send for approval</button></form>
        </details>
      <?php endif ?>
      <?php if ($editable): ?>
        <details class="panel"><summary>📅 Change due date / add follow-up</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="due">
            <div class="grid"><label>New due date<input type="date" name="due_date" value="<?= h($pm['due_date']) ?>" required></label>
              <label>Follow-up note<input name="note" placeholder="e.g. party asked for 1 week more"></label></div>
            <button>Update due date</button></form>
        </details>
      <?php endif ?>
    <?php endif;

    if ($inb): ?>
      <div class="from-photo"><?= inbox_card($inb, '<small>Type the details from this photo. It is attached to the invoice when you save.</small>') ?></div>
    <?php endif;
    if ($editable): ?>
      <details class="panel" <?= $pm ? '' : 'open' ?>><summary><?= $pm ? '✏️ Edit details' : '🧾 Invoice details' ?></summary>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="save">
          <div class="grid"><?= pick('party', 'parties', $pm['party'] ?? null, 'Party') ?><?= pick('product', 'products', $pm['products'] ?? null, 'Product') ?></div>
          <div class="grid">
            <label>Invoice no.<input name="invoice_no" value="<?= h($pm['invoice_no'] ?? '') ?>" placeholder="leave empty if none"></label>
            <label>Invoice date<input type="date" name="invoice_date" value="<?= h($pm['invoice_date'] ?? date('Y-m-d')) ?>" required></label>
            <label>Bill type<select name="bill_type"><option value="gst">GST bill</option><option value="cash" <?= ($pm['bill_type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash bill</option></select></label>
          </div>
          <div class="grid">
            <label>Invoice amount (₹)<input name="amount" inputmode="decimal" value="<?= h($pm['amount'] ?? '') ?>" required></label>
            <label>Due date<input type="date" name="due_date" value="<?= h($pm['due_date'] ?? '') ?>" required></label>
          </div>
          <?= $pm ? pay_mode_html(number_format((paise($pm['amount']) - paise($pm['paid'])) / 100, 2, '.', ''), $pm['request_amount']) : pay_mode_html(null) ?>
          <?= approver_select($pm ? approvers_of('invoice', $id) : []) ?>
          <label>📎 Invoice copy <small>(photo or PDF — you can pick several; on a phone you can use the camera)</small>
            <input type="file" name="files[]" accept="image/*,.pdf" multiple></label>
          <?php if ($pm): ?><small>Saving changes sends this invoice back for approval.</small><?php endif ?>
          <button><?= $pm ? 'Save & send for approval' : '📨 Create & send for approval' ?></button>
        </form>
      </details>
    <?php endif;

    if ($pm):
        $files = q('SELECT * FROM attachments WHERE payment_id = ? ORDER BY id', [$id])->fetchAll();
        $msgs = q('SELECT m.*, u.name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.payment_id = ? ORDER BY m.id', [$id])->fetchAll(); ?>
      <h3>📎 Invoice / files</h3>
      <?php if ($files): ?><ul><?php foreach ($files as $f): ?>
        <li><a href="?p=file&id=<?= $f['id'] ?>" target="_blank"><?= h($f['file_name']) ?></a>
          <?php if ($f['uploaded_by'] == $uid || can('manage')): ?>
            <form method="post" class="inline" onsubmit="return confirm('Remove file?')"><?= csrf_field() ?><input type="hidden" name="a" value="unattach"><input type="hidden" name="att" value="<?= $f['id'] ?>"><button class="no">remove</button></form>
          <?php endif ?></li>
      <?php endforeach ?></ul><?php endif ?>
      <?php if (can('files')): ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="upload">
        <div class="row"><input type="file" name="file[]" accept="image/*,.pdf,.xls,.xlsx" multiple required><button>⬆ Upload</button></div></form>
      <?php endif ?>

      <h3>💬 Discussion & follow-ups</h3>
      <div id="chat" data-payment="<?= $id ?>" data-after="<?= $msgs ? end($msgs)['id'] : 0 ?>"><?php foreach ($msgs as $m) echo msg_html($m) ?></div>
      <?php if (can('chat')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="msg">
        <textarea name="body" placeholder="Write a message…" required></textarea>
        <div class="row end"><select name="kind"><option value="chat">💬 Chat</option><option value="followup">📌 Follow-up note</option></select><button>Send</button></div></form>
      <?php endif ?>

      <?php if (can('manage')): ?>
        <form method="post" onsubmit="return confirm('Delete this invoice with its payments, files and chat?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete">
          <button class="no">🗑 Delete invoice</button></form>
      <?php endif;
    endif;
    foot();

case 'file':
    $f = q('SELECT * FROM attachments WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch() ?: fail('File not found', 404);
    send_file($f['file_path'], $f['file_name']);

// ── invoice photos: 'user' logins send them, the Administrator turns them into invoices ──
case 'inboxfile':
    $f = q('SELECT * FROM inbox WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch() ?: fail('File not found', 404);
    if (!can('inbox') && (int) $f['uploaded_by'] !== (int) me()['id']) fail('Your role does not allow this.', 403);
    send_file($f['file_path'], $f['file_name']);

case 'uploads':
    need('upload');
    if ($post) {
        $files = checked_uploads('files') ?: fail('Choose at least one photo or PDF of the invoice.');
        $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);
        foreach ($files as $f)
            q('INSERT INTO inbox (file_path, file_name, note, uploaded_by) VALUES (?, ?, ?, ?)', [store_upload($f), mb_substr($f['name'], 0, 255), $note, me()['id']]);
        go('?p=uploads', count($files) . ' file' . (count($files) > 1 ? 's' : '') . ' sent to the Administrator');
    }
    $rows = q('SELECT * FROM inbox WHERE uploaded_by = ? ORDER BY id DESC LIMIT 100', [me()['id']])->fetchAll();
    head('Send invoice photo'); ?>
    <form method="post" enctype="multipart/form-data" class="panel upload-box"><?= csrf_field() ?>
      <label class="drop">📷 <strong>Take or choose invoice photos</strong><small>Photo or PDF · you can pick several</small>
        <input type="file" name="files[]" accept="image/*,.pdf" multiple required></label>
      <label>Note for the Administrator <small>(optional)</small><input name="note" maxlength="500" placeholder="e.g. Diesel bill, pay by Friday"></label>
      <button>📨 Send to Administrator</button>
    </form>
    <h3>What you have sent</h3>
    <?php if (!$rows): ?><p class="note">Nothing sent yet.</p><?php endif ?>
    <div class="photos">
    <?php foreach ($rows as $r)
        echo inbox_card($r, $r['payment_id'] ? '<span class="badge approved">✅ Invoice entered</span>'
            : ($r['closed_at'] ? '<span class="badge rejected">Closed</span>' . ($r['close_note'] ? '<div class="why">' . h($r['close_note']) . '</div>' : '')
            : '<span class="badge pending">⏳ With the Administrator</span>')); ?>
    </div>
    <?php foot();

case 'inbox':
    need('inbox');
    if ($post) { // closed without an invoice (duplicate, not a bill, …)
        $n = q("UPDATE inbox SET closed_by = ?, closed_at = NOW(), close_note = ? WHERE id = ? AND closed_at IS NULL",
               [me()['id'], mb_substr(req('reason'), 0, 500), (int) ($_POST['id'] ?? 0)])->rowCount();
        go('?p=inbox', $n ? 'Closed' : 'Already handled');
    }
    $q = 'SELECT i.*, u.name AS by_name, pm.invoice_no FROM inbox i JOIN users u ON u.id = i.uploaded_by LEFT JOIN payments pm ON pm.id = i.payment_id';
    $open = q("$q WHERE i.closed_at IS NULL ORDER BY i.id")->fetchAll();
    $done = q("$q WHERE i.closed_at IS NOT NULL ORDER BY i.closed_at DESC LIMIT 30")->fetchAll();
    head('Invoice inbox'); ?>
    <?php if (!$open): ?><p class="note">🎉 No new invoice photos.</p><?php endif ?>
    <div class="photos">
    <?php foreach ($open as $r)
        echo inbox_card($r, '<div class="photo-acts"><a role="button" href="?p=payment&from=' . $r['id'] . '">🧾 Make invoice</a>'
            . '<button type="button" class="secondary" data-reject>✕ Close</button><form method="post" class="inline rej-form" hidden>' . csrf_field()
            . '<input type="hidden" name="id" value="' . $r['id'] . '"><input name="reason" placeholder="Why? e.g. duplicate" required><button class="no">Close</button></form></div>'); ?>
    </div>
    <?php if ($done): ?>
    <h3>Handled recently</h3>
    <div class="photos">
    <?php foreach ($done as $r)
        echo inbox_card($r, $r['payment_id'] ? '<a class="badge approved" href="?p=payment&id=' . $r['payment_id'] . '">✅ Invoice ' . h($r['invoice_no'] ?: '#' . $r['payment_id']) . '</a>'
            : '<span class="badge rejected">Closed</span>' . ($r['close_note'] ? '<div class="why">' . h($r['close_note']) . '</div>' : '')); ?>
    </div>
    <?php endif;
    foot();

// ── chat ──────────────────────────────────────────────────────────────────
case 'msgs': // polled by the chat box
    $pid = (int) ($_GET['payment'] ?? 0);
    $rows = q('SELECT m.*, u.name FROM messages m JOIN users u ON u.id = m.user_id WHERE ' . ($pid ? 'm.payment_id = ?' : 'm.payment_id IS NULL') . ' AND m.id > ? ORDER BY m.id',
              $pid ? [$pid, (int) ($_GET['after'] ?? 0)] : [(int) ($_GET['after'] ?? 0)])->fetchAll();
    foreach ($rows as $m) echo msg_html($m);
    exit;

case 'chat':
    if ($post) {
        need('chat');
        q("INSERT INTO messages (payment_id, user_id, kind, body) VALUES (NULL, ?, 'chat', ?)", [me()['id'], req('body')]);
        go('?p=chat');
    }
    $msgs = array_reverse(q('SELECT m.*, u.name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.payment_id IS NULL ORDER BY m.id DESC LIMIT 200')->fetchAll());
    $recent = q('SELECT m.*, u.name, p.name AS party FROM messages m JOIN users u ON u.id = m.user_id JOIN payments pm ON pm.id = m.payment_id
                 JOIN parties p ON p.id = pm.party_id ORDER BY m.id DESC LIMIT 10')->fetchAll();
    head('Team chat'); ?>
    <div class="two">
      <div>
        <div id="chat" data-payment="0" data-after="<?= $msgs ? end($msgs)['id'] : 0 ?>"><?php foreach ($msgs as $m) echo msg_html($m) ?></div>
        <form method="post"><?= csrf_field() ?>
          <fieldset role="group"><input name="body" placeholder="Message to everyone…" required autocomplete="off"><button>Send</button></fieldset></form>
      </div>
      <article><header><strong>Latest on invoices</strong></header>
        <?php foreach ($recent as $m): ?>
          <p><a href="?p=payment&id=<?= $m['payment_id'] ?>"><?= h($m['party']) ?></a> — <b><?= h($m['name']) ?>:</b> <?= h(mb_strimwidth($m['body'], 0, 90, '…')) ?></p>
        <?php endforeach; if (!$recent): ?><small>No invoice discussions yet.</small><?php endif ?>
      </article>
    </div>
    <?php foot();

// ── receipts (money received from customers): no approval, count at once ─
case 'receivables':
    if ($post) {
        if ($a === 'delete') {
            need('manage');
            q('DELETE FROM receivables WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            go('?p=receivables', 'Receipt deleted');
        }
        need('request');
        // check everything first, then add new customer / sales person names
        [$customer, $sp, $amt, $on, $state, $against] = [picked('customer'), picked('sales_person'), amount_in($_POST['amount'] ?? ''),
            date_in($_POST['received_on'] ?? ''), enum_in($_POST['state'] ?? '', STATES), enum_in($_POST['against'] ?? '', ['sales', 'capital'])];
        q("INSERT INTO receivables (customer_id, sales_person, state, amount, received_on, against, note, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?)", [
            party_id($customer, isset($_POST['gst']) ? true : null, $_POST['gstin'] ?? '', 'customers'), add_name('sales_people', $sp), $state,
            $amt, $on, $against, trim($_POST['note'] ?? '') ?: null, me()['id']]);
        go('?p=receivables', 'Receipt saved — added to the balance');
    }
    $w = ['1=1']; $args = [];
    if ($d = date_or_null($_GET['from'] ?? '')) { $w[] = 'r.received_on >= ?'; $args[] = $d; }
    if ($d = date_or_null($_GET['to'] ?? ''))   { $w[] = 'r.received_on <= ?'; $args[] = $d; }
    if (($s = trim($_GET['sp'] ?? '')) !== '') { $w[] = 'r.sales_person = ?'; $args[] = $s; }
    if (in_array($_GET['state'] ?? '', STATES, true)) { $w[] = 'r.state = ?'; $args[] = $_GET['state']; }
    if (in_array($_GET['against'] ?? '', ['sales', 'capital'], true)) { $w[] = 'r.against = ?'; $args[] = $_GET['against']; }
    if (in_array($_GET['gst'] ?? '', ['0', '1'], true)) { $w[] = 'COALESCE(c.gst_registered, p.gst_registered) = ?'; $args[] = $_GET['gst']; }
    if (($s = trim($_GET['customer'] ?? '')) !== '') { $w[] = 'COALESCE(c.name, p.name) LIKE ?'; $args[] = "%$s%"; }
    $rows = q('SELECT r.received_on, COALESCE(c.name, p.name) AS customer, r.sales_person, r.state, r.against, r.amount,
                      IF(COALESCE(c.gst_registered, p.gst_registered), \'Yes\', \'No\') AS gst_registered, COALESCE(c.gstin, p.gstin) AS gstin, r.note, r.status, r.id
               FROM receivables r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN parties p ON p.id = r.party_id
               WHERE ' . implode(' AND ', $w) . ' ORDER BY r.received_on DESC, r.id DESC', $args)->fetchAll();
    if (isset($_GET['csv'])) csv('receipts', array_map(fn($r) => array_diff_key($r, ['id' => 1]), $rows));
    $counted = array_filter($rows, fn($r) => $r['status'] !== 'rejected');
    $qs = http_build_query(array_diff_key($_GET, ['p' => 1]));
    head('Receipts', '<a href="?p=receivables&csv=1' . ($qs ? '&' . h($qs) : '') . '" role="button" class="secondary">⬇ Excel (CSV)</a>');
    if (can('request')): ?>
    <details class="panel" id="add" open><summary>＋ New receipt (money received)</summary>
      <form method="post"><?= csrf_field() ?>
        <div class="grid"><?= pick('customer', 'customers', null, 'Customer') ?>
          <label>Amount (₹)<input name="amount" inputmode="decimal" required></label>
          <label>Date received<input type="date" name="received_on" value="<?= date('Y-m-d') ?>" required></label></div>
        <div class="grid"><?= pick('sales_person', 'sales_people', null, 'Sales person') ?>
          <label>State<select name="state" required><option value="">— Select state —</option>
            <?php foreach (STATES as $st): ?><option><?= h($st) ?></option><?php endforeach ?></select></label>
          <label>Against<select name="against"><option value="sales">Sales</option><option value="capital">Capital</option></select></label></div>
        <div class="grid">
          <label class="check"><input type="checkbox" name="gst" value="1"> Customer is GST registered</label>
          <label>GSTIN (optional)<input name="gstin" maxlength="15"></label>
          <label>Note<input name="note" placeholder="optional"></label></div>
        <button>💰 Save receipt</button>
      </form>
    </details>
    <?php endif ?>
    <form class="filters panel"><input type="hidden" name="p" value="receivables">
      <label>From<input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
      <label>Sales person<select name="sp"><option value="">All</option>
        <?php foreach (q('SELECT name FROM sales_people ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $n): ?><option <?= $sel('sp', $n) ?>><?= h($n) ?></option><?php endforeach ?></select></label>
      <label>State<select name="state"><option value="">All</option>
        <?php foreach (STATES as $st): ?><option <?= $sel('state', $st) ?>><?= h($st) ?></option><?php endforeach ?></select></label>
      <label>Against<select name="against"><option value="">All</option><option value="sales" <?= $sel('against', 'sales') ?>>Sales</option><option value="capital" <?= $sel('against', 'capital') ?>>Capital</option></select></label>
      <label>GST registered<select name="gst"><option value="">All</option><option value="1" <?= $sel('gst', '1') ?>>Yes</option><option value="0" <?= $sel('gst', '0') ?>>No</option></select></label>
      <label>Customer<input name="customer" value="<?= h($_GET['customer'] ?? '') ?>" placeholder="Search"></label>
      <button>Filter</button>
    </form>
    <div class="tbl"><table><thead><tr><th>Customer</th><th>Date</th><th>Sales person</th><th>State</th><th>Against</th><th class="right">Amount</th><th>GST reg.</th><th>Note</th><?= can('manage') ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><strong><?= h($r['customer'] ?? '—') ?></strong><?= $r['status'] === 'rejected' ? ' ' . badge('rejected') : '' ?></td>
        <td><?= $date($r['received_on']) ?></td><td><?= h($r['sales_person'] ?? '—') ?></td><td><?= h($r['state'] ?? '—') ?></td>
        <td><?= h(ucfirst($r['against'])) ?></td><td class="right <?= $r['status'] === 'rejected' ? '' : 'pos' ?>"><strong><?= money($r['amount']) ?></strong></td>
        <td><?= $r['gst_registered'] ?><?= $r['gstin'] ? ' <small>' . h($r['gstin']) . '</small>' : '' ?></td><td class="wrap"><?= h($r['note']) ?></td>
        <?php if (can('manage')): ?><td><form method="post" class="inline" onsubmit="return confirm('Delete this receipt? The balance changes back.')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="no">delete</button></form></td><?php endif ?></tr>
    <?php endforeach;
    if (!$rows): ?><tr><td colspan="9" class="empty">No receipts match.</td></tr><?php endif ?></tbody>
    <tfoot><tr><th colspan="5"><?= count($counted) ?> receipts</th><th class="right"><?= money(array_sum(array_map(fn($r) => paise($r['amount']), $counted)) / 100) ?></th><th colspan="3"></th></tr></tfoot></table></div>
    <?php foot();

// ── balance: admin and administrator add entries directly (no approval) ─
case 'cash':
    need('see_cash');
    if ($post) {
        need('balance');
        if ($a === 'delete') {
            q('DELETE FROM cash_entries WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            go('?p=cash', 'Entry deleted');
        }
        $kind = enum_in($_POST['kind'] ?? '', ['opening', 'adjustment', 'expense']);
        $amt = amount_in($_POST['amount'] ?? '', true);
        if ($kind === 'expense') $amt = '-' . ltrim($amt, '-');
        q("INSERT INTO cash_entries (entry_date, amount, kind, note, status, reviewed_by, created_by) VALUES (?, ?, ?, ?, 'approved', ?, ?)",
          [date_in($_POST['entry_date'] ?? ''), $amt, $kind, trim($_POST['note'] ?? '') ?: null, me()['id'], me()['id']]);
        go('?p=cash', 'Balance updated');
    }
    $rows = q('SELECT c.*, u.name FROM cash_entries c JOIN users u ON u.id = c.created_by ORDER BY c.entry_date DESC, c.id DESC')->fetchAll();
    $bal = q('SELECT balance FROM current_balance')->fetchColumn();
    head('Balance'); ?>
    <div class="stats"><article class="stat" data-icon="🏦"><small>Current balance</small><div class="big <?= $bal < 0 ? 'neg' : '' ?>"><?= money($bal) ?></div></article></div>
    <p class="note">Receipts add to the balance and recorded payments take away from it automatically. Use this page for the opening balance, corrections and other costs — they count straight away.</p>
    <?php if (can('balance')): ?>
    <form method="post" class="panel"><?= csrf_field() ?>
      <strong>＋ Add balance entry</strong><br><br>
      <div class="grid">
        <label>Type<select name="kind"><option value="expense">Expense / other cost (−)</option><option value="adjustment">Correction (+ or −)</option><option value="opening">Opening balance</option></select></label>
        <label>Amount (₹)<input name="amount" inputmode="decimal" placeholder="e.g. 2500  (correction: −2500 to reduce)" required></label>
        <label>Date<input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></label>
      </div>
      <label>Note<input name="note" placeholder="e.g. office rent, bank charges"></label>
      <button>Add entry</button>
    </form>
    <?php endif ?>
    <div class="tbl"><table><thead><tr><th>Date</th><th>Type</th><th class="right">Amount</th><th>Note</th><th>By</th><?= can('balance') ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($rows as $r): if ($r['status'] === 'rejected') continue; ?>
      <tr><td><?= $date($r['entry_date']) ?></td><td><?= h(ucfirst($r['kind'])) ?></td>
        <td class="right <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>"><strong><?= money($r['amount']) ?></strong></td><td><?= h($r['note']) ?></td><td><?= h($r['name']) ?></td>
        <?php if (can('balance')): ?><td><form method="post" class="inline" onsubmit="return confirm('Delete this entry? The balance changes back.')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="no">delete</button></form></td><?php endif ?></tr>
    <?php endforeach;
    if (!$rows): ?><tr><td colspan="6" class="empty">No entries yet. Start with the opening balance.</td></tr><?php endif ?></tbody></table></div>
    <?php foot();

// ── statement: every money movement with running balance ─────────────────
case 'statement':
    need('statement');
    $from = date_or_null($_GET['from'] ?? '') ?? date('Y-m-01');
    $to   = date_or_null($_GET['to'] ?? '') ?? date('Y-m-d');
    $open = q('SELECT COALESCE(SUM(amount), 0) FROM statement WHERE entry_date < ?', [$from])->fetchColumn();
    $rows = q('SELECT entry_date AS date, kind, detail, ref_id, amount,
                      CAST(? AS DECIMAL(14,2)) + SUM(amount) OVER (ORDER BY entry_date, kind <> \'opening\', kind, row_id ROWS UNBOUNDED PRECEDING) AS balance
               FROM statement WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, kind <> \'opening\', kind, row_id', [$open, $from, $to])->fetchAll();
    if (isset($_GET['csv'])) csv('statement', $rows);
    $in  = array_sum(array_filter(array_column($rows, 'amount'), fn($v) => $v > 0));
    $out = array_sum(array_filter(array_column($rows, 'amount'), fn($v) => $v < 0));
    head('Statement', '<a href="?p=statement&csv=1&from=' . h($from) . '&to=' . h($to) . '" role="button" class="secondary">⬇ Excel (CSV)</a>'); ?>
    <form class="filters panel"><input type="hidden" name="p" value="statement">
      <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
      <button>Show</button>
    </form>
    <div class="stats">
      <article class="stat blue" data-icon="📂"><small>Opening</small><div class="big"><?= money($open) ?></div></article>
      <article class="stat green" data-icon="⬇️"><small>Money in</small><div class="big pos"><?= money($in) ?></div></article>
      <article class="stat red" data-icon="⬆️"><small>Money out</small><div class="big neg"><?= money($out) ?></div></article>
      <article class="stat" data-icon="🏦"><small>Closing</small><div class="big"><?= money($rows ? end($rows)['balance'] : $open) ?></div></article>
    </div>
    <div class="tbl"><table><thead><tr><th>Date</th><th>Type</th><th>Detail</th><th class="right">Amount</th><th class="right">Balance</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= $date($r['date']) ?></td><td><?= h(ucfirst($r['kind'])) ?></td>
        <td><?= $r['kind'] === 'payment' ? '<a href="?p=payment&id=' . (int) $r['ref_id'] . '">' . h($r['detail']) . '</a>' : h($r['detail']) ?></td>
        <td class="right <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['amount']) ?></td><td class="right"><strong><?= money($r['balance']) ?></strong></td></tr>
    <?php endforeach;
    if (!$rows): ?><tr><td colspan="5" class="empty">No money movements in this period.</td></tr><?php endif ?></tbody></table></div>
    <?php foot();

// ── import the Excel invoice register ─────────────────────────────────────
case 'import':
    need('import');
    $res = null;
    if ($post) {
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) fail('Upload failed. Max size is ' . ini_get('upload_max_filesize') . '.');
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') fail('Please upload an .xlsx file (in Excel: File → Save As → Excel Workbook).');
        $res = import_register($file['tmp_name'], (int) me()['id']);
    }
    head('Import from Excel'); ?>
    <?php if ($res): ?>
      <div class="flash">✅ Imported <strong><?= $res['added'] ?></strong> invoices<?= $res['skipped'] ? ", skipped {$res['skipped']} already in the app" : '' ?>.
        <?= $res['parties'] ?> new parties and <?= $res['products'] ?> new products added. <a href="?p=payments">Open invoice register →</a></div>
      <?php if ($res['errors']): ?><div class="error-box">⚠️ <?= count($res['errors']) ?> rows were not imported:<br><?= implode('<br>', array_map('h', $res['errors'])) ?></div><br><?php endif ?>
    <?php endif ?>
    <div class="panel">
      <p>Upload your <strong>Inward Invoice &amp; Payment Register</strong> (.xlsx). The sheet named <em>Invoice Register</em> is read, or the first sheet if there isn't one.
        Columns are found by their headings: <em>Invoice Date, Invoice No., Party Name, Invoice Amount, Amount Paid, Due Date, Remarks</em>.</p>
      <ul>
        <li>Every new party and product/remark is added to the dropdown lists.</li>
        <li>Imported invoices are marked <strong>approved</strong>; <em>Amount Paid</em> is recorded as a payment made before the app, so it does <strong>not</strong> change the current balance.</li>
        <li>Bill type is guessed: GST when there is an invoice number, cash when it is empty or “-”. You can edit any invoice afterwards.</li>
        <li>Uploading the same file again skips invoices that are already in the app.</li>
      </ul>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="row"><input type="file" name="file" accept=".xlsx" required><button>📥 Import</button></div></form>
    </div>
    <?php foot();

// ── users (developer manages logins) ──────────────────────────────────────
case 'users':
    need('see_users');
    if ($post) {
        need('users');
        $id = (int) ($_POST['id'] ?? 0);
        if ($a === 'add') {
            $email = filter_var(req('email'), FILTER_VALIDATE_EMAIL) ?: fail('Invalid email');
            if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('That email already has an account');
            if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
            q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
              [req('name'), $email, password_hash($_POST['password'], PASSWORD_DEFAULT), enum_in($_POST['role'] ?? '', array_keys(ROLES))]);
        } elseif ($a === 'role') {
            if ($id === (int) me()['id']) fail('You cannot change your own role');
            q('UPDATE users SET role = ? WHERE id = ?', [enum_in($_POST['role'] ?? '', array_keys(ROLES)), $id]);
        } elseif ($a === 'password') {
            if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
        }
        go('?p=users', 'Saved');
    }
    $role_select = fn(string $cur = '') => '<select name="role"' . ($cur ? ' onchange="this.form.submit()"' : '') . '>'
        . implode('', array_map(fn($r, $label) => '<option value="' . $r . '"' . ($r === $cur ? ' selected' : '') . '>' . h($label) . '</option>', array_keys(ROLES), ROLES)) . '</select>';
    $edit = can('users');
    head('Users');
    if ($edit): ?>
    <details class="panel"><summary>＋ Add user</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="add">
        <div class="grid"><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label></div>
        <div class="grid"><label>Password<input name="password" type="password" placeholder="At least 8 characters" minlength="8" required></label><label>Role<?= $role_select() ?></label></div>
        <button>Add user</button></form>
    </details>
    <?php endif ?>
    <div class="tbl"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><?= $edit ? '<th>Reset password</th>' : '' ?></tr></thead><tbody>
    <?php foreach (q('SELECT * FROM users ORDER BY name')->fetchAll() as $u): ?>
      <tr><td><strong><?= h($u['name']) ?></strong></td><td><?= h($u['email']) ?></td>
        <td><?php if (!$edit || $u['id'] == me()['id']): ?><?= h(ROLES[$u['role']]) ?><?= $u['id'] == me()['id'] ? ' (you)' : '' ?><?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>"><?= $role_select($u['role']) ?></form>
        <?php endif ?></td>
        <?php if ($edit): ?>
        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="a" value="password"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <input name="password" type="password" placeholder="New password" minlength="8" required><button class="secondary">Set</button></form></td>
        <?php endif ?></tr>
    <?php endforeach ?></tbody></table></div>
    <?php if ($edit): ?><p><small>To remove someone's access, set a new password they don't know.</small></p><?php endif;
    foot();

default:
    fail('Page not found', 404);
}
