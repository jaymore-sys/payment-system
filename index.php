<?php
require __DIR__ . '/app.php';

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

switch ($p) {

// ── first run: create the admin ───────────────────────────────────────────
case 'setup':
    if ($has_users) go('?p=login');
    if ($post) {
        $email = filter_var(req('email'), FILTER_VALIDATE_EMAIL) ?: fail('Invalid email');
        if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
        q("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
          [req('name'), $email, password_hash($_POST['password'], PASSWORD_DEFAULT)]);
        login(db()->lastInsertId());
        go('?', 'Admin account created. Add your opening balance under "Balance entries".');
    }
    head('Create admin account'); ?>
    <p>This is the first account, so it will be the admin. Add staff later under Users.</p>
    <form method="post"><?= csrf_field() ?>
      <input name="name" placeholder="Your name" required>
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password (min 8 characters)" minlength="8" required>
      <button>Create admin</button>
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
                      (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid' AND paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS paid")->fetch();
    $popup = $dues && empty($_SESSION['dues_seen']);
    if ($popup) $_SESSION['dues_seen'] = true;
    head('Dashboard'); ?>
    <div class="cards">
      <article><small>Current balance</small><div class="big <?= $bal < 0 ? 'neg' : '' ?>"><?= money($bal) ?></div></article>
      <article><small>Received this month</small><div class="big pos"><?= money($mon['received']) ?></div></article>
      <article><small>Paid this month</small><div class="big neg"><?= money($mon['paid']) ?></div></article>
      <article><small>Awaiting approval</small><div class="big"><a href="?p=payments&status=pending"><?= (int) $pend ?></a></div></article>
    </div>
    <p><a href="?p=payment" role="button">+ New payment</a> <a href="?p=receivables" role="button" class="secondary">+ Receipt</a></p>
    <h3>Due within 7 days / overdue</h3>
    <?php $due_table = function () use ($dues) { ?>
      <div class="overflow-auto"><table class="striped"><thead><tr><th>Party</th><th>Products</th><th class="right">Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($dues as $d): ?>
        <tr><td><a href="?p=payment&id=<?= $d['id'] ?>"><?= h($d['party_name']) ?></a></td><td><?= h($d['products']) ?></td>
          <td class="right"><?= money($d['amount']) ?></td>
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

// ── payments list + report ───────────────────────────────────────────────
case 'payments':
    $by = ($_GET['by'] ?? '') === 'paid_on' ? 'pm.paid_on' : 'pm.due_date';
    $w = ['1=1']; $args = [];
    if ($d = date_or_null($_GET['from'] ?? '')) { $w[] = "$by >= ?"; $args[] = $d; }
    if ($d = date_or_null($_GET['to'] ?? ''))   { $w[] = "$by <= ?"; $args[] = $d; }
    if (in_array($_GET['status'] ?? '', STATUSES, true)) { $w[] = 'pm.status = ?'; $args[] = $_GET['status']; }
    if (in_array($_GET['bill'] ?? '', ['gst', 'cash'], true)) { $w[] = 'pm.bill_type = ?'; $args[] = $_GET['bill']; }
    if (($s = trim($_GET['party'] ?? '')) !== '') { $w[] = 'p.name LIKE ?'; $args[] = "%$s%"; }
    // ponytail: no paging; add LIMIT/OFFSET when lists pass a few thousand rows
    $rows = q('SELECT pm.id, p.name AS party, pm.products, pm.bill_type, pm.amount, pm.due_date, pm.status, pm.paid_on, pm.reject_reason
               FROM payments pm JOIN parties p ON p.id = pm.party_id WHERE ' . implode(' AND ', $w) . ' ORDER BY pm.due_date DESC, pm.id DESC', $args)->fetchAll();
    if (isset($_GET['csv'])) csv('payments', $rows);
    head('Payments'); ?>
    <form class="filters"><input type="hidden" name="p" value="payments">
      <label>Date of<select name="by"><option value="due_date">Due date</option><option value="paid_on" <?= $by === 'pm.paid_on' ? 'selected' : '' ?>>Paid date</option></select></label>
      <label>From<input type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>"></label>
      <label>To<input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
      <label>Status<select name="status"><option value="">All</option>
        <?php foreach (STATUSES as $st): ?><option <?= ($_GET['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach ?></select></label>
      <label>Bill<select name="bill"><option value="">All</option>
        <option value="gst" <?= ($_GET['bill'] ?? '') === 'gst' ? 'selected' : '' ?>>GST</option><option value="cash" <?= ($_GET['bill'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option></select></label>
      <label>Party<input name="party" value="<?= h($_GET['party'] ?? '') ?>"></label>
      <button>Filter</button>
      <button name="csv" value="1" class="secondary">Excel (CSV)</button>
    </form>
    <p><a href="?p=payment" role="button">+ New payment</a></p>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>#</th><th>Party</th><th>Products</th><th>Bill</th><th class="right">Amount</th><th>Due</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="?p=payment&id=<?= $r['id'] ?>"><?= $r['id'] ?></a></td><td><a href="?p=payment&id=<?= $r['id'] ?>"><?= h($r['party']) ?></a></td>
        <td><?= h($r['products']) ?></td><td><?= strtoupper($r['bill_type']) ?></td><td class="right"><?= money($r['amount']) ?></td>
        <td><?= h(date('d M Y', strtotime($r['due_date']))) ?></td><td><?= badge($r['status']) ?></td></tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><th colspan="4"><?= count($rows) ?> payments</th><th class="right"><?= money(array_sum(array_column($rows, 'amount'))) ?></th><th colspan="2"></th></tr></tfoot></table></div>
    <?php foot();

// ── one payment: create / edit / approve / pay / follow-up / files / chat ─
case 'payment':
    $id = (int) ($_GET['id'] ?? 0);
    $pm = $id ? (q('SELECT pm.*, p.name AS party, u.name AS creator, r.name AS reviewer FROM payments pm JOIN parties p ON p.id = pm.party_id
                    JOIN users u ON u.id = pm.created_by LEFT JOIN users r ON r.id = pm.reviewed_by WHERE pm.id = ?', [$id])->fetch() ?: fail('Payment not found', 404)) : null;
    $locked = $pm && $pm['status'] === 'paid' && !is_admin();
    $uid = me()['id'];

    if ($post) {
        if (!$pm && $a !== 'save') fail('Payment not found', 404);
        $changed = fn(PDOStatement $st) => $st->rowCount() ?: fail('Not allowed for a payment that is ' . $pm['status']);
        switch ($a) {
        case 'save':
            if ($locked) fail('Only admin can change a paid payment', 403);
            $f = [party_id(req('party')), req('products'), enum_in($_POST['bill_type'] ?? '', ['gst', 'cash']), amount_in($_POST['amount'] ?? ''), date_in($_POST['due_date'] ?? '')];
            if (!$pm) {
                q('INSERT INTO payments (party_id, products, bill_type, amount, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?)', [...$f, $uid]);
                $id = (int) db()->lastInsertId();
            } elseif (is_admin()) {
                q('UPDATE payments SET party_id = ?, products = ?, bill_type = ?, amount = ?, due_date = ? WHERE id = ?', [...$f, $id]);
            } else { // staff edits go back for approval
                q("UPDATE payments SET party_id = ?, products = ?, bill_type = ?, amount = ?, due_date = ?, status = 'pending', reviewed_by = NULL, reject_reason = NULL WHERE id = ?", [...$f, $id]);
            }
            break;
        case 'approve':
            need_admin();
            $changed(q("UPDATE payments SET status = 'approved', reviewed_by = ?, reject_reason = NULL WHERE id = ? AND status IN ('pending', 'rejected')", [$uid, $id]));
            break;
        case 'reject':
            need_admin();
            $changed(q("UPDATE payments SET status = 'rejected', reviewed_by = ?, reject_reason = ? WHERE id = ? AND status IN ('pending', 'approved')", [$uid, req('reason'), $id]));
            break;
        case 'pay':
            $changed(q("UPDATE payments SET status = 'paid', paid_on = ? WHERE id = ? AND status = 'approved'", [date_in($_POST['paid_on'] ?? ''), $id]));
            break;
        case 'reopen':
            need_admin();
            q("UPDATE payments SET status = 'pending', paid_on = NULL, reviewed_by = NULL, reject_reason = NULL WHERE id = ?", [$id]);
            break;
        case 'delete':
            need_admin();
            foreach (q('SELECT file_path FROM attachments WHERE payment_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN) as $fp) @unlink(upload_dir() . '/' . basename($fp));
            q('DELETE FROM payments WHERE id = ?', [$id]);
            go('?p=payments', "Payment #$id deleted");
        case 'due':
            if ($locked) fail('Payment is already paid', 403);
            $new = date_in($_POST['due_date'] ?? '');
            q('UPDATE payments SET due_date = ? WHERE id = ?', [$new, $id]);
            $note = trim($_POST['note'] ?? '');
            q("INSERT INTO messages (payment_id, user_id, kind, body) VALUES (?, ?, 'followup', ?)",
              [$id, $uid, 'Due date changed ' . date('d M Y', strtotime($pm['due_date'])) . ' → ' . date('d M Y', strtotime($new)) . ($note !== '' ? ". $note" : '')]);
            break;
        case 'msg':
            q('INSERT INTO messages (payment_id, user_id, kind, body) VALUES (?, ?, ?, ?)', [$id, $uid, enum_in($_POST['kind'] ?? 'chat', ['chat', 'followup']), req('body')]);
            break;
        case 'upload':
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
            if ($att['uploaded_by'] != $uid && !is_admin()) fail('Only the uploader or admin can remove this file', 403);
            @unlink(upload_dir() . '/' . basename($att['file_path']));
            q('DELETE FROM attachments WHERE id = ?', [$att['id']]);
            break;
        default:
            fail('Unknown action');
        }
        go("?p=payment&id=$id", 'Saved');
    }

    head($pm ? "Payment #$id" : 'New payment');
    if ($pm): ?>
      <p><?= badge($pm['status']) ?> &nbsp; <strong><?= h($pm['party']) ?></strong> · <?= money($pm['amount']) ?> · <?= strtoupper($pm['bill_type']) ?> bill
        · due <?= h(date('d M Y', strtotime($pm['due_date']))) ?><?= $pm['paid_on'] ? ' · paid ' . h(date('d M Y', strtotime($pm['paid_on']))) : '' ?><br>
        <small>Added by <?= h($pm['creator']) ?> on <?= h(date('d M Y', strtotime($pm['created_at']))) ?><?= $pm['reviewer'] ? ' · reviewed by ' . h($pm['reviewer']) : '' ?></small></p>
      <?php if ($pm['status'] === 'rejected'): ?><p class="neg"><strong>Rejected:</strong> <?= h($pm['reject_reason']) ?></p><?php endif ?>

      <?php if (is_admin() && in_array($pm['status'], ['pending', 'rejected', 'approved'], true)): ?>
        <article><header><strong>Approval</strong></header>
          <?php if ($pm['status'] !== 'approved'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="a" value="approve"><button>✅ Yes, approve</button></form>
          <?php endif ?>
          <form method="post" style="margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="a" value="reject">
            <fieldset role="group"><input name="reason" placeholder="Reason for rejecting" required><button class="secondary">❌ No, reject</button></fieldset></form>
        </article>
      <?php endif ?>

      <?php if ($pm['status'] === 'approved'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="pay">
          <fieldset role="group"><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" required><button>💸 Mark as paid</button></fieldset></form>
      <?php elseif ($pm['status'] === 'paid' && is_admin()): ?>
        <form method="post" onsubmit="return confirm('Undo payment? The amount goes back into the balance.')"><?= csrf_field() ?><input type="hidden" name="a" value="reopen"><button class="secondary">↩ Undo paid (back to pending)</button></form>
      <?php endif ?>

      <?php if (!$locked && $pm['status'] !== 'paid'): ?>
        <details><summary>📅 Change due date / add follow-up</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="due">
            <input type="date" name="due_date" value="<?= h($pm['due_date']) ?>" required>
            <input name="note" placeholder="Follow-up note (e.g. party asked for 1 week more)">
            <button>Update due date</button></form>
        </details>
      <?php endif ?>
    <?php endif;

    if (!$locked): ?>
      <details <?= $pm ? '' : 'open' ?>><summary><?= $pm ? '✏️ Edit details' : 'Details' ?></summary>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save">
          <label>Party name<input name="party" list="parties" value="<?= h($pm['party'] ?? '') ?>" required></label><?= parties_datalist() ?>
          <label>Products<textarea name="products" required><?= h($pm['products'] ?? '') ?></textarea></label>
          <div class="grid">
            <label>Bill type<select name="bill_type"><option value="gst">GST bill</option><option value="cash" <?= ($pm['bill_type'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash bill</option></select></label>
            <label>Amount (₹)<input name="amount" inputmode="decimal" value="<?= h($pm['amount'] ?? '') ?>" required></label>
            <label>Due date<input type="date" name="due_date" value="<?= h($pm['due_date'] ?? '') ?>" required></label>
          </div>
          <?php if ($pm && !is_admin()): ?><small>Saving changes sends this payment back for approval.</small><?php endif ?>
          <button><?= $pm ? 'Save changes' : 'Create payment' ?></button>
        </form>
      </details>
    <?php endif;

    if ($pm):
        $files = q('SELECT * FROM attachments WHERE payment_id = ? ORDER BY id', [$id])->fetchAll();
        $msgs = q('SELECT m.*, u.name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.payment_id = ? ORDER BY m.id', [$id])->fetchAll(); ?>
      <h3>📎 Invoice / files</h3>
      <ul><?php foreach ($files as $f): ?>
        <li><a href="?p=file&id=<?= $f['id'] ?>" target="_blank"><?= h($f['file_name']) ?></a>
          <?php if ($f['uploaded_by'] == $uid || is_admin()): ?>
            <form method="post" class="inline" onsubmit="return confirm('Remove file?')"><?= csrf_field() ?><input type="hidden" name="a" value="unattach"><input type="hidden" name="att" value="<?= $f['id'] ?>"><button class="secondary outline">remove</button></form>
          <?php endif ?></li>
      <?php endforeach ?></ul>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="upload">
        <fieldset role="group"><input type="file" name="file" accept="image/*,.pdf,.xls,.xlsx" required><button>Upload</button></fieldset></form>

      <h3>💬 Discussion & follow-ups</h3>
      <div id="chat" data-payment="<?= $id ?>" data-after="<?= $msgs ? end($msgs)['id'] : 0 ?>"><?php foreach ($msgs as $m) echo msg_html($m) ?></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="msg">
        <textarea name="body" placeholder="Write a message…" required></textarea>
        <fieldset role="group"><select name="kind"><option value="chat">Chat</option><option value="followup">Follow-up note</option></select><button>Send</button></fieldset></form>

      <?php if (is_admin()): ?>
        <form method="post" onsubmit="return confirm('Delete this payment with its files and chat?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete">
          <button class="secondary outline">🗑 Delete payment</button></form>
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
            need_admin();
            q('DELETE FROM receivables WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            go('?p=receivables', 'Receipt deleted');
        }
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
    head('Receivables'); ?>
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
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th>Party</th><th>GST reg.</th><th>Against</th><th class="right">Amount</th><th>Note</th><?= is_admin() ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h(date('d M Y', strtotime($r['received_on']))) ?></td><td><?= h($r['party']) ?></td><td><?= $r['gst_registered'] ?><?= $r['gstin'] ? '<br><small>' . h($r['gstin']) . '</small>' : '' ?></td>
        <td><?= h(ucfirst($r['against'])) ?></td><td class="right pos"><?= money($r['amount']) ?></td><td><?= h($r['note']) ?></td>
        <?php if (is_admin()): ?><td><form method="post" class="inline" onsubmit="return confirm('Delete this receipt?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="secondary outline">delete</button></form></td><?php endif ?></tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><th colspan="4"><?= count($rows) ?> receipts</th><th class="right"><?= money(array_sum(array_column($rows, 'amount'))) ?></th><th colspan="2"></th></tr></tfoot></table></div>
    <?php foot();

// ── admin: opening balance, adjustments, expenses ─────────────────────────
case 'cash':
    need_admin();
    if ($post) {
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
      Receipts and paid payments change it automatically; use this page for the opening balance, corrections and other costs.</p>
    <form method="post"><?= csrf_field() ?>
      <div class="grid">
        <label>Type<select name="kind"><option value="opening">Opening balance</option><option value="expense">Expense / other cost</option><option value="adjustment">Correction (+ or −)</option></select></label>
        <label>Amount (₹)<input name="amount" inputmode="decimal" placeholder="Expense: enter positive" required></label>
        <label>Date<input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required></label>
      </div>
      <input name="note" placeholder="Note (e.g. office rent, bank charges)">
      <button>Add entry</button>
    </form>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Date</th><th>Type</th><th class="right">Amount</th><th>Note</th><th>By</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h(date('d M Y', strtotime($r['entry_date']))) ?></td><td><?= h(ucfirst($r['kind'])) ?></td>
        <td class="right <?= $r['amount'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['amount']) ?></td><td><?= h($r['note']) ?></td><td><?= h($r['name']) ?></td>
        <td><form method="post" class="inline" onsubmit="return confirm('Delete this entry?')"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="secondary outline">delete</button></form></td></tr>
    <?php endforeach ?></tbody></table></div>
    <?php foot();

// ── statement: every money movement with running balance ─────────────────
case 'statement':
    $from = date_or_null($_GET['from'] ?? '') ?? date('Y-m-01');
    $to   = date_or_null($_GET['to'] ?? '') ?? date('Y-m-d');
    $open = q('SELECT COALESCE(SUM(amount), 0) FROM statement WHERE entry_date < ?', [$from])->fetchColumn();
    $rows = q('SELECT entry_date AS date, kind, detail, ref_id, amount,
                      CAST(? AS DECIMAL(14,2)) + SUM(amount) OVER (ORDER BY entry_date, kind, ref_id ROWS UNBOUNDED PRECEDING) AS balance
               FROM statement WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, kind, ref_id', [$open, $from, $to])->fetchAll();
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
    need_admin();
    if ($post) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($a === 'add') {
            $email = filter_var(req('email'), FILTER_VALIDATE_EMAIL) ?: fail('Invalid email');
            if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) fail('That email already has an account');
            if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
            q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
              [req('name'), $email, password_hash($_POST['password'], PASSWORD_DEFAULT), enum_in($_POST['role'] ?? '', ['admin', 'staff'])]);
        } elseif ($a === 'role') {
            if ($id === (int) me()['id']) fail('You cannot change your own role');
            q('UPDATE users SET role = ? WHERE id = ?', [enum_in($_POST['role'] ?? '', ['admin', 'staff']), $id]);
        } elseif ($a === 'password') {
            if (strlen($_POST['password'] ?? '') < 8) fail('Password must be at least 8 characters');
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
        }
        go('?p=users', 'Saved');
    }
    head('Users'); ?>
    <details><summary>+ Add user</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="add">
        <div class="grid"><input name="name" placeholder="Name" required><input name="email" type="email" placeholder="Email" required></div>
        <div class="grid"><input name="password" type="password" placeholder="Password (min 8)" minlength="8" required>
          <select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></div>
        <button>Add user</button></form>
    </details>
    <div class="overflow-auto"><table class="striped"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Reset password</th></tr></thead><tbody>
    <?php foreach (q('SELECT * FROM users ORDER BY name')->fetchAll() as $u): ?>
      <tr><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td>
        <td><?php if ($u['id'] == me()['id']): ?><?= $u['role'] ?> (you)<?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="role"><input type="hidden" name="id" value="<?= $u['id'] ?>">
            <select name="role" onchange="this.form.submit()"><option <?= $u['role'] === 'staff' ? 'selected' : '' ?>>staff</option><option <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option></select></form>
        <?php endif ?></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="password"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <fieldset role="group"><input name="password" type="password" placeholder="New password" minlength="8" required><button class="secondary">Set</button></fieldset></form></td></tr>
    <?php endforeach ?></tbody></table></div>
    <p><small>To remove someone's access, set a new password they don't know.</small></p>
    <?php foot();

default:
    fail('Page not found', 404);
}
