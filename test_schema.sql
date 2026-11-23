-- Local check of the balance math. Run on an EMPTY test database:
--   mysql testdb < schema.sql && mysql testdb < test_schema.sql
-- Each `DO IF(ok, 0, (SELECT 1 UNION SELECT 2))` errors ("more than 1 row") when a check fails.

INSERT INTO users (name, email, password_hash, role) VALUES ('Admin','a@x','-','admin'), ('Maker','s@x','-','administrator');
INSERT INTO parties (name, gst_registered) VALUES ('Acme', TRUE);

INSERT INTO cash_entries (entry_date, amount, kind, created_by) VALUES (CURDATE(), 100000, 'opening', 1);
INSERT INTO payments (party_id, products, bill_type, amount, due_date, created_by)
  VALUES (1, 'Steel', 'gst', 25000.50, CURDATE() + INTERVAL 2 DAY, 2);
INSERT INTO receivables (party_id, amount, received_on, against, created_by) VALUES (1, 10000, CURDATE(), 'sales', 2);

DO IF((SELECT balance FROM current_balance) = 110000, 0, (SELECT 1 UNION SELECT 2));  -- receipt adds, pending ignored
DO IF((SELECT COUNT(*) FROM upcoming_dues) = 1, 0, (SELECT 1 UNION SELECT 2));        -- reminder shows

UPDATE payments SET status = 'approved', reviewed_by = 1 WHERE id = 1;
UPDATE payments SET status = 'paid', paid_on = CURDATE() WHERE id = 1;
DO IF((SELECT balance FROM current_balance) = 84999.50, 0, (SELECT 1 UNION SELECT 2)); -- paid subtracts
DO IF((SELECT COUNT(*) FROM upcoming_dues) = 0, 0, (SELECT 1 UNION SELECT 2));          -- paid leaves reminders

INSERT INTO cash_entries (entry_date, amount, kind, created_by) VALUES (CURDATE(), -4999.50, 'expense', 1);
DO IF((SELECT balance FROM current_balance) = 80000, 0, (SELECT 1 UNION SELECT 2));    -- expense subtracts
DO IF((SELECT COUNT(*) FROM statement) = 4, 0, (SELECT 1 UNION SELECT 2));

SELECT 'ALL CHECKS PASSED';
