-- Payment records app — MySQL / MariaDB (Hostinger).
-- The app runs this file itself on first start (empty database); later changes go in MIGRATIONS in app.php.
--
-- Current balance is never stored; it is calculated (view current_balance) from:
--   cash_entries (admin: opening balance, adjustments, expenses)
--   + receivables  − payouts (each payment made against an invoice)
-- so it cannot drift from the records.
--
-- Roles (enforced by the app, see can() in app.php):
--   administrator makes requests, admin approves, superadmin views everything,
--   developer manages logins. The first registered user becomes developer.
--   Invoices and receipts are requests sent to one admin; only approved ones count. Balance entries need no approval.

SET NAMES utf8mb4;

CREATE TABLE app_meta (
  k VARCHAR(30) PRIMARY KEY,
  v VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO app_meta VALUES ('schema', '6');

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,           -- PHP password_hash()
  role          ENUM('superadmin','admin','administrator','developer') NOT NULL DEFAULT 'administrator',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- product / remarks names offered in the invoice dropdown
CREATE TABLE products (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parties (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(190) NOT NULL UNIQUE,
  gst_registered BOOLEAN NOT NULL DEFAULT FALSE,
  gstin          VARCHAR(15),
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- inward invoices to pay (the "Invoice Register")
CREATE TABLE payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  party_id      INT NOT NULL,
  invoice_no    VARCHAR(60),
  invoice_date  DATE,
  products      TEXT NOT NULL,                   -- products / remarks
  bill_type     ENUM('gst','cash') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,          -- invoice amount
  due_date      DATE NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approver_id   INT,                             -- admin the request was sent to
  reject_reason TEXT,
  reviewed_by   INT,
  created_by    INT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id)    REFERENCES parties(id),
  FOREIGN KEY (approver_id) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (created_by)  REFERENCES users(id),
  CONSTRAINT amount_positive   CHECK (amount > 0),
  CONSTRAINT reject_has_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> ''),
  INDEX (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- money paid against an invoice; several = part payments
CREATE TABLE payouts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  amount     DECIMAL(14,2) NOT NULL,
  paid_on    DATE NOT NULL,
  note       VARCHAR(255),
  in_balance BOOLEAN NOT NULL DEFAULT TRUE,      -- FALSE: paid before the app was used (Excel import)
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT payout_positive CHECK (amount > 0),
  INDEX (paid_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE receivables (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  party_id    INT NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  received_on DATE NOT NULL,
  against     ENUM('sales','capital') NOT NULL,
  note        TEXT,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approver_id INT,
  reject_reason TEXT,
  reviewed_by INT,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id)    REFERENCES parties(id),
  FOREIGN KEY (approver_id) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (created_by)  REFERENCES users(id),
  CONSTRAINT receivable_positive CHECK (amount > 0),
  CONSTRAINT receipt_reject_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> ''),
  INDEX (received_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- opening balance, corrections (+/−), other costs (−); admin and administrator add them directly (status kept for old requests)
CREATE TABLE cash_entries (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  amount     DECIMAL(14,2) NOT NULL,             -- + money in, − money out
  kind       ENUM('opening','adjustment','expense') NOT NULL,
  note       TEXT,
  status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  approver_id INT,
  reject_reason TEXT,
  reviewed_by INT,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (approver_id) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT cash_reject_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> ''),
  CONSTRAINT entry_nonzero    CHECK (amount <> 0),
  CONSTRAINT expense_negative CHECK (kind <> 'expense' OR amount < 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- chat + follow-ups; payment_id NULL = general discussion
CREATE TABLE messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT,
  user_id    INT NOT NULL,
  kind       ENUM('chat','followup') NOT NULL DEFAULT 'chat',
  body       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id),
  INDEX (payment_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- files live in an uploads/ folder on the server; only the path is stored here
CREATE TABLE attachments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  payment_id  INT NOT NULL,
  file_path   VARCHAR(255) NOT NULL,
  file_name   VARCHAR(255) NOT NULL,
  uploaded_by INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id)  REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── views (re-created by the app after every migration) ──────────────────

-- one row per invoice with the same derived columns as the Excel register
CREATE OR REPLACE VIEW payment_register AS
  SELECT pm.*, p.name AS party,
         COALESCE(po.paid, 0) AS paid,
         pm.amount - COALESCE(po.paid, 0) AS outstanding,
         po.last_paid_on,
         CASE WHEN COALESCE(po.paid, 0) >= pm.amount THEN 'Paid'
              WHEN COALESCE(po.paid, 0) > 0 THEN 'Part Paid' ELSE 'Unpaid' END AS pay_status,
         IF(pm.amount > COALESCE(po.paid, 0), GREATEST(0, DATEDIFF(CURDATE(), pm.due_date)), 0) AS days_overdue,
         DATEDIFF(CURDATE(), COALESCE(pm.invoice_date, DATE(pm.created_at))) AS days_since_invoice
    FROM payments pm
    JOIN parties p ON p.id = pm.party_id
    LEFT JOIN (SELECT payment_id, SUM(amount) AS paid, MAX(paid_on) AS last_paid_on FROM payouts GROUP BY payment_id) po
      ON po.payment_id = pm.id;

-- every money movement in one list (reports: filter on entry_date)
CREATE OR REPLACE VIEW statement AS
  SELECT c.entry_date, c.amount, c.kind, c.note AS detail, c.id AS ref_id, c.id AS row_id
    FROM cash_entries c WHERE c.status = 'approved'
  UNION ALL
  SELECT r.received_on, r.amount, 'receipt', CONCAT(p.name, ' (', r.against, ')'), r.id, r.id
    FROM receivables r JOIN parties p ON p.id = r.party_id WHERE r.status = 'approved'
  UNION ALL
  SELECT po.paid_on, -po.amount, 'payment', CONCAT(p.name, ': ', COALESCE(NULLIF(pm.invoice_no, ''), pm.products)), pm.id, po.id
    FROM payouts po JOIN payments pm ON pm.id = po.payment_id JOIN parties p ON p.id = pm.party_id WHERE po.in_balance;

-- everything that needs an admin's yes/no, in one list (Approvals page)
CREATE OR REPLACE VIEW requests AS
  SELECT 'invoice' AS type, pm.id, p.name AS party, COALESCE(NULLIF(pm.invoice_no, ''), CONCAT('#', pm.id)) AS ref,
         pm.products AS item, pm.bill_type, pm.due_date, (SELECT COUNT(*) FROM attachments a WHERE a.payment_id = pm.id) AS files,
         pm.amount, pm.status, pm.approver_id, pm.reject_reason, pm.created_by, pm.created_at
    FROM payments pm JOIN parties p ON p.id = pm.party_id
  UNION ALL
  SELECT 'receipt', r.id, p.name, CONCAT('Against ', r.against), COALESCE(r.note, ''), NULL, NULL, 0,
         r.amount, r.status, r.approver_id, r.reject_reason, r.created_by, r.created_at
    FROM receivables r JOIN parties p ON p.id = r.party_id;

CREATE OR REPLACE VIEW current_balance AS
  SELECT COALESCE(SUM(amount), 0) AS balance FROM statement;

-- reminder popup: invoices with money still owed, overdue or due within 7 days
CREATE OR REPLACE VIEW upcoming_dues AS
  SELECT r.*, DATEDIFF(r.due_date, CURDATE()) AS days_left
    FROM payment_register r
   WHERE r.status IN ('pending','approved') AND r.outstanding > 0
     AND r.due_date <= CURDATE() + INTERVAL 7 DAY;
