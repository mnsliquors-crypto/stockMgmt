<?php

function migrate_purchase_cycle(mysqli $conn): void {
    if ($conn->query("SELECT 1 FROM erp_schema_versions WHERE version='004_purchase_cycle'")->num_rows) return;
    $ddl = [
        "CREATE TABLE IF NOT EXISTS po_item_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE,
            order_id BIGINT NOT NULL, transaction_id INT NOT NULL UNIQUE, vendor_id INT NOT NULL,
            received_date DATE NOT NULL, delivery_reference VARCHAR(100) NOT NULL DEFAULT '',
            notes VARCHAR(2000) NOT NULL DEFAULT '', subtotal DECIMAL(18,2) NOT NULL,
            reversal_of BIGINT NULL UNIQUE, request_key VARCHAR(80) NOT NULL UNIQUE,
            payload_hash CHAR(64) NOT NULL, created_by INT NOT NULL, created_username VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(order_id) REFERENCES po_orders(id), FOREIGN KEY(transaction_id) REFERENCES transactions(id),
            FOREIGN KEY(vendor_id) REFERENCES entity_list(id), FOREIGN KEY(reversal_of) REFERENCES po_item_receipts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS po_item_receipt_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, receipt_id BIGINT NOT NULL, order_line_id BIGINT NOT NULL,
            transaction_line_id INT NOT NULL UNIQUE, quantity DECIMAL(18,4) NOT NULL, line_total DECIMAL(18,2) NOT NULL,
            FOREIGN KEY(receipt_id) REFERENCES po_item_receipts(id), FOREIGN KEY(order_line_id) REFERENCES po_lines(id),
            FOREIGN KEY(transaction_line_id) REFERENCES transaction_items(id), UNIQUE KEY receipt_order_line(receipt_id,order_line_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS po_item_receipt_seals (
            receipt_id BIGINT PRIMARY KEY, FOREIGN KEY(receipt_id) REFERENCES po_item_receipts(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS po_vendor_bills (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(80) NOT NULL UNIQUE,
            order_id BIGINT NOT NULL, vendor_id INT NOT NULL, invoice_number VARCHAR(100) NOT NULL,
            bill_date DATE NOT NULL, notes VARCHAR(2000) NOT NULL DEFAULT '',
            subtotal DECIMAL(18,2) NOT NULL, tax DECIMAL(18,2) NOT NULL, total DECIMAL(18,2) NOT NULL,
            status ENUM('draft','recorded','cancelled') NOT NULL DEFAULT 'draft', transaction_id INT NULL UNIQUE,
            version INT NOT NULL DEFAULT 1, request_key VARCHAR(80) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL,
            created_by INT NOT NULL, created_username VARCHAR(255) NOT NULL, recorded_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(order_id) REFERENCES po_orders(id), FOREIGN KEY(vendor_id) REFERENCES entity_list(id),
            FOREIGN KEY(transaction_id) REFERENCES transactions(id), INDEX vendor_invoice(vendor_id,invoice_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS po_vendor_bill_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, bill_id BIGINT NOT NULL, receipt_line_id BIGINT NOT NULL,
            quantity DECIMAL(18,4) NOT NULL, line_total DECIMAL(18,2) NOT NULL,
            FOREIGN KEY(bill_id) REFERENCES po_vendor_bills(id), FOREIGN KEY(receipt_line_id) REFERENCES po_item_receipt_lines(id),
            UNIQUE KEY bill_receipt_line(bill_id,receipt_line_id), INDEX billed_receipt_line(receipt_line_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS po_supplier_invoices (
            vendor_id INT NOT NULL, invoice_number VARCHAR(100) NOT NULL, transaction_id INT NOT NULL UNIQUE,
            PRIMARY KEY(vendor_id,invoice_number), FOREIGN KEY(vendor_id) REFERENCES entity_list(id),
            FOREIGN KEY(transaction_id) REFERENCES transactions(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT IGNORE INTO po_supplier_invoices(vendor_id,invoice_number,transaction_id)
            SELECT vendor_id,invoice_number,transaction_id FROM po_receipts",
        "CREATE TABLE IF NOT EXISTS po_cycle_payments (
            transaction_id INT PRIMARY KEY, bill_id BIGINT NOT NULL, request_key VARCHAR(80) NOT NULL UNIQUE,
            payload_hash CHAR(64) NOT NULL, FOREIGN KEY(transaction_id) REFERENCES transactions(id),
            FOREIGN KEY(bill_id) REFERENCES po_vendor_bills(id)
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $conn->query($sql);
    if (!$conn->query("SELECT id FROM gl_accounts WHERE system_key='grir'")->num_rows) {
        $conn->query("INSERT INTO gl_accounts(code,name,category,system_key) VALUES('2150-GRIR','Goods received not invoiced','liability','grir')");
    }
    $conn->query("CREATE OR REPLACE VIEW po_received_quantities AS
        SELECT order_line_id,SUM(quantity) AS quantity,SUM(line_total) AS amount FROM (
            SELECT order_line_id,quantity,line_total FROM po_receipt_lines
            UNION ALL SELECT order_line_id,quantity,line_total FROM po_item_receipt_lines
        ) movements GROUP BY order_line_id");
    $conn->query("CREATE OR REPLACE VIEW po_invoiced_totals AS
        SELECT order_id,SUM(subtotal) AS subtotal,SUM(tax) AS tax FROM (
            SELECT order_id,subtotal,tax FROM po_receipts
            UNION ALL SELECT order_id,subtotal,tax FROM po_vendor_bills WHERE status='recorded'
        ) invoices GROUP BY order_id");
    $conn->query("CREATE OR REPLACE VIEW erp_purchase_bill_items AS
        SELECT i.id,i.transaction_id,i.item_id,i.quantity,i.unit_price,i.total_price,i.unit,i.cost_price,i.cost_recorded
        FROM transaction_items i JOIN transactions t ON t.id=i.transaction_id WHERE t.type='purchase'
        UNION ALL SELECT -l.id,b.transaction_id,o.item_id,
            CAST(l.quantity*d.unit_factor AS DECIMAL(18,4)),CAST(o.unit_price/d.unit_factor AS DECIMAL(18,4)),
            l.line_total,i.unit,CAST(o.unit_price/d.unit_factor AS DECIMAL(18,4)),1
        FROM po_vendor_bill_lines l JOIN po_vendor_bills b ON b.id=l.bill_id AND b.status='recorded'
        JOIN po_item_receipt_lines r ON r.id=l.receipt_line_id JOIN po_lines o ON o.id=r.order_line_id
        JOIN item_list i ON i.id=o.item_id JOIN erp_line_details d ON d.line_id=r.transaction_line_id");
    $conn->query("CREATE OR REPLACE VIEW erp_operational_lines AS
        SELECT ti.id AS line_id,t.id AS transaction_id,t.transaction_date,t.type,t.entity_id,ti.item_id,
            COALESCE(c.location_id,1) AS location_id,ti.quantity,ti.unit_price,ti.total_price,
            CASE WHEN t.type='sale' THEN ROUND(ti.total_price*t.total_amount/NULLIF(s.line_total,0),2)
                WHEN t.type='return' AND LOWER(COALESCE(t.entity_type,'')) NOT IN ('supplier','vendor') THEN -ti.total_price ELSE 0 END AS revenue,
            CASE WHEN t.type='sale' THEN ti.quantity*IF(ti.cost_recorded=1,ti.cost_price,i.cost)
                WHEN t.type='return' AND LOWER(COALESCE(t.entity_type,'')) NOT IN ('supplier','vendor')
                THEN -ti.quantity*COALESCE(IF(o.recorded=1,o.cost,NULL),i.cost) ELSE 0 END AS cogs,
            CASE WHEN t.type='purchase' THEN ti.total_price ELSE 0 END AS purchase_value,
            CASE WHEN t.type IN ('purchase','item_receipt','opening_stock','adjustment') THEN ti.quantity WHEN t.type='sale' THEN -ti.quantity
                WHEN t.type='return' THEN IF(LOWER(t.entity_type) IN ('supplier','vendor'),-ti.quantity,ti.quantity) ELSE 0 END AS movement
        FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id JOIN item_list i ON i.id=ti.item_id
        LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id
        LEFT JOIN (SELECT transaction_id,SUM(total_price) AS line_total FROM transaction_items GROUP BY transaction_id) s ON s.transaction_id=t.id
        LEFT JOIN (SELECT transaction_id,item_id,MIN(cost_recorded) AS recorded,SUM(quantity*cost_price)/NULLIF(SUM(quantity),0) AS cost
            FROM transaction_items GROUP BY transaction_id,item_id) o ON o.transaction_id=t.parent_id AND o.item_id=ti.item_id
        UNION ALL SELECT i.id,t.id,t.transaction_date,t.type,t.entity_id,i.item_id,COALESCE(c.location_id,1),
            i.quantity,i.unit_price,i.total_price,0,0,i.total_price,0
        FROM erp_purchase_bill_items i JOIN po_vendor_bills b ON b.transaction_id=i.transaction_id AND b.status='recorded'
        JOIN transactions t ON t.id=b.transaction_id LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id");

    $triggers=[];
    foreach (['po_item_receipts','po_item_receipt_lines','po_item_receipt_seals','po_cycle_payments','po_supplier_invoices'] as $table) foreach (['UPDATE','DELETE'] as $event) {
        $triggers[$table.'_'.strtolower($event)]="BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Item receipts are immutable. Record a receipt reversal before billing.'";
    }
    $triggers['pc_receipt_insert']="BEFORE INSERT ON po_item_receipts FOR EACH ROW BEGIN
        IF NOT EXISTS(SELECT 1 FROM transactions t JOIN po_orders o ON o.id=NEW.order_id WHERE t.id=NEW.transaction_id
            AND t.type='item_receipt' AND t.entity_id=o.vendor_id AND NEW.vendor_id=o.vendor_id
            AND (NEW.reversal_of IS NOT NULL OR o.status IN ('approved','part_received'))
            AND t.total_amount=NEW.subtotal AND t.tax=0 AND t.transaction_date=NEW.received_date) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt source must match its approved order and inventory value.'; END IF;
    END";
    $triggers['pc_receipt_line_insert']="BEFORE INSERT ON po_item_receipt_lines FOR EACH ROW BEGIN
        DECLARE ordered DECIMAL(18,4); DECLARE received DECIMAL(18,4); DECLARE reversing BIGINT;
        SELECT o.quantity,h.reversal_of INTO ordered,reversing FROM po_lines o JOIN po_item_receipts h ON h.id=NEW.receipt_id WHERE o.id=NEW.order_line_id AND o.order_id=h.order_id FOR UPDATE;
        IF ordered IS NULL OR EXISTS(SELECT 1 FROM po_item_receipt_seals WHERE receipt_id=NEW.receipt_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt lines must belong to an unsealed receipt and its order.'; END IF;
        IF NOT EXISTS(SELECT 1 FROM transaction_items i JOIN po_item_receipts h ON h.transaction_id=i.transaction_id
            JOIN po_lines o ON o.id=NEW.order_line_id JOIN erp_line_details d ON d.line_id=i.id
            WHERE h.id=NEW.receipt_id AND i.id=NEW.transaction_line_id AND i.item_id=o.item_id
            AND i.quantity=ROUND(NEW.quantity*d.unit_factor,4) AND i.total_price=NEW.line_total) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt stock lines must match their order allocation.'; END IF;
        SELECT COALESCE(SUM(quantity),0) INTO received FROM po_received_quantities WHERE order_line_id=NEW.order_line_id;
        IF NEW.quantity=0 OR (reversing IS NULL AND NEW.quantity<0) OR (reversing IS NOT NULL AND NEW.quantity>0)
            OR received+NEW.quantity>ordered OR received+NEW.quantity<0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt quantity exceeds the outstanding order quantity.'; END IF;
    END";
    $triggers['pc_legacy_receipt_limit']="BEFORE INSERT ON po_receipt_lines FOR EACH ROW BEGIN
        IF NEW.quantity+COALESCE((SELECT quantity FROM po_received_quantities WHERE order_line_id=NEW.order_line_id),0)>
            (SELECT quantity FROM po_lines WHERE id=NEW.order_line_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt quantity exceeds its ordered quantity across all receipts.'; END IF;
    END";
    $triggers['pc_receipt_seal_insert']="BEFORE INSERT ON po_item_receipt_seals FOR EACH ROW BEGIN
        IF NOT EXISTS(SELECT 1 FROM po_item_receipts h WHERE h.id=NEW.receipt_id
            AND h.subtotal=(SELECT SUM(line_total) FROM po_item_receipt_lines WHERE receipt_id=h.id)
            AND (SELECT COUNT(*) FROM po_item_receipt_lines WHERE receipt_id=h.id)=(SELECT COUNT(*) FROM transaction_items WHERE transaction_id=h.transaction_id)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt allocations must match all inventory lines before sealing.'; END IF;
    END";
    $triggers['pc_bill_delete']="BEFORE DELETE ON po_vendor_bills FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cancel draft bills instead of deleting their history.'";
    $triggers['pc_bill_insert']="BEFORE INSERT ON po_vendor_bills FOR EACH ROW BEGIN
        IF NEW.status<>'draft' OR NEW.transaction_id IS NOT NULL OR NEW.subtotal<=0 OR NEW.tax<0 OR NEW.total<>NEW.subtotal+NEW.tax
            OR NOT EXISTS(SELECT 1 FROM po_orders WHERE id=NEW.order_id AND vendor_id=NEW.vendor_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create a draft bill for its purchase order supplier first.'; END IF;
    END";
    $triggers['pc_bill_update']="BEFORE UPDATE ON po_vendor_bills FOR EACH ROW BEGIN
        IF OLD.status<>'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recorded or cancelled bills are immutable.'; END IF;
        IF NEW.order_id<>OLD.order_id OR NEW.vendor_id<>OLD.vendor_id OR NEW.created_by<>OLD.created_by OR NEW.request_key<>OLD.request_key THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bill origin cannot be changed.'; END IF;
        IF NEW.status='recorded' AND NOT EXISTS(SELECT 1 FROM transactions WHERE id=NEW.transaction_id AND type='purchase'
            AND entity_id=NEW.vendor_id AND total_amount=NEW.subtotal AND tax=NEW.tax AND transaction_date=NEW.bill_date) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A recorded bill requires its matching supplier document.'; END IF;
        IF NEW.status='recorded' AND (NEW.subtotal<>(SELECT COALESCE(SUM(line_total),0) FROM po_vendor_bill_lines WHERE bill_id=OLD.id)
            OR NEW.total<>NEW.subtotal+NEW.tax OR NEW.subtotal<=0 OR NEW.tax<0
            OR EXISTS(SELECT 1 FROM po_vendor_bill_lines l JOIN po_item_receipt_lines r ON r.id=l.receipt_line_id
                JOIN po_item_receipts h ON h.id=r.receipt_id LEFT JOIN po_item_receipts x ON x.reversal_of=h.id
                WHERE l.bill_id=OLD.id AND (h.order_id<>NEW.order_id OR h.reversal_of IS NOT NULL OR x.id IS NOT NULL
                    OR l.quantity<=0 OR l.line_total<=0 OR NEW.bill_date<h.received_date
                    OR r.quantity<l.quantity+COALESCE((SELECT SUM(p.quantity) FROM po_vendor_bill_lines p JOIN po_vendor_bills b ON b.id=p.bill_id WHERE p.receipt_line_id=r.id AND b.status='recorded' AND b.id<>OLD.id),0)))) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bill quantities and amounts must match unbilled received items.'; END IF;
    END";
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $checks='';
        foreach($event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'] as $ref) $checks.="IF EXISTS(SELECT 1 FROM po_vendor_bills WHERE id=$ref.bill_id AND status<>'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only draft bill lines can be changed.'; END IF; ";
        $triggers['pc_bill_line_'.strtolower($event)]="BEFORE $event ON po_vendor_bill_lines FOR EACH ROW BEGIN $checks END";
        $checks='';
        foreach($event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'] as $ref) $checks.="IF EXISTS(SELECT 1 FROM po_item_receipts WHERE transaction_id=$ref.transaction_id) OR EXISTS(SELECT 1 FROM po_vendor_bills WHERE transaction_id=$ref.transaction_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purchase cycle source lines are immutable.'; END IF; ";
        $triggers['pc_source_line_'.strtolower($event)]="BEFORE $event ON transaction_items FOR EACH ROW BEGIN $checks END";
    }
    foreach (['UPDATE','DELETE'] as $event) $triggers['pc_source_'.strtolower($event)]="BEFORE $event ON transactions FOR EACH ROW BEGIN
        IF EXISTS(SELECT 1 FROM po_item_receipts WHERE transaction_id=OLD.id) OR EXISTS(SELECT 1 FROM po_vendor_bills WHERE transaction_id=OLD.id) OR EXISTS(SELECT 1 FROM po_cycle_payments WHERE transaction_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purchase cycle documents are immutable. Use their linked workflow.'; END IF;
    END";
    foreach (['INSERT','UPDATE','DELETE'] as $event) {
        $checks='';
        foreach($event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'] as $ref) {
            $checks.="IF EXISTS(SELECT 1 FROM po_item_receipt_lines WHERE transaction_line_id=$ref.line_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt unit snapshots are immutable.'; END IF; ";
        }
        $triggers['pc_units_'.strtolower($event)]="BEFORE $event ON erp_line_details FOR EACH ROW BEGIN $checks END";
        $checks='';
        foreach($event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'] as $ref) {
            $checks.="IF $ref.ref_table='transactions' AND EXISTS(SELECT 1 FROM po_cycle_payments WHERE transaction_id=$ref.ref_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Recorded supplier payments are immutable.'; END IF; ";
        }
        $triggers['pc_cash_'.strtolower($event)]="BEFORE $event ON transaction_list FOR EACH ROW BEGIN $checks END";
        $checks='';
        foreach($event==='UPDATE'?['OLD','NEW']:[$event==='DELETE'?'OLD':'NEW'] as $ref) {
            $checks.="IF EXISTS(SELECT 1 FROM po_item_receipts WHERE transaction_id=$ref.transaction_id) OR EXISTS(SELECT 1 FROM po_vendor_bills WHERE transaction_id=$ref.transaction_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purchase cycle locations are immutable.'; END IF; ";
        }
        $triggers['pc_location_'.strtolower($event)]="BEFORE $event ON erp_transaction_context FOR EACH ROW BEGIN $checks END";
    }
    foreach($triggers as $name=>$body) if(!$conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows)$conn->query("CREATE TRIGGER `$name` $body");
    $conn->query("INSERT INTO erp_schema_versions(version) VALUES('004_purchase_cycle')");
    echo "Applied separate item receipts, supplier bills, and GR/IR accounting.\n";
}
