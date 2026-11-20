<?php

function migrate_accounting(mysqli $conn) {
    if (!extension_loaded('bcmath')) throw new RuntimeException('The accounting module requires the PHP BCMath extension.');
    $conn->query("CREATE TABLE IF NOT EXISTS erp_schema_versions (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    if ($conn->query("SELECT version FROM erp_schema_versions WHERE version='001_accounting'")->num_rows) return;

    $tables = ['account_list', 'cash_denominations', 'daily_account_balances', 'entity_list', 'item_list',
        'search_index', 'summary_daily_metrics', 'summary_monthly_metrics', 'transaction_items', 'transaction_list', 'transactions'];
    foreach ($tables as $table) {
        $changes = [];
        foreach ($conn->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $column) {
            if (!preg_match('/^(float|double)/i', $column['Type'])) continue;
            $name = $column['Field'];
            $scale = preg_match('/quantity|reorder|cost|price|mrp|perc/', $name) ? 4 : 2;
            $definition = "DECIMAL(18,$scale) " . ($column['Null'] === 'YES' ? 'NULL' : 'NOT NULL');
            if ($column['Default'] !== null) $definition .= " DEFAULT '" . $conn->real_escape_string($column['Default']) . "'";
            $changes[] = "MODIFY `$name` $definition";
        }
        if ($changes) $conn->query("ALTER TABLE `$table` " . implode(', ', $changes));
    }

    $ddl = [
        "CREATE TABLE IF NOT EXISTS erp_control (id INT PRIMARY KEY) ENGINE=InnoDB",
        "INSERT IGNORE INTO erp_control (id) VALUES (1)",
        "CREATE TABLE IF NOT EXISTS gl_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
            category ENUM('asset','liability','equity','income','expense') NOT NULL,
            active TINYINT NOT NULL DEFAULT 1, system_key VARCHAR(60) NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS gl_periods (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open', closed_at DATETIME NULL, closed_by INT NULL,
            INDEX period_dates (start_date,end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS gl_journals (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE, entry_date DATE NOT NULL,
            memo VARCHAR(1000) NOT NULL, status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
            source_transaction_id INT NULL UNIQUE, reversal_of_id BIGINT NULL UNIQUE,
            request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL,
            created_by INT NOT NULL, posted_by INT NULL, posted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (source_transaction_id) REFERENCES transactions(id),
            FOREIGN KEY (reversal_of_id) REFERENCES gl_journals(id), INDEX journal_dates (entry_date,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS gl_journal_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT NOT NULL, account_id INT NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '', debit DECIMAL(18,2) NOT NULL DEFAULT 0,
            credit DECIMAL(18,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (journal_id) REFERENCES gl_journals(id), FOREIGN KEY (account_id) REFERENCES gl_accounts(id),
            INDEX journal_line_account (account_id,journal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS gl_bank_mapping (
            bank_account_id INT PRIMARY KEY, gl_account_id INT NOT NULL UNIQUE,
            FOREIGN KEY (bank_account_id) REFERENCES account_list(id), FOREIGN KEY (gl_account_id) REFERENCES gl_accounts(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_audit_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id INT NOT NULL, action VARCHAR(50) NOT NULL,
            object_type VARCHAR(40) NOT NULL, object_id BIGINT NOT NULL, details JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX audit_object (object_type,object_id)
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $conn->query($sql);
    $accounts = [
        ['1200', 'Trade receivables', 'asset', 'receivables'],
        ['1300', 'Inventory', 'asset', 'inventory'],
        ['1400', 'Recoverable purchase tax', 'asset', 'input_tax'],
        ['2100', 'Trade payables', 'liability', 'payables'],
        ['2200', 'Sales tax payable', 'liability', 'output_tax'],
        ['3000', 'Opening balance equity', 'equity', 'opening_equity'],
        ['4000', 'Sales revenue', 'income', 'sales'],
        ['4100', 'Sales returns', 'income', 'sales_returns'],
        ['5000', 'Cost of goods sold', 'expense', 'cogs'],
        ['5100', 'Inventory adjustments', 'expense', 'inventory_adjustments'],
        ['6000', 'Operating expenses', 'expense', 'expenses'],
    ];
    $stmt = $conn->prepare('INSERT IGNORE INTO gl_accounts (code,name,category,system_key) VALUES (?,?,?,?)');
    foreach ($accounts as [$code,$name,$category,$key]) {
        $stmt->bind_param('ssss', $code, $name, $category, $key);
        $stmt->execute();
    }
    $stmt->close();

    $period_count = $conn->query('SELECT COUNT(*) FROM gl_periods')->fetch_row()[0];
    if (!$period_count) {
        $now = new DateTimeImmutable('now', new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Asia/Kathmandu'));
        $name = $now->format('F Y'); $start = $now->format('Y-m-01'); $end = $now->format('Y-m-t');
        $stmt = $conn->prepare('INSERT INTO gl_periods (name,start_date,end_date) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $start, $end); $stmt->execute(); $stmt->close();
    }

    // Database guards also cover existing import code and direct service calls.
    $control_lock = 'DECLARE lock_id INT; SELECT id INTO lock_id FROM erp_control WHERE id=1 LOCK IN SHARE MODE; ';
    $guard = static function ($id, $date) {
        return "IF EXISTS (SELECT 1 FROM gl_journals WHERE source_transaction_id=$id AND status='posted' LOCK IN SHARE MODE) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted documents are immutable. Record a return or correcting journal.'; END IF;
            IF EXISTS (SELECT 1 FROM gl_periods WHERE status='closed' AND $date BETWEEN start_date AND end_date LOCK IN SHARE MODE) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The accounting period is closed.'; END IF; ";
    };
    $triggers = [];
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $body = '';
        if ($event !== 'INSERT') $body .= $guard('OLD.id', 'OLD.transaction_date');
        if ($event !== 'DELETE') $body .= $guard('NEW.id', 'NEW.transaction_date');
        $triggers['erp_transactions_' . strtolower($event)] = "BEFORE $event ON transactions FOR EACH ROW BEGIN $control_lock $body END";
        foreach (['transaction_items' => 'transaction_id', 'transaction_list' => 'ref_id'] as $table => $field) {
            $body = '';
            foreach ($event === 'UPDATE' ? ['OLD','NEW'] : [$event === 'INSERT' ? 'NEW' : 'OLD'] as $row) {
                $id = "$row.$field";
                $check = "SELECT id INTO parent_id_lock FROM transactions WHERE id=$id FOR UPDATE; " . $guard($id, "(SELECT transaction_date FROM transactions WHERE id=$id)");
                $body .= $table === 'transaction_list' ? "IF $row.ref_table='transactions' THEN $check END IF; " : $check;
            }
            $triggers['erp_' . $table . '_' . strtolower($event)] = "BEFORE $event ON `$table` FOR EACH ROW BEGIN DECLARE parent_id_lock INT; $control_lock $body END";
        }
    }
    $triggers['erp_journal_insert'] = "BEFORE INSERT ON gl_journals FOR EACH ROW BEGIN
        IF NEW.status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create journals as drafts before posting.'; END IF; END";
    $triggers['erp_journal_update'] = "BEFORE UPDATE ON gl_journals FOR EACH ROW BEGIN $control_lock
        IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted journals cannot be edited.'; END IF;
        IF NEW.status='posted' THEN
            IF NOT EXISTS (SELECT 1 FROM gl_periods WHERE status='open' AND NEW.entry_date BETWEEN start_date AND end_date LOCK IN SHARE MODE) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posting requires an open accounting period.'; END IF;
            IF (SELECT COUNT(*) FROM gl_journal_lines WHERE journal_id=OLD.id) < 2 OR
                (SELECT COALESCE(SUM(debit-credit),0) FROM gl_journal_lines WHERE journal_id=OLD.id) <> 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Journal debits and credits must balance.'; END IF;
            IF EXISTS (SELECT 1 FROM gl_journal_lines l JOIN gl_accounts a ON a.id=l.account_id WHERE l.journal_id=OLD.id AND a.active<>1) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A journal account is inactive.'; END IF;
        END IF; END";
    $triggers['erp_journal_delete'] = "BEFORE DELETE ON gl_journals FOR EACH ROW BEGIN
        IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted journals cannot be deleted.'; END IF; END";
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $checks = '';
        foreach ($event === 'UPDATE' ? ['OLD','NEW'] : [$event === 'INSERT' ? 'NEW' : 'OLD'] as $row) {
            $checks .= "SELECT status INTO parent_status FROM gl_journals WHERE id=$row.journal_id FOR UPDATE;
                IF parent_status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted journal lines are immutable.'; END IF; ";
        }
        if ($event !== 'DELETE') $checks .= "IF (NEW.debit <= 0 AND NEW.credit <= 0) OR (NEW.debit <> 0 AND NEW.credit <> 0) OR NEW.debit < 0 OR NEW.credit < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A line requires either a positive debit or a positive credit.'; END IF;";
        $triggers['erp_journal_line_' . strtolower($event)] = "BEFORE $event ON gl_journal_lines FOR EACH ROW BEGIN DECLARE parent_status VARCHAR(20); $checks END";
    }
    foreach (['UPDATE','DELETE'] as $event) {
        $triggers['erp_audit_' . strtolower($event)] = "BEFORE $event ON erp_audit_events FOR EACH ROW BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Accounting audit events are append-only.'; END";
    }
    foreach ($triggers as $name => $definition) {
        $exists = $conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows;
        if (!$exists) $conn->query("CREATE TRIGGER `$name` $definition");
    }
    $conn->query("INSERT INTO erp_schema_versions (version) VALUES ('001_accounting')");
    echo "Applied accounting foundation and decimal storage.\n";
}
