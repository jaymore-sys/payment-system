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
if (me() && !can('view') && in_array($p, ['home', 'payments', 'payment', 'file', 'msgs', 'chat', 'receivables', 'statement'], true)) {
    if ($p === 'msgs') { http_response_code(403); exit; }
    go('?p=users');
}

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
      <input name="name" placeholder="Your name" required>
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password (min 8 characters)" minlength="8" required>
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
    head('Login'); ?>
    <form method="post" style="max-width:420px"><?= csrf_field() ?>
      <?php if ($err): ?><p class="neg"><?= h($err) ?></p><?php endif ?>
      <input name="email" type="email" placeholder="Email" required autofocus>
      <input name="password" type="password" placeholder="Password" required>
      <button>Login</button>
    </form>
    <?php foot();

case 'logout':
    session_destroy();
    go('?p=login');

// ── dashboard ─────────────────────────────────────────────────────────────
case 'home':
    $bal  = q('SELECT balance FROM current_balance')->fetchColumn();
    $dues = q('SELECT * FROM upcoming_dues ORDER BY due_date')->fetchAll();
    $pend = q("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $mon  = q("SELECT (SELECT COALESCE(SUM(amount), 0) FROM receivables WHERE received_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS received,
                      (SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid")->fetch();
    [$t] = summarize(q('SELECT * FROM payment_register')->fetchAll());
    $popup = $dues && empty($_SESSION['dues_seen']);
    if ($popup) $_SESSION['dues_seen'] = true;
    head('Dashboard'); ?>
    <div class="cards">
      <article><small>Current balance</small><div class="big <?= $bal < 0 ? 'neg' : '' ?>"><?= money($bal) ?></div></article>
      <article><small>Total outstanding</small><div class="big"><a href="?p=outstanding"><?= money($t['outstanding']) ?></a></div></article>
      <article><small>Overdue outstanding</small><div class="big neg"><?= money($t['overdue']) ?></div></article>
      <article><small>Awaiting approval</small><div class="big"><a href="?p=payments&status=pending"><?= (int) $pend ?></a></div></article>
    </div>
    <?php if (can('request')): ?><p><a href="?p=payment" role="button">+ New invoice</a> <a href="?p=receivables" role="button" class="secondary">+ Receipt</a></p><?php endif ?>
    <div class="grid">
      <article><header><strong>Payables</strong></header><table>
        <tr><td>Total invoiced</td><td class="right"><?= money($t['invoiced']) ?></td></tr>
        <tr><td>Total paid</td><td class="right"><?= money($t['paid']) ?></td></tr>
        <tr><td>Total outstanding</td><td class="right"><strong><?= money($t['outstanding']) ?></strong></td></tr>
        <tr><td>Invoices with balance</td><td class="right"><?= $t['with_balance'] ?></td></tr>
        <tr><td>Fully paid invoices</td><td class="right"><?= $t['fully_paid'] ?></td></tr>
        <tr><td>Paid this month</td><td class="right neg"><?= money($mon['paid']) ?></td></tr>
        <tr><td>Received this month</td><td class="right pos"><?= money($mon['received']) ?></td></tr>
      </table></article>
      <article><header><strong>Outstanding ageing</strong></header><table>
        <?php foreach (AGEING as $k => $label): ?>
          <tr><td><a href="?p=payments&age=<?= $k ?>"><?= h($label) ?></a></td><td class="right <?= $k !== 'not_due' && $t[$k] > 0 ? 'neg' : '' ?>"><?= money($t[$k]) ?></td></tr>
        <?php endforeach ?>
      </table></article>
    </div>
    <h3>Due within 7 days / overdue</h3>
    <?php $due_table = function () use ($dues) { ?>
      <div class="overflow-auto"><table class="striped"><thead><tr><th>Party</th><th>Invoice</th><th class="right">Outstanding</th><th>Due</th><th>Approval</th></tr></thead><tbody>
      <?php foreach ($dues as $d): ?>
        <tr><td><a href="?p=payment&id=<?= $d['id'] ?>"><?= h($d['party']) ?></a></td><td><?= h($d['invoice_no'] ?: $d['products']) ?></td>
          <td class="right"><?= money($d['outstanding']) ?></td>
          <td class="<?= $d['days_left'] < 0 ? 'neg' : '' ?>"><?= h(date('d M Y', strtotime($d['due_date']))) ?>
            (<?= $d['days_left'] < 0 ? abs($d['days_left']) . ' days overdue' : ($d['days_left'] == 0 ? 'today' : "in {$d['days_left']} days") ?>)</td>
          <td><?= badge($d['status']) ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?php };
    $dues ? $due_table() : print('<p>Nothing due. 🎉</p>');
    if ($popup): ?>
      <dialog open><article>
        <header><strong>⏰ Payment reminders</strong></header>
        <?php $due_table() ?>
        <footer><button onclick="this.closest('dialog').close()">OK</button></footer>
      </article></dialog>
    <?php endif;
    foot();

// ── invoice register (list + filters + Excel) ─────────────────────────────
case 'payments':
    $by = ($_GET['by'] ?? '') === 'invoice_date' ? 'invoice_date' : 'due_date';
    $w = ['1=1']; $args = []; $desc = [];
    if ($d = date_or_null($_GET['from'] ?? '')) { $w[] = "$by >= ?"; $args[] = $d; $desc[] = "$by from $d"; }
    if ($d = date_or_null($_GET['to'] ?? ''))   { $w[] = "$by <= ?"; $args[] = $d; $desc[] = "$by to $d"; }
    if (in_array($_GET['status'] ?? '', STATUSES, true)) { $w[] = 'status = ?'; $args[] = $_GET['status']; $desc[] = $_GET['status']; }
    if (in_array($_GET['pay'] ?? '', PAY_STATES, true)) { $w[] = 'pay_status = ?'; $args[] = $_GET['pay']; $desc[] = $_GET['pay']; }
    if (in_array($_GET['bill'] ?? '', ['gst', 'cash'], true)) { $w[] = 'bill_type = ?'; $args[] = $_GET['bill']; $desc[] = strtoupper($_GET['bill']) . ' bills'; }
    if (($s = trim($_GET['party'] ?? '')) !== '') { $w[] = 'party LIKE ?'; $args[] = "%$s%"; $desc[] = "party: $s"; }
    $ages = ['not_due' => 'outstanding > 0 AND days_overdue = 0', 'd1_30' => 'days_overdue BETWEEN 1 AND 30', 'd31_60' => 'days_overdue BETWEEN 31 AND 60',
             'd61_90' => 'days_overdue BETWEEN 61 AND 90', 'd90' => 'days_overdue > 90'];
    if (isset($ages[$_GET['age'] ?? ''])) { $w[] = $ages[$_GET['age']] . " AND status <> 'rejected'"; $desc[] = AGEING[$_GET['age']]; }
    // ponytail: no paging; add LIMIT/OFFSET when the register passes a few thousand invoices
    $rows = q('SELECT * FROM payment_register WHERE ' . implode(' AND ', $w) . ' ORDER BY due_date, id', $args)->fetchAll();
    if (isset($_GET['xlsx'])) register_workbook($rows, implode(', ', $desc));
    [$t] = summarize($rows);
    $sel = fn($k, $v) => ($_GET[$k] ?? '') === $v ? 'selected' : '';
    head('Invoice register'); ?>
    <form class="filters"><input type="hidden" name="p" value="payments">
      <label>Date of<select name="by"><option value="due_date">Due date</option><option value="invoice_date" <?= $sel('by', 'invoice_date') ?>>Invoice date</option></select></label>
      <label>From<input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
      <label>Payment<select name="pay"><option value="">All</option>
        <?php foreach (PAY_STATES as $st): ?><option <?= $sel('pay', $st) ?>><?= $st ?></option><?php endforeach ?></select></label>
      <label>Approval<select name="status"><option value="">All</option>
        <?php foreach (STATUSES as $st): ?><option <?= $sel('status', $st) ?>><?= $st ?></option><?php endforeach ?></select></label>
      <label>Overdue<select name="age"><option value="">All</option>
        <?php foreach (AGEING as $k => $label): ?><option value="<?= $k ?>" <?= $sel('age', $k) ?>><?= h($label) ?></option><?php endforeach ?></select></label>
      <label>Bill<select name="bill"><option value="">All</option><option value="gst" <?= $sel('bill', 'gst') ?>>GST</option><option value="cash" <?= $sel('bill', 'cash') ?>>Cash</option></select></label>
      <label>Party<input name="party" value="<?= h($_GET['party'] ?? '') ?>"></label>
      <button>Filter</button>
      <button name="xlsx" value="1" class="secondary">⬇ Excel</button>
    </form>
    <?php if (can('request')): ?><p><a href="?p=payment" role="button">+ New invoice</a></p><?php endif ?>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Invoice date</th><th>Invoice no.</th><th>Party</th><th class="right">Amount</th><th class="right">Paid</th>
      <th class="right">Outstanding</th><th>Due</th><th class="right">Days overdue</th><th>Payment</th><th>Approval</th><th>Remarks</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= $r['invoice_date'] ? h(date('d M Y', strtotime($r['invoice_date']))) : '' ?></td>
        <td><a href="?p=payment&id=<?= $r['id'] ?>"><?= h($r['invoice_no'] ?: '#' . $r['id']) ?></a></td>
        <td><a href="?p=payment&id=<?= $r['id'] ?>"><?= h($r['party']) ?></a></td>
        <td class="right"><?= money($r['amount']) ?></td><td class="right"><?= money($r['paid']) ?></td><td class="right"><strong><?= money($r['outstanding']) ?></strong></td>
        <td><?= h(date('d M Y', strtotime($r['due_date']))) ?></td><td class="right <?= $r['days_overdue'] > 0 ? 'neg' : '' ?>"><?= (int) $r['days_overdue'] ?></td>
        <td><?= badge($r['pay_status']) ?></td><td><?= badge($r['status']) ?></td><td><?= h(mb_strimwidth($r['products'], 0, 40, '…')) ?></td></tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><th colspan="3"><?= count($rows) ?> invoices (totals exclude rejected)</th><th class="right"><?= money($t['invoiced']) ?></th>
      <th class="right"><?= money($t['paid']) ?></th><th class="right"><?= money($t['outstanding']) ?></th><th colspan="5"></th></tr></tfoot></table></div>
    <?php foot();

// ── party-wise outstanding ────────────────────────────────────────────────
case 'outstanding':
    [$t, $parties] = summarize(q('SELECT * FROM payment_register')->fetchAll());
    head('Party-wise outstanding'); ?>
    <p><a href="?p=payments&xlsx=1" role="button" class="secondary">⬇ Excel (register + party outstanding + dashboard)</a></p>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Party</th><th class="right">Invoices</th><th class="right">Total invoiced</th><th class="right">Paid</th>
      <th class="right">Outstanding</th><th class="right">Overdue amount</th><th class="right">Oldest days overdue</th></tr></thead><tbody>
    <?php foreach ($parties as $name => $p): ?>
      <tr><td><a href="?p=payments&party=<?= urlencode($name) ?>"><?= h($name) ?></a></td><td class="right"><?= $p['invoices'] ?></td>
        <td class="right"><?= money($p['invoiced']) ?></td><td class="right"><?= money($p['paid']) ?></td><td class="right"><strong><?= money($p['outstanding']) ?></strong></td>
        <td class="right <?= $p['overdue'] > 0 ? 'neg' : '' ?>"><?= money($p['overdue']) ?></td><td class="right"><?= $p['oldest'] ?></td></tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><th><?= count($parties) ?> parties</th><th></th><th class="right"><?= money($t['invoiced']) ?></th><th class="right"><?= money($t['paid']) ?></th>
      <th class="right"><?= money($t['outstanding']) ?></th><th class="right"><?= money($t['overdue']) ?></th><th></th></tr></tfoot></table></div>
    <?php foot();

// ── one invoice: create / edit / approve / pay (part or full) / follow-up / files / chat ─
case 'payment':
    $id = (int) ($_GET['id'] ?? 0);
    $pm = $id ? (q('SELECT r.*, u.name AS creator, v.name AS reviewer FROM payment_register r JOIN users u ON u.id = r.created_by
                    LEFT JOIN users v ON v.id = r.reviewed_by WHERE r.id = ?', [$id])->fetch() ?: fail('Invoice not found', 404)) : null;
    if (!$pm) need('request');
    // administrator can edit until fully paid; any edit goes back for approval
    $editable = can('request') && (!$pm || $pm['outstanding'] > 0);
    $uid = me()['id'];

    if ($post) {
        if (!$pm && $a !== 'save') fail('Invoice not found', 404);
        $changed = fn(PDOStatement $st) => $st->rowCount() ?: fail('Not allowed for an invoice that is ' . $pm['status']);
        switch ($a) {
        case 'save':
            if (!$editable) fail('This invoice cannot be edited by you', 403);
            $amount = amount_in($_POST['amount'] ?? '');
            if ($pm && paise($amount) < paise($pm['paid'])) fail('Amount cannot be less than what is already paid (' . money($pm['paid']) . ')');
            $f = [party_id(req('party')), trim($_POST['invoice_no'] ?? '') ?: null, date_in($_POST['invoice_date'] ?? ''), req('products'),
                  enum_in($_POST['bill_type'] ?? '', ['gst', 'cash']), $amount, date_in($_POST['due_date'] ?? '')];
            if (!$pm) {
                q('INSERT INTO payments (party_id, invoice_no, invoice_date, products, bill_type, amount, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [...$f, $uid]);
                $id = (int) db()->lastInsertId();
            } else {
                q("UPDATE payments SET party_id = ?, invoice_no = ?, invoice_date = ?, products = ?, bill_type = ?, amount = ?, due_date = ?,
                   status = 'pending', reviewed_by = NULL, reject_reason = NULL WHERE id = ?", [...$f, $id]);
            }
            break;
        case 'approve':
            need('approve');
            $changed(q("UPDATE payments SET status = 'approved', reviewed_by = ?, reject_reason = NULL WHERE id = ? AND status IN ('pending', 'rejected')", [$uid, $id]));
            break;
        case 'reject':
            need('approve');
            if ($pm['paid'] > 0) fail('Payments were already made against this invoice, so it cannot be rejected.');
            $changed(q("UPDATE payments SET status = 'rejected', reviewed_by = ?, reject_reason = ? WHERE id = ? AND status IN ('pending', 'approved')", [$uid, req('reason'), $id]));
            break;
        case 'pay': // record a full or part payment
            need('pay');
            $amt = amount_in($_POST['amount'] ?? '');
            $on = date_in($_POST['paid_on'] ?? '');
            db()->beginTransaction(); // lock the invoice so two clicks can't overpay it
            $inv = q('SELECT status, amount FROM payments WHERE id = ? FOR UPDATE', [$id])->fetch();
            $paid = q('SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE payment_id = ?', [$id])->fetchColumn();
            if ($inv['status'] !== 'approved') fail('The invoice must be approved before paying.');
            if (paise($paid) + paise($amt) > paise($inv['amount'])) fail('That is more than the outstanding ' . money(($inv['amount'] * 100 - paise($paid)) / 100) . '.');
            q('INSERT INTO payouts (payment_id, amount, paid_on, note, created_by) VALUES (?, ?, ?, ?, ?)', [$id, $amt, $on, trim($_POST['note'] ?? '') ?: null, $uid]);
            db()->commit();
            break;
        case 'unpay':
            need('manage');
            $changed(q('DELETE FROM payouts WHERE id = ? AND payment_id = ?', [(int) ($_POST['payout'] ?? 0), $id]));
            break;
        case 'delete':
            need('manage');
            foreach (q('SELECT file_path FROM attachments WHERE payment_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(upload_dir() . '/' . basename($fp));
            q('DELETE FROM payments WHERE id = ?', [$id]);
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
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) fail('Upload failed. Max size is ' . ini_get('upload_max_filesize') . '.');
            if ($file['size'] > 10 * 1024 * 1024) fail('Max file size is 10 MB');
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!isset(FILE_TYPES[$ext])) fail('Allowed files: ' . implode(', ', array_keys(FILE_TYPES)));
            if (!is_dir(upload_dir())) mkdir(upload_dir(), 0750, true);
            $name = bin2hex(random_bytes(16)) . ".$ext";
            if (!move_uploaded_file($file['tmp_name'], upload_dir() . "/$name")) fail('Could not save the file');
            q('INSERT INTO attachments (payment_id, file_path, file_name, uploaded_by) VALUES (?, ?, ?, ?)', [$id, $name, mb_substr($file['name'], 0, 255), $uid]);
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

    head($pm ? 'Invoice ' . ($pm['invoice_no'] ?: "#$id") : 'New invoice');
    if ($pm):
        $payouts = q('SELECT po.*, u.name FROM payouts po JOIN users u ON u.id = po.created_by WHERE po.payment_id = ? ORDER BY po.paid_on, po.id', [$id])->fetchAll(); ?>
      <p><?= badge($pm['pay_status']) ?> <?= badge($pm['status']) ?> &nbsp; <strong><?= h($pm['party']) ?></strong> · <?= strtoupper($pm['bill_type']) ?> bill
        <?= $pm['invoice_date'] ? ' · dated ' . h(date('d M Y', strtotime($pm['invoice_date']))) : '' ?> · <?= h($pm['products']) ?><br>
        <small>Added by <?= h($pm['creator']) ?> on <?= h(date('d M Y', strtotime($pm['created_at']))) ?><?= $pm['reviewer'] ? ' · reviewed by ' . h($pm['reviewer']) : '' ?></small></p>
      <div class="cards">
        <article><small>Invoice amount</small><div class="big"><?= money($pm['amount']) ?></div></article>
        <article><small>Paid</small><div class="big pos"><?= money($pm['paid']) ?></div></article>
        <article><small>Outstanding</small><div class="big <?= $pm['outstanding'] > 0 ? 'neg' : '' ?>"><?= money($pm['outstanding']) ?></div></article>
        <article><small>Due date</small><div class="big"><?= h(date('d M Y', strtotime($pm['due_date']))) ?></div>
          <?php if ($pm['days_overdue'] > 0): ?><small class="neg"><?= (int) $pm['days_overdue'] ?> days overdue</small><?php endif ?></article>
      </div>
      <?php if ($pm['status'] === 'rejected'): ?><p class="neg"><strong>Rejected:</strong> <?= h($pm['reject_reason']) ?></p><?php endif ?>

      <?php if (can('approve') && $pm['outstanding'] > 0 && ($pm['status'] !== 'approved' || $pm['paid'] == 0)): ?>
        <article><header><strong>Approval</strong></header>
          <?php if ($pm['status'] !== 'approved'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="a" value="approve"><button>✅ Yes, approve</button></form>
          <?php endif ?>
          <?php if ($pm['paid'] == 0 && $pm['status'] !== 'rejected'): ?>
          <form method="post" style="margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="a" value="reject">
            <fieldset role="group"><input name="reason" placeholder="Reason for rejecting" required><button class="secondary">❌ No, reject</button></fieldset></form>
          <?php endif ?>
        </article>
      <?php endif ?>

      <h3>💸 Payments made</h3>
      <?php if ($payouts): ?>
        <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th class="right">Amount</th><th>Note</th><th>By</th><?= can('manage') ? '<th></th>' : '' ?></tr></thead><tbody>
        <?php foreach ($payouts as $po): ?>
          <tr><td><?= h(date('d M Y', strtotime($po['paid_on']))) ?></td><td class="right"><?= money($po['amount']) ?></td><td><?= h($po['note']) ?></td><td><?= h($po['name']) ?></td>
            <?php if (can('manage')): ?><td><form method="post" class="inline" onsubmit="return confirm('Remove this payment? The amount goes back into the balance.')"><?= csrf_field() ?>
              <input type="hidden" name="a" value="unpay"><input type="hidden" name="payout" value="<?= $po['id'] ?>"><button class="secondary outline">remove</button></form></td><?php endif ?></tr>
        <?php endforeach ?></tbody></table></div>
      <?php else: ?><p>No payments yet.</p><?php endif ?>
      <?php if ($pm['outstanding'] > 0 && can('pay')): ?>
        <?php if ($pm['status'] === 'approved'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="pay">
            <div class="grid">
              <label>Amount (₹) — full or part<input name="amount" inputmode="decimal" value="<?= h($pm['outstanding']) ?>" required></label>
              <label>Paid on<input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" required></label>
              <label>Note<input name="note" placeholder="e.g. NEFT ref / cheque no."></label>
            </div>
            <button>💸 Record payment</button></form>
        <?php else: ?><p><small>Payments can be recorded once the admin approves this invoice.</small></p><?php endif ?>
      <?php endif ?>

      <?php if ($editable): ?>
        <details><summary>📅 Change due date / add follow-up</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="due">
            <input type="date" name="due_date" value="<?= h($pm['due_date']) ?>" required>
            <input name="note" placeholder="Follow-up note (e.g. party asked for 1 week more)">
            <button>Update due date</button></form>
        </details>
      <?php endif ?>
    <?php endif;

    if ($editable): ?>
      <details <?= $pm ? '' : 'open' ?>><summary><?= $pm ? '✏️ Edit details' : 'Details' ?></summary>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save">
          <label>Party name / number<input name="party" list="parties" value="<?= h($pm['party'] ?? '') ?>" required></label><?= parties_datalist() ?>
          <div class="grid">
            <label>Invoice no.<input name="invoice_no" value="<?= h($pm['invoice_no'] ?? '') ?>" placeholder="leave empty if none"></label>
            <label>Invoice date<input type="date" name="invoice_date" value="<?= h($pm['invoice_date'] ?? date('Y-m-d')) ?>" required></label>
            <label>Bill type<select name="bill_type"><option value="gst">GST bill</option><option value="cash" <?= ($pm['bill_type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash bill</option></select></label>
          </div>
          <div class="grid">
            <label>Invoice amount (₹)<input name="amount" inputmode="decimal" value="<?= h($pm['amount'] ?? '') ?>" required></label>
            <label>Due date<input type="date" name="due_date" value="<?= h($pm['due_date'] ?? '') ?>" required></label>
          </div>
          <label>Products / remarks<input name="products" value="<?= h($pm['products'] ?? '') ?>" placeholder="e.g. Unit 1 Preform, Transport, Shrink" required></label>
          <?php if ($pm): ?><small>Saving changes sends this invoice back for approval.</small><?php endif ?>
          <button><?= $pm ? 'Save changes' : 'Create invoice' ?></button>
        </form>
      </details>
    <?php endif;

    if ($pm):
        $files = q('SELECT * FROM attachments WHERE payment_id = ? ORDER BY id', [$id])->fetchAll();
        $msgs = q('SELECT m.*, u.name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.payment_id = ? ORDER BY m.id', [$id])->fetchAll(); ?>
      <h3>📎 Invoice / files</h3>
      <ul><?php foreach ($files as $f): ?>
        <li><a href="?p=file&id=<?= $f['id'] ?>" target="_blank"><?= h($f['file_name']) ?></a>
          <?php if ($f['uploaded_by'] == $uid || can('manage')): ?>
            <form method="post" class="inline" onsubmit="return confirm('Remove file?')"><?= csrf_field() ?><input type="hidden" name="a" value="unattach"><input type="hidden" name="att" value="<?= $f['id'] ?>"><button class="secondary outline">remove</button></form>
          <?php endif ?></li>
      <?php endforeach ?></ul>
      <?php if (can('files')): ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="upload">
        <fieldset role="group"><input type="file" name="file" accept="image/*,.pdf,.xls,.xlsx" required><button>Upload</button></fieldset></form>
      <?php endif ?>

      <h3>💬 Discussion & follow-ups</h3>
      <div id="chat" data-payment="<?= $id ?>" data-after="<?= $msgs ? end($msgs)['id'] : 0 ?>"><?php foreach ($msgs as $m) echo msg_html($m) ?></div>
      <?php if (can('chat')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="msg">
        <textarea name="body" placeholder="Write a message…" required></textarea>
        <fieldset role="group"><select name="kind"><option value="chat">Chat</option><option value="followup">Follow-up note</option></select><button>Send</button></fieldset></form>
      <?php endif ?>

      <?php if (can('manage')): ?>
        <form method="post" onsubmit="return confirm('Delete this invoice with its payments, files and chat?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete">
          <button class="secondary outline">🗑 Delete invoice</button></form>
      <?php endif;
    endif;
    foot();

case 'file':
    $f = q('SELECT * FROM attachments WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch() ?: fail('File not found', 404);
    $path = upload_dir() . '/' . basename($f['file_path']);
    if (!is_file($path)) fail('File missing on server', 404);
    header('Content-Type: ' . FILE_TYPES[pathinfo($path, PATHINFO_EXTENSION)]);
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($f['file_name']));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;

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
    <div id="chat" data-payment="0" data-after="<?= $msgs ? end($msgs)['id'] : 0 ?>"><?php foreach ($msgs as $m) echo msg_html($m) ?></div>
    <form method="post"><?= csrf_field() ?>
      <fieldset role="group"><input name="body" placeholder="Message to everyone…" required autocomplete="off"><button>Send</button></fieldset></form>
    <?php if ($recent): ?>
      <h3>Latest on payments</h3>
      <ul><?php foreach ($recent as $m): ?>
        <li><a href="?p=payment&id=<?= $m['payment_id'] ?>">#<?= $m['payment_id'] ?> <?= h($m['party']) ?></a> — <b><?= h($m['name']) ?>:</b> <?= h(mb_strimwidth($m['body'], 0, 90, '…')) ?></li>
      <?php endforeach ?></ul>
    <?php endif;
    foot();

// ── receivables ───────────────────────────────────────────────────────────
case 'receivables':
    if ($post) {
        if ($a === 'delete') {
            need('manage');
            q('DELETE FROM receivables WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            go('?p=receivables', 'Receipt deleted');
        }
        need('request');
        q('INSERT INTO receivables (party_id, amount, received_on, against, note, created_by) VALUES (?, ?, ?, ?, ?, ?)', [
            party_id(req('party'), isset($_POST['gst']), $_POST['gstin'] ?? ''), amount_in($_POST['amount'] ?? ''), date_in($_POST['received_on'] ?? ''),
            enum_in($_POST['against'] ?? '', ['sales', 'capital']), trim($_POST['note'] ?? '') ?: null, me()['id']]);
        go('?p=receivables', 'Receipt added');
    }
    $w = ['1=1']; $args = [];
    if ($d = date_or_null($_GET['from'] ?? '')) { $w[] = 'r.received_on >= ?'; $args[] = $d; }
    if ($d = date_or_null($_GET['to'] ?? ''))   { $w[] = 'r.received_on <= ?'; $args[] = $d; }
    if (in_array($_GET['against'] ?? '', ['sales', 'capital'], true)) { $w[] = 'r.against = ?'; $args[] = $_GET['against']; }
    if (in_array($_GET['gst'] ?? '', ['0', '1'], true)) { $w[] = 'p.gst_registered = ?'; $args[] = $_GET['gst']; }
    if (($s = trim($_GET['party'] ?? '')) !== '') { $w[] = 'p.name LIKE ?'; $args[] = "%$s%"; }
    $rows = q('SELECT r.id, p.name AS party, IF(p.gst_registered, \'Yes\', \'No\') AS gst_registered, p.gstin, r.amount, r.received_on, r.against, r.note
               FROM receivables r JOIN parties p ON p.id = r.party_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.received_on DESC, r.id DESC', $args)->fetchAll();
    if (isset($_GET['csv'])) csv('receivables', $rows);
    head('Receivables');
    if (can('request')): ?>
    <details open><summary>+ Add receipt</summary>
      <form method="post"><?= csrf_field() ?>
        <div class="grid">
          <label>Party name<input name="party" list="parties" required></label><?= parties_datalist() ?>
          <label>Amount (₹)<input name="amount" inputmode="decimal" required></label>
          <label>Date<input type="date" name="received_on" value="<?= date('Y-m-d') ?>" required></label>
        </div>
        <div class="grid">
          <label>Against<select name="against"><option value="sales">Sales</option><option value="capital">Capital</option></select></label>
          <label><input type="checkbox" name="gst" value="1"> Party is GST registered</label>
          <label>GSTIN (optional)<input name="gstin" maxlength="15"></label>
        </div>
        <input name="note" placeholder="Note (optional)">
        <button>Add receipt</button>
      </form>
    </details>
    <?php endif ?>
    <form class="filters"><input type="hidden" name="p" value="receivables">
      <label>From<input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
      <label>Against<select name="against"><option value="">All</option>
        <option value="sales" <?= ($_GET['against'] ?? '') === 'sales' ? 'selected' : '' ?>>Sales</option><option value="capital" <?= ($_GET['against'] ?? '') === 'capital' ? 'selected' : '' ?>>Capital</option></select></label>
      <label>GST registered<select name="gst"><option value="">All</option>
        <option value="1" <?= ($_GET['gst'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= ($_GET['gst'] ?? '') === '0' ? 'selected' : '' ?>>No</option></select></label>
      <label>Party<input name="party" value="<?= h($_GET['party'] ?? '') ?>"></label>
      <button>Filter</button>
      <button name="csv" value="1" class="secondary">Excel (CSV)</button>
    </form>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th>Party</th><th>GST reg.</th><th>Against</th><th class="right">Amount</th><th>Note</th><?= can('manage') ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h(date('d M Y', strtotime($r['received_on']))) ?></td><td><?= h($r['party']) ?></td><td><?= $r['gst_registered'] ?><?= $r['gstin'] ? '<br><small>' . h($r['gstin']) . '</small>' : '' ?></td>
        <td><?= h(ucfirst($r['against'])) ?></td><td class="right pos"><?= money($r['amount']) ?></td><td><?= h($r['note']) ?></td>
        <?php if (can('manage')): ?><td><form method="post" class="inline" onsubmit="return confirm('Delete this receipt?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="secondary outline">delete</button></form></td><?php endif ?></tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><th colspan="4"><?= count($rows) ?> receipts</th><th class="right"><?= money(array_sum(array_column($rows, 'amount'))) ?></th><th colspan="2"></th></tr></tfoot></table></div>
    <?php foot();

// ── admin: opening balance, adjustments, expenses ─────────────────────────
case 'cash':
    need('see_cash');
    if ($post) {
        need('manage');
        if ($a === 'delete') {
            q('DELETE FROM cash_entries WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            go('?p=cash', 'Entry deleted');
        }
        $kind = enum_in($_POST['kind'] ?? '', ['opening', 'adjustment', 'expense']);
        $amt = amount_in($_POST['amount'] ?? '', true);
        if ($kind === 'expense') $amt = '-' . ltrim($amt, '-');
        q('INSERT INTO cash_entries (entry_date, amount, kind, note, created_by) VALUES (?, ?, ?, ?, ?)',
          [date_in($_POST['entry_date'] ?? ''), $amt, $kind, trim($_POST['note'] ?? '') ?: null, me()['id']]);
        go('?p=cash', 'Entry added');
    }
    $rows = q('SELECT c.*, u.name FROM cash_entries c JOIN users u ON u.id = c.created_by ORDER BY c.entry_date DESC, c.id DESC')->fetchAll();
    head('Balance entries'); ?>
    <p>Current balance: <strong><?= money(q('SELECT balance FROM current_balance')->fetchColumn()) ?></strong>.
      Receipts and paid payments change it automatically; this page holds the opening balance, corrections and other costs.</p>
    <?php if (can('manage')): ?>
    <form method="post"><?= csrf_field() ?>
      <div class="grid">
        <label>Type<select name="kind"><option value="opening">Opening balance</option><option value="expense">Expense / other cost</option><option value="adjustment">Correction (+ or −)</option></select></label>
        <label>Amount (₹)<input name="amount" inputmode="decimal" placeholder="Expense: enter positive" required></label>
        <label>Date<input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></label>
      </div>
      <input name="note" placeholder="Note (e.g. office rent, bank charges)">
      <button>Add entry</button>
    </form>
    <?php endif ?>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th>Type</th><th class="right">Amount</th><th>Note</th><th>By</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h(date('d M Y', strtotime($r['entry_date']))) ?></td><td><?= h(ucfirst($r['kind'])) ?></td>
        <td class="right <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['amount']) ?></td><td><?= h($r['note']) ?></td><td><?= h($r['name']) ?></td>
        <td><?php if (can('manage')): ?><form method="post" class="inline" onsubmit="return confirm('Delete this entry?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="secondary outline">delete</button></form><?php endif ?></td></tr>
    <?php endforeach ?></tbody></table></div>
    <?php foot();

// ── statement: every money movement with running balance ─────────────────
case 'statement':
    $from = date_or_null($_GET['from'] ?? '') ?? date('Y-m-01');
    $to   = date_or_null($_GET['to'] ?? '') ?? date('Y-m-d');
    $open = q('SELECT COALESCE(SUM(amount), 0) FROM statement WHERE entry_date < ?', [$from])->fetchColumn();
    $rows = q('SELECT entry_date AS date, kind, detail, ref_id, amount,
                      CAST(? AS DECIMAL(14,2)) + SUM(amount) OVER (ORDER BY entry_date, kind, row_id ROWS UNBOUNDED PRECEDING) AS balance
               FROM statement WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, kind, row_id', [$open, $from, $to])->fetchAll();
    if (isset($_GET['csv'])) csv('statement', $rows);
    $in  = array_sum(array_filter(array_column($rows, 'amount'), fn($v) => $v > 0));
    $out = array_sum(array_filter(array_column($rows, 'amount'), fn($v) => $v < 0));
    head('Statement'); ?>
    <form class="filters"><input type="hidden" name="p" value="statement">
      <label>From<input type="date" name="from" value="<?= h($from) ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($to) ?>"></label>
      <button>Show</button><button name="csv" value="1" class="secondary">Excel (CSV)</button>
    </form>
    <div class="cards">
      <article><small>Opening</small><div class="big"><?= money($open) ?></div></article>
      <article><small>Money in</small><div class="big pos"><?= money($in) ?></div></article>
      <article><small>Money out</small><div class="big neg"><?= money($out) ?></div></article>
      <article><small>Closing</small><div class="big"><?= money($rows ? end($rows)['balance'] : $open) ?></div></article>
    </div>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th>Type</th><th>Detail</th><th class="right">Amount</th><th class="right">Balance</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h(date('d M Y', strtotime($r['date']))) ?></td><td><?= h(ucfirst($r['kind'])) ?></td>
        <td><?= $r['kind'] === 'payment' ? '<a href="?p=payment&id=' . (int) $r['ref_id'] . '">' . h($r['detail']) . '</a>' : h($r['detail']) ?></td>
        <td class="right <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['amount']) ?></td><td class="right"><?= money($r['balance']) ?></td></tr>
    <?php endforeach ?></tbody></table></div>
    <?php foot();

// ── admin: users ──────────────────────────────────────────────────────────
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
    <details><summary>+ Add user</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="add">
        <div class="grid"><input name="name" placeholder="Name" required><input name="email" type="email" placeholder="Email" required></div>
        <div class="grid"><input name="password" type="password" placeholder="Password (min 8)" minlength="8" required><?= $role_select() ?></div>
        <button>Add user</button></form>
    </details>
    <?php endif ?>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Name</th><th>Email</th><th>Role</th><?= $edit ? '<th>Reset password</th>' : '' ?></tr></thead><tbody>
    <?php foreach (q('SELECT * FROM users ORDER BY name')->fetchAll() as $u): ?>
      <tr><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td>
        <td><?php if (!$edit || $u['id'] == me()['id']): ?><?= h(ROLES[$u['role']]) ?><?= $u['id'] == me()['id'] ? ' (you)' : '' ?><?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>"><?= $role_select($u['role']) ?></form>
        <?php endif ?></td>
        <?php if ($edit): ?>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="password"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <fieldset role="group"><input name="password" type="password" placeholder="New password" minlength="8" required><button class="secondary">Set</button></fieldset></form></td>
        <?php endif ?></tr>
    <?php endforeach ?></tbody></table></div>
    <?php if ($edit): ?><p><small>To remove someone's access, set a new password they don't know.</small></p><?php endif;
    foot();

default:
    fail('Page not found', 404);
}
