<?php
// Explicit, repeatable schema upgrade for existing installations.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line.');
}
require_once __DIR__ . '/../classes/DBConnection.php';
$db = new DBConnection();
$columns = [
    'account_list' => ['account_no' => 'VARCHAR(100) NULL', 'bank_name' => 'VARCHAR(160) NULL'],
    'entity_list' => ['created_by' => 'INT NULL', 'updated_by' => 'INT NULL'],
    'item_list' => ['created_by' => 'INT NULL', 'updated_by' => 'INT NULL'],
    'transaction_items' => ['unit' => 'VARCHAR(50) NULL', 'cost_recorded' => 'TINYINT(1) NOT NULL DEFAULT 0'],
    'system_logs' => ['action_type' => 'VARCHAR(20) NULL', 'table_name' => 'VARCHAR(100) NULL',
        'field_name' => 'VARCHAR(100) NULL', 'ip_address' => 'VARCHAR(50) NULL', 'device_info' => 'TEXT NULL'],
    'cash_denominations' => ['updated_by' => 'INT NULL', 'finalized_by' => 'INT NULL'],
    'daily_account_balances' => ['account_name' => 'VARCHAR(250) NULL', 'denomination_id' => 'INT NULL',
        'source' => "VARCHAR(50) NOT NULL DEFAULT 'denomination'", 'date_recorded' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
];
foreach ($columns as $table => $definitions) {
    $existing = array_column($db->conn->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC), 'Field');
    foreach ($definitions as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $db->conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added $table.$column\n";
        }
    }
}
require_once __DIR__ . '/migrations/001_accounting.php';
migrate_accounting($db->conn);
require_once __DIR__ . '/migrations/002_procurement.php';
migrate_procurement($db->conn);
require_once __DIR__ . '/migrations/003_v3_update.php';
migrate_v3_update($db->conn);
require_once __DIR__.'/migrations/004_purchase_cycle.php';
migrate_purchase_cycle($db->conn);
require_once __DIR__.'/migrations/005_item_masters.php';
migrate_item_masters($db->conn);
echo "Schema is up to date.\n";
