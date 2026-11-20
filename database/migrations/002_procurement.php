<?php

function migrate_procurement(mysqli $conn): void {
    if ($conn->query("SELECT version FROM erp_schema_versions WHERE version='002_procurement'")->num_rows) return;
    $statements = [
        "CREATE TABLE IF NOT EXISTS po_policy (
            id INT PRIMARY KEY, approval_threshold DECIMAL(18,2) NOT NULL DEFAULT 0,
            approver_id INT NULL, require_purchase_orders TINYINT NOT NULL DEFAULT 0, revision INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "INSERT IGNORE INTO po_policy (id) VALUES (1)",
        "CREATE TABLE IF NOT EXISTS po_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE,
            vendor_id INT NOT NULL, vendor_name VARCHAR(250) NOT NULL, order_date DATE NOT NULL,
            expected_date DATE NULL, notes VARCHAR(2000) NOT NULL DEFAULT '',
            status ENUM('draft','pending','rejected','approved','part_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
            tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0, subtotal DECIMAL(18,2) NOT NULL,
            tax DECIMAL(18,2) NOT NULL, total DECIMAL(18,2) NOT NULL,
            created_by INT NOT NULL, created_username VARCHAR(255) NOT NULL,
            approved_by INT NULL, approved_at DATETIME NULL, approval_required TINYINT NOT NULL DEFAULT 1,
            required_approver_id INT NULL, version INT NOT NULL DEFAULT 1,
            request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (vendor_id) REFERENCES entity_list(id), INDEX po_status_date (status,order_date), INDEX po_vendor (vendor_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS po_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT NOT NULL, item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL, unit VARCHAR(50) NOT NULL DEFAULT '',
            quantity DECIMAL(18,4) NOT NULL, unit_price DECIMAL(18,4) NOT NULL, line_total DECIMAL(18,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES po_orders(id), FOREIGN KEY (item_id) REFERENCES item_list(id),
            UNIQUE KEY po_item (order_id,item_id), INDEX po_line_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS po_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE, order_id BIGINT NOT NULL,
            transaction_id INT NOT NULL UNIQUE, vendor_id INT NOT NULL, invoice_number VARCHAR(100) NOT NULL,
            received_date DATE NOT NULL, notes VARCHAR(2000) NOT NULL DEFAULT '',
            subtotal DECIMAL(18,2) NOT NULL, tax DECIMAL(18,2) NOT NULL, total DECIMAL(18,2) NOT NULL,
            request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL,
            created_by INT NOT NULL, created_username VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES po_orders(id), FOREIGN KEY (transaction_id) REFERENCES transactions(id),
            FOREIGN KEY (vendor_id) REFERENCES entity_list(id), UNIQUE KEY po_invoice (vendor_id,invoice_number),
            INDEX receipt_date (received_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS po_receipt_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, receipt_id BIGINT NOT NULL, order_line_id BIGINT NOT NULL,
            quantity DECIMAL(18,4) NOT NULL, line_total DECIMAL(18,2) NOT NULL,
            FOREIGN KEY (receipt_id) REFERENCES po_receipts(id), FOREIGN KEY (order_line_id) REFERENCES po_lines(id),
            UNIQUE KEY receipt_line (receipt_id,order_line_id), INDEX received_order_line (order_line_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS po_receipt_seals (
            receipt_id BIGINT PRIMARY KEY, FOREIGN KEY (receipt_id) REFERENCES po_receipts(id)
        ) ENGINE=InnoDB",
    ];
    foreach ($statements as $sql) $conn->query($sql);

    $triggers = [];
    foreach (['UPDATE','DELETE'] as $event) {
        $triggers['po_receipt_' . strtolower($event)] = "BEFORE $event ON po_receipts FOR EACH ROW BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipts are immutable. Record a linked supplier return for corrections.'; END";
        $triggers['po_receipt_line_' . strtolower($event)] = "BEFORE $event ON po_receipt_lines FOR EACH ROW BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt lines are immutable.'; END";
        $triggers['po_receipt_seal_' . strtolower($event)] = "BEFORE $event ON po_receipt_seals FOR EACH ROW BEGIN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt seals are immutable.'; END";
    }
    $triggers['po_receipt_insert'] = "BEFORE INSERT ON po_receipts FOR EACH ROW BEGIN
        DECLARE parent_status VARCHAR(20); DECLARE supplier INT;
        SELECT status,vendor_id INTO parent_status,supplier FROM po_orders WHERE id=NEW.order_id FOR UPDATE;
        IF parent_status NOT IN ('approved','part_received') OR supplier<>NEW.vendor_id OR
            NOT EXISTS (SELECT 1 FROM transactions WHERE id=NEW.transaction_id AND type='purchase' AND entity_id=supplier
                AND total_amount=NEW.subtotal AND tax=NEW.tax AND transaction_date=NEW.received_date LOCK IN SHARE MODE) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A receipt requires an approved order and a matching supplier purchase.'; END IF;
    END";
    $triggers['po_receipt_line_insert'] = "BEFORE INSERT ON po_receipt_lines FOR EACH ROW BEGIN
        DECLARE ordered DECIMAL(18,4); DECLARE parent_order BIGINT; DECLARE receipt_order BIGINT;
        SELECT quantity,order_id INTO ordered,parent_order FROM po_lines WHERE id=NEW.order_line_id FOR UPDATE;
        SELECT order_id INTO receipt_order FROM po_receipts WHERE id=NEW.receipt_id FOR UPDATE;
        IF EXISTS (SELECT 1 FROM po_receipt_seals WHERE receipt_id=NEW.receipt_id LOCK IN SHARE MODE) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A completed receipt cannot accept more lines.'; END IF;
        IF parent_order<>receipt_order OR NEW.quantity<=0 OR NEW.line_total<0 OR
            COALESCE((SELECT SUM(quantity) FROM po_receipt_lines WHERE order_line_id=NEW.order_line_id),0)+NEW.quantity>ordered THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt quantity must be positive and cannot exceed its ordered quantity.'; END IF;
    END";
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $checks = '';
        foreach ($event === 'UPDATE' ? ['OLD','NEW'] : [$event === 'INSERT' ? 'NEW' : 'OLD'] as $row) {
            $checks .= "SELECT status INTO order_status FROM po_orders WHERE id=$row.order_id FOR UPDATE;
                IF order_status NOT IN ('draft','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only draft or rejected order lines can be changed.'; END IF; ";
        }
        $triggers['po_line_' . strtolower($event)] = "BEFORE $event ON po_lines FOR EACH ROW BEGIN DECLARE order_status VARCHAR(20); $checks END";
    }
    $triggers['po_order_delete'] = "BEFORE DELETE ON po_orders FOR EACH ROW BEGIN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cancel purchase orders instead of deleting their history.'; END";
    $triggers['po_order_update'] = "BEFORE UPDATE ON po_orders FOR EACH ROW BEGIN
        IF OLD.status NOT IN ('draft','rejected') AND
            (NOT (NEW.vendor_id <=> OLD.vendor_id) OR NOT (NEW.vendor_name <=> OLD.vendor_name)
            OR NOT (NEW.order_date <=> OLD.order_date) OR NOT (NEW.expected_date <=> OLD.expected_date)
            OR NOT (NEW.notes <=> OLD.notes) OR NOT (NEW.tax_rate <=> OLD.tax_rate)
            OR NOT (NEW.subtotal <=> OLD.subtotal) OR NOT (NEW.tax <=> OLD.tax) OR NOT (NEW.total <=> OLD.total)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Submitted order terms are locked. Reject or cancel the order before changing terms.'; END IF;
        IF NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.created_username <=> OLD.created_username)
            OR NOT (NEW.request_key <=> OLD.request_key) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purchase order origin cannot be changed.'; END IF;
        IF NEW.status='approved' AND NEW.approval_required=1 AND (NEW.approved_by IS NULL OR NEW.approved_by=NEW.created_by) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A different administrator must approve this order.'; END IF;
    END";
    // Receipt-linked purchases are protected even before their accounting journal is posted.
    foreach (['UPDATE','DELETE'] as $event) {
        $triggers['po_purchase_' . strtolower($event)] = "BEFORE $event ON transactions FOR EACH ROW BEGIN
            IF EXISTS (SELECT 1 FROM po_receipts WHERE transaction_id=OLD.id LOCK IN SHARE MODE) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This purchase belongs to a goods receipt. Use a linked supplier return.'; END IF; END";
    }
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $checks = '';
        foreach ($event === 'UPDATE' ? ['OLD','NEW'] : [$event === 'INSERT' ? 'NEW' : 'OLD'] as $row) {
            $checks .= "SELECT id INTO parent_lock FROM transactions WHERE id=$row.transaction_id FOR UPDATE;
                IF EXISTS (SELECT 1 FROM po_receipts WHERE transaction_id=$row.transaction_id LOCK IN SHARE MODE) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Goods receipt purchase lines are immutable.'; END IF; ";
        }
        $triggers['po_purchase_line_' . strtolower($event)] = "BEFORE $event ON transaction_items FOR EACH ROW BEGIN DECLARE parent_lock INT; $checks END";
    }
    foreach ($triggers as $name => $body) {
        if (!$conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows) {
            $conn->query("CREATE TRIGGER `$name` $body");
        }
    }
    $conn->query("INSERT INTO erp_schema_versions (version) VALUES ('002_procurement')");
    echo "Applied purchase orders, approval policy, and immutable goods receipts.\n";
}
