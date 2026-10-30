-- Read-only MySQL user for POST /api/debug/query on trio-bites.com.
--
-- Run as a MySQL admin on the production server. Before running:
--   1. Replace restaurant_hub with the DB_DATABASE value in the server's .env, if different.
--   2. Replace CHANGE_ME with a long random password, for example from `openssl rand -hex 24`.
--
-- SELECT only, granted table by table. The secret tables (sessions, tokens, cache, queued
-- jobs) are not granted at all, and users is granted without password and remember_token.
-- The database itself refuses them, whatever the app's own checks may miss.
-- A table added later is not readable until it is granted here.

CREATE USER 'claude_ro'@'localhost' IDENTIFIED BY 'CHANGE_ME'
    WITH MAX_QUERIES_PER_HOUR 2000 MAX_USER_CONNECTIONS 2;

GRANT SELECT ON restaurant_hub.attendance_records TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.branches TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.day_closures TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.employees TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.employee_shift_assignments TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.expenses TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.expense_categories TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.gcash_adjustments TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.gcash_entry_statuses TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.gcash_wallets TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.ingredients TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.ingredient_categories TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.inventory_movements TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.menu_categories TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.menu_items TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.migrations TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.model_has_permissions TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.model_has_roles TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.payroll_entries TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.payroll_periods TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.payroll_rules TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.permissions TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.recipe_items TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.roles TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.role_has_permissions TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.sales TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.sale_items TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.shifts TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.special_expenses TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.special_expense_categories TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.stock_counts TO 'claude_ro'@'localhost';
GRANT SELECT ON restaurant_hub.stock_count_entries TO 'claude_ro'@'localhost';

-- users without password and remember_token.
GRANT SELECT (id, name, email, email_verified_at, branch_id, is_active, created_at, updated_at)
    ON restaurant_hub.users TO 'claude_ro'@'localhost';

-- Check: expect only SELECT grants listed.
SHOW GRANTS FOR 'claude_ro'@'localhost';

-- To switch it off later:  DROP USER 'claude_ro'@'localhost';
