<?php

function migrate_friend_features(mysqli $conn): void {
    if ($conn->query("SELECT 1 FROM erp_schema_versions WHERE version='003_friend_features'")->num_rows) return;
    $ddl = [
        "CREATE TABLE IF NOT EXISTS erp_locations (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
            address VARCHAR(500) NOT NULL DEFAULT '', phone VARCHAR(50) NOT NULL DEFAULT '', active TINYINT NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT IGNORE INTO erp_locations (id,code,name) VALUES (1,'MAIN','Main Store')",
        "CREATE TABLE IF NOT EXISTS erp_transaction_context (
            transaction_id INT PRIMARY KEY, location_id INT NOT NULL,
            FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
            FOREIGN KEY (location_id) REFERENCES erp_locations(id), INDEX context_location(location_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_item_packaging (
            item_id INT PRIMARY KEY, barcode VARCHAR(100) NULL UNIQUE, case_barcode VARCHAR(100) NULL UNIQUE,
            case_name VARCHAR(50) NOT NULL DEFAULT 'Case', units_per_case DECIMAL(18,4) NOT NULL DEFAULT 1,
            case_cost DECIMAL(18,4) NULL, case_price DECIMAL(18,4) NULL, version INT NOT NULL DEFAULT 1,
            FOREIGN KEY (item_id) REFERENCES item_list(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_line_details (
            line_id INT PRIMARY KEY, entered_quantity DECIMAL(18,4) NOT NULL, entered_price DECIMAL(18,4) NOT NULL,
            unit_name VARCHAR(50) NOT NULL, unit_factor DECIMAL(18,4) NOT NULL DEFAULT 1,
            promotion_id INT NULL, normal_price DECIMAL(18,4) NULL, promotion_saving DECIMAL(18,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (line_id) REFERENCES transaction_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_po_context (
            order_id BIGINT PRIMARY KEY, location_id INT NOT NULL, FOREIGN KEY(order_id) REFERENCES po_orders(id),
            FOREIGN KEY(location_id) REFERENCES erp_locations(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_po_line_units (
            order_line_id BIGINT PRIMARY KEY, unit_factor DECIMAL(18,4) NOT NULL DEFAULT 1,
            FOREIGN KEY(order_line_id) REFERENCES po_lines(id) ON DELETE CASCADE
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_stock_transfers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE, transfer_date DATE NOT NULL,
            from_location_id INT NOT NULL, to_location_id INT NOT NULL, notes VARCHAR(1000) NOT NULL,
            request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reversal_of BIGINT NULL UNIQUE,
            FOREIGN KEY (from_location_id) REFERENCES erp_locations(id), FOREIGN KEY (to_location_id) REFERENCES erp_locations(id),
            FOREIGN KEY (reversal_of) REFERENCES erp_stock_transfers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_stock_transfer_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, transfer_id BIGINT NOT NULL, item_id INT NOT NULL,
            quantity DECIMAL(18,4) NOT NULL, unit_cost DECIMAL(18,4) NOT NULL,
            FOREIGN KEY (transfer_id) REFERENCES erp_stock_transfers(id), FOREIGN KEY (item_id) REFERENCES item_list(id),
            UNIQUE KEY transfer_item(transfer_id,item_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_promotions (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
            starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL,
            status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
            discount_type ENUM('percentage','fixed') NOT NULL, discount_basis ENUM('selling_price','mrp') NOT NULL,
            discount_value DECIMAL(18,4) NOT NULL, min_quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
            max_quantity DECIMAL(18,4) NULL, priority INT NOT NULL DEFAULT 0,
            all_locations TINYINT NOT NULL DEFAULT 1, version INT NOT NULL DEFAULT 1,
            created_by INT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_promotion_items (
            promotion_id INT NOT NULL, item_id INT NOT NULL, discount_value DECIMAL(18,4) NULL,
            PRIMARY KEY(promotion_id,item_id), FOREIGN KEY(promotion_id) REFERENCES erp_promotions(id),
            FOREIGN KEY(item_id) REFERENCES item_list(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_promotion_locations (
            promotion_id INT NOT NULL, location_id INT NOT NULL, PRIMARY KEY(promotion_id,location_id),
            FOREIGN KEY(promotion_id) REFERENCES erp_promotions(id), FOREIGN KEY(location_id) REFERENCES erp_locations(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_activities (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, kind ENUM('task','event','call') NOT NULL,
            title VARCHAR(200) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL,
            status ENUM('open','in_progress','completed','cancelled') NOT NULL DEFAULT 'open',
            priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal', assigned_to INT NULL,
            party_id INT NULL, notes TEXT NOT NULL, created_by INT NOT NULL, version INT NOT NULL DEFAULT 1,
            request_key VARCHAR(80) NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(party_id) REFERENCES entity_list(id), INDEX activity_schedule(starts_at,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_user_preferences (
            user_id INT PRIMARY KEY, preferences JSON NOT NULL, version INT NOT NULL DEFAULT 1,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_access_roles (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(500) NOT NULL, permissions JSON NOT NULL, active TINYINT NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_user_roles (
            user_id INT PRIMARY KEY, role_id INT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(role_id) REFERENCES erp_access_roles(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_fiscal_years (
            id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, start_date DATE NOT NULL,
            end_date DATE NOT NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open',
            closed_by INT NULL, closed_at DATETIME NULL, version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS erp_fiscal_year_periods (
            fiscal_year_id INT NOT NULL, period_id INT NOT NULL, was_closed TINYINT NOT NULL,
            PRIMARY KEY(fiscal_year_id,period_id), FOREIGN KEY(fiscal_year_id) REFERENCES erp_fiscal_years(id),
            FOREIGN KEY(period_id) REFERENCES gl_periods(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_pos_checkouts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL,
            sale_id INT NOT NULL, location_id INT NOT NULL, cashier_id INT NOT NULL, amount DECIMAL(18,2) NOT NULL,
            details JSON NOT NULL, response JSON NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(sale_id) REFERENCES transactions(id), FOREIGN KEY(location_id) REFERENCES erp_locations(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS erp_credit_allocations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, return_id INT NOT NULL, invoice_id INT NOT NULL,
            allocation_date DATE NOT NULL, amount DECIMAL(18,2) NOT NULL, reason VARCHAR(800) NOT NULL,
            request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, created_by INT NOT NULL,
            reversal_of BIGINT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(return_id) REFERENCES transactions(id), FOREIGN KEY(invoice_id) REFERENCES transactions(id),
            FOREIGN KEY(reversal_of) REFERENCES erp_credit_allocations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) $conn->query($sql);
    $conn->query("CREATE OR REPLACE VIEW erp_credit_adjustment_events AS
        SELECT r.parent_id AS invoice_id,a.allocation_date,IF(a.reversal_of IS NULL,a.amount,-a.amount) AS adjustment
            FROM erp_credit_allocations a JOIN transactions r ON r.id=a.return_id
        UNION ALL SELECT a.invoice_id,a.allocation_date,IF(a.reversal_of IS NULL,-a.amount,a.amount) AS adjustment FROM erp_credit_allocations a");
    $conn->query('CREATE OR REPLACE VIEW erp_credit_adjustments AS SELECT invoice_id,SUM(adjustment) AS adjustment FROM erp_credit_adjustment_events GROUP BY invoice_id');
    $conn->query("CREATE OR REPLACE VIEW erp_operational_lines AS
        SELECT ti.id AS line_id,t.id AS transaction_id,t.transaction_date,t.type,t.entity_id,ti.item_id,
            COALESCE(c.location_id,1) AS location_id,ti.quantity,ti.unit_price,ti.total_price,
            CASE WHEN t.type='sale' THEN ROUND(ti.total_price*t.total_amount/NULLIF(s.line_total,0),2)
                WHEN t.type='return' AND LOWER(COALESCE(t.entity_type,'')) NOT IN ('supplier','vendor') THEN -ti.total_price ELSE 0 END AS revenue,
            CASE WHEN t.type='sale' THEN ti.quantity*IF(ti.cost_recorded=1,ti.cost_price,i.cost)
                WHEN t.type='return' AND LOWER(COALESCE(t.entity_type,'')) NOT IN ('supplier','vendor')
                THEN -ti.quantity*COALESCE(IF(o.recorded=1,o.cost,NULL),i.cost) ELSE 0 END AS cogs,
            CASE WHEN t.type='purchase' THEN ti.total_price ELSE 0 END AS purchase_value,
            CASE WHEN t.type IN ('purchase','opening_stock','adjustment') THEN ti.quantity WHEN t.type='sale' THEN -ti.quantity
                WHEN t.type='return' THEN IF(LOWER(t.entity_type) IN ('supplier','vendor'),-ti.quantity,ti.quantity) ELSE 0 END AS movement
        FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id JOIN item_list i ON i.id=ti.item_id
        LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id
        LEFT JOIN (SELECT transaction_id,SUM(total_price) AS line_total FROM transaction_items GROUP BY transaction_id) s ON s.transaction_id=t.id
        LEFT JOIN (SELECT transaction_id,item_id,MIN(cost_recorded) AS recorded,SUM(quantity*cost_price)/NULLIF(SUM(quantity),0) AS cost
            FROM transaction_items GROUP BY transaction_id,item_id) o ON o.transaction_id=t.parent_id AND o.item_id=ti.item_id");
    foreach (['erp_stock_transfers','erp_stock_transfer_lines','erp_pos_checkouts','erp_credit_allocations'] as $table) {
        foreach (['UPDATE','DELETE'] as $event) {
            $name = $table . '_' . strtolower($event);
            if (!$conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows) {
                $conn->query("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recorded transfers, receipts, and credit applications are immutable. Use a reversal.'");
            }
        }
    }
    foreach (['erp_transaction_context'=>'transaction_id','erp_line_details'=>'line_id','erp_po_context'=>'order_id','erp_po_line_units'=>'order_line_id'] as $table=>$column) {
        foreach (['INSERT','UPDATE','DELETE'] as $event) {
            $name=$table.'_guard_'.strtolower($event);
            if ($conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows) continue;
            $references=$event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'];$checks=[];
            foreach ($references as $reference) {
                $key=$reference.'.'.$column;
                $checks[]=match ($table) {
                    'erp_transaction_context'=>"EXISTS(SELECT 1 FROM gl_journals WHERE source_transaction_id=$key AND status='posted')",
                    'erp_line_details'=>"EXISTS(SELECT 1 FROM transaction_items i JOIN gl_journals j ON j.source_transaction_id=i.transaction_id AND j.status='posted' WHERE i.id=$key)",
                    'erp_po_context'=>"EXISTS(SELECT 1 FROM po_orders WHERE id=$key AND status NOT IN ('draft','rejected'))",
                    'erp_po_line_units'=>"EXISTS(SELECT 1 FROM po_lines l JOIN po_orders o ON o.id=l.order_id WHERE l.id=$key AND o.status NOT IN ('draft','rejected'))",
                };
            }
            $condition=implode(' OR ',$checks);
            $conn->query("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted document and submitted order metadata is immutable.'; END IF; END");
        }
    }
    $conn->query("INSERT INTO erp_schema_versions(version) VALUES ('003_friend_features')");
    echo "Applied locations, packaging, promotions, activities, preferences, roles, and fiscal-year metadata.\n";
}
