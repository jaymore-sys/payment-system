-- Payment records app — MySQL / MariaDB (Hostinger).
-- Import once: hPanel → Databases → phpMyAdmin → select your database → Import → this file.
--
-- Current balance is never stored; it is calculated (view current_balance) from:
--   cash_entries (admin: opening balance, adjustments, expenses)
--   + receivables  − payments with status 'paid'
-- so it cannot drift from the records.
--
-- Rules the app enforces (MySQL has no per-user row security):
--   only admin approves/rejects; a payment must be 'approved' before 'paid';
--   only admin edits or deletes a paid payment, receivables and cash_entries;
--   the first registered user becomes admin.

SET NAMES utf8mb4;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,           -- PHP password_hash()
  role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parties (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(190) NOT NULL UNIQUE,
  gst_registered BOOLEAN NOT NULL DEFAULT FALSE,
  gstin          VARCHAR(15),
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  party_id      INT NOT NULL,
  products      TEXT NOT NULL,
  bill_type     ENUM('gst','cash') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  due_date      DATE NOT NULL,
  status        ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  reject_reason TEXT,
  reviewed_by   INT,
  paid_on       DATE,
  created_by    INT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id)    REFERENCES parties(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (created_by)  REFERENCES users(id),
  CONSTRAINT amount_positive   CHECK (amount > 0),
  CONSTRAINT reject_has_reason CHECK (status <> 'rejected' OR TRIM(COALESCE(reject_reason,'')) <> ''),
  CONSTRAINT paid_has_date     CHECK (status <> 'paid' OR paid_on IS NOT NULL),
  INDEX (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE receivables (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  party_id    INT NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  received_on DATE NOT NULL,
  against     ENUM('sales','capital') NOT NULL,
  note        TEXT,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (party_id)   REFERENCES parties(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT receivable_positive CHECK (amount > 0),
  INDEX (received_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- admin-only: opening balance, corrections (+/−), other costs (−)
CREATE TABLE cash_entries (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  amount     DECIMAL(14,2) NOT NULL,             -- + money in, − money out
  kind       ENUM('opening','adjustment','expense') NOT NULL,
  note       TEXT,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
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

-- every money movement in one list (reports: filter on entry_date)
CREATE VIEW statement AS
  SELECT c.entry_date, c.amount, c.kind, c.note AS detail, c.id AS ref_id
    FROM cash_entries c
  UNION ALL
  SELECT r.received_on, r.amount, 'receipt', CONCAT(p.name, ' (', r.against, ')'), r.id
    FROM receivables r JOIN parties p ON p.id = r.party_id
  UNION ALL
  SELECT pm.paid_on, -pm.amount, 'payment', CONCAT(p.name, ': ', pm.products), pm.id
    FROM payments pm JOIN parties p ON p.id = pm.party_id
   WHERE pm.status = 'paid';

CREATE VIEW current_balance AS
  SELECT COALESCE(SUM(amount), 0) AS balance FROM statement;

-- reminder popup: unpaid payments overdue or due within 7 days
CREATE VIEW upcoming_dues AS
  SELECT pm.*, p.name AS party_name, DATEDIFF(pm.due_date, CURDATE()) AS days_left
    FROM payments pm JOIN parties p ON p.id = pm.party_id
   WHERE pm.status IN ('pending','approved')
     AND pm.due_date <= CURDATE() + INTERVAL 7 DAY;
