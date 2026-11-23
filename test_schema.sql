-- Local check of the balance and outstanding math. Run on an EMPTY test database:
--   mysql testdb < schema.sql && mysql testdb < test_schema.sql
-- Each `DO IF(ok, 0, (SELECT 1 UNION SELECT 2))` errors ("more than 1 row") when a check fails.

INSERT INTO users (name, email, password_hash, role) VALUES ('Admin','a@x','-','admin'), ('Maker','s@x','-','administrator');
INSERT INTO parties (name, gst_registered) VALUES ('Acme', TRUE);

INSERT INTO cash_entries (entry_date, amount, kind, created_by) VALUES (CURDATE(), 100000, 'opening', 1);
INSERT INTO payments (party_id, invoice_no, invoice_date, products, bill_type, amount, due_date, created_by)
  VALUES (1, 'INV-1', CURDATE() - INTERVAL 40 DAY, 'Steel', 'gst', 25000.50, CURDATE() - INTERVAL 10 DAY, 2);
INSERT INTO receivables (party_id, amount, received_on, against, created_by) VALUES (1, 10000, CURDATE(), 'sales', 2);

DO IF((SELECT balance FROM current_balance) = 110000, 0, (SELECT 1 UNION SELECT 2));                  -- receipt adds, unpaid invoice ignored
DO IF((SELECT pay_status FROM payment_register) = 'Unpaid', 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT days_overdue FROM payment_register) = 10, 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT days_since_invoice FROM payment_register) = 40, 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT COUNT(*) FROM upcoming_dues) = 1, 0, (SELECT 1 UNION SELECT 2));                        -- overdue shows as reminder

UPDATE payments SET status = 'approved', reviewed_by = 1 WHERE id = 1;
INSERT INTO payouts (payment_id, amount, paid_on, created_by) VALUES (1, 10000, CURDATE(), 2);       -- part payment
DO IF((SELECT CONCAT(pay_status, '|', outstanding) FROM payment_register) = 'Part Paid|15000.50', 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT balance FROM current_balance) = 100000, 0, (SELECT 1 UNION SELECT 2));
INSERT INTO payouts (payment_id, amount, paid_on, created_by) VALUES (1, 15000.50, CURDATE(), 2);    -- rest
DO IF((SELECT CONCAT(pay_status, '|', outstanding, '|', days_overdue) FROM payment_register) = 'Paid|0.00|0', 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT balance FROM current_balance) = 84999.50, 0, (SELECT 1 UNION SELECT 2));
DO IF((SELECT COUNT(*) FROM upcoming_dues) = 0, 0, (SELECT 1 UNION SELECT 2));                        -- paid leaves reminders

INSERT INTO cash_entries (entry_date, amount, kind, created_by) VALUES (CURDATE(), -4999.50, 'expense', 1);
DO IF((SELECT balance FROM current_balance) = 80000, 0, (SELECT 1 UNION SELECT 2));                   -- expense subtracts
DO IF((SELECT COUNT(*) FROM statement) = 5, 0, (SELECT 1 UNION SELECT 2));

SELECT 'ALL CHECKS PASSED';
