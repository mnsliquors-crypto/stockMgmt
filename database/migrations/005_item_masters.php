<?php
function migrate_item_masters(mysqli $db): void {
    if($db->query("SELECT 1 FROM erp_schema_versions WHERE version='005_item_masters'")->num_rows)return;
    $definitions=[
        'item_type'=>"ENUM('inventory','assembly','non_inventory','service') NOT NULL DEFAULT 'inventory'",
        'item_usage'=>"ENUM('purchase','sale','resale') NOT NULL DEFAULT 'resale'",
        'sku'=>'VARCHAR(100) NULL UNIQUE','is_matrix_parent'=>'TINYINT NOT NULL DEFAULT 0',
        'matrix_parent_id'=>'INT NULL','variant_key'=>'VARCHAR(64) NULL','variant_options'=>'JSON NULL',
        'catalog_version'=>'INT NOT NULL DEFAULT 1'
    ];
    $columns=array_column($db->query('SHOW COLUMNS FROM item_list')->fetch_all(MYSQLI_ASSOC),'Field');
    foreach($definitions as $name=>$definition)if(!in_array($name,$columns,true))$db->query("ALTER TABLE item_list ADD COLUMN `$name` $definition");
    $ddl=[
        "CREATE TABLE IF NOT EXISTS erp_catalog_settings(id TINYINT PRIMARY KEY,matrix_enabled TINYINT NOT NULL DEFAULT 0,version INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "INSERT IGNORE INTO erp_catalog_settings(id) VALUES(1)",
        "CREATE TABLE IF NOT EXISTS erp_master_usage(record_type ENUM('account','unit','bin','customer','vendor','location','class','department') NOT NULL,record_id INT NOT NULL,item_usage ENUM('purchase','sale','resale') NOT NULL DEFAULT 'resale',version INT NOT NULL DEFAULT 1,PRIMARY KEY(record_type,record_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_units(id INT AUTO_INCREMENT PRIMARY KEY,code VARCHAR(50) NOT NULL UNIQUE,name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_dimensions(id INT AUTO_INCREMENT PRIMARY KEY,kind ENUM('class','department') NOT NULL,code VARCHAR(50) NOT NULL,name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,UNIQUE KEY dimension_code(kind,code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_bins(id INT AUTO_INCREMENT PRIMARY KEY,location_id INT NOT NULL,code VARCHAR(50) NOT NULL,name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,UNIQUE KEY location_bin(location_id,code),FOREIGN KEY(location_id) REFERENCES erp_locations(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_item_defaults(item_id INT PRIMARY KEY,unit_id INT NULL,location_id INT NULL,bin_id INT NULL,class_id INT NULL,department_id INT NULL,inventory_account_id INT NULL,income_account_id INT NULL,expense_account_id INT NULL,FOREIGN KEY(item_id) REFERENCES item_list(id),FOREIGN KEY(unit_id) REFERENCES erp_units(id),FOREIGN KEY(location_id) REFERENCES erp_locations(id),FOREIGN KEY(bin_id) REFERENCES erp_bins(id),FOREIGN KEY(class_id) REFERENCES erp_dimensions(id),FOREIGN KEY(department_id) REFERENCES erp_dimensions(id),FOREIGN KEY(inventory_account_id) REFERENCES gl_accounts(id),FOREIGN KEY(income_account_id) REFERENCES gl_accounts(id),FOREIGN KEY(expense_account_id) REFERENCES gl_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_matrix_axes(item_id INT PRIMARY KEY,axes JSON NOT NULL,FOREIGN KEY(item_id) REFERENCES item_list(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS erp_line_catalog(line_id INT PRIMARY KEY,item_type VARCHAR(20) NOT NULL,inventory_account_id INT NULL,income_account_id INT NULL,expense_account_id INT NULL,bin_id INT NULL,class_id INT NULL,department_id INT NULL,FOREIGN KEY(line_id) REFERENCES transaction_items(id) ON DELETE CASCADE,FOREIGN KEY(inventory_account_id) REFERENCES gl_accounts(id),FOREIGN KEY(income_account_id) REFERENCES gl_accounts(id),FOREIGN KEY(expense_account_id) REFERENCES gl_accounts(id),FOREIGN KEY(bin_id) REFERENCES erp_bins(id),FOREIGN KEY(class_id) REFERENCES erp_dimensions(id),FOREIGN KEY(department_id) REFERENCES erp_dimensions(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
    foreach($ddl as $sql)$db->query($sql);
    if(!$db->query("SHOW INDEX FROM item_list WHERE Key_name='matrix_combination'")->num_rows)$db->query('ALTER TABLE item_list ADD UNIQUE KEY matrix_combination(matrix_parent_id,variant_key), ADD FOREIGN KEY(matrix_parent_id) REFERENCES item_list(id)');
    $db->query("INSERT IGNORE INTO erp_units(code,name) SELECT DISTINCT TRIM(unit),TRIM(unit) FROM item_list WHERE TRIM(COALESCE(unit,''))<>'' AND CHAR_LENGTH(TRIM(unit))<=50");
    $db->query("INSERT IGNORE INTO erp_units(code,name) VALUES('Each','Each')");
    $db->query("CREATE OR REPLACE VIEW erp_stock_items AS SELECT ti.* FROM transaction_items ti JOIN item_list c ON c.id=ti.item_id WHERE c.item_type IN ('inventory','assembly') AND c.is_matrix_parent=0");
    $db->query("CREATE OR REPLACE VIEW erp_inventory_items AS SELECT * FROM item_list WHERE item_type IN ('inventory','assembly') AND is_matrix_parent=0");
    $db->query("CREATE OR REPLACE VIEW erp_operational_expenses AS
        SELECT t.id AS transaction_id,t.transaction_date,COALESCE(c.location_id,1) AS location_id,t.total_amount AS amount
        FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.type='expense'
        UNION ALL SELECT t.id,t.transaction_date,COALESCE(c.location_id,1),SUM(IF(t.type='return',-i.total_price,i.total_price))
        FROM transaction_items i JOIN item_list p ON p.id=i.item_id AND p.item_type IN ('service','non_inventory')
        JOIN transactions t ON t.id=i.transaction_id LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id
        WHERE t.type IN ('purchase','item_receipt') OR (t.type='return' AND LOWER(t.entity_type) IN ('supplier','vendor'))
        GROUP BY t.id,t.transaction_date,c.location_id");
    foreach(['purchase'=>"('purchase','resale')",'sale'=>"('sale','resale')"]as $mode=>$allowed)$db->query("CREATE OR REPLACE VIEW erp_{$mode}_parties AS SELECT e.* FROM entity_list e LEFT JOIN erp_master_usage u ON u.record_id=e.id AND u.record_type=IF(e.entity_type='Supplier','vendor','customer') WHERE COALESCE(u.item_usage,'resale') IN $allowed");
    $db->query("CREATE OR REPLACE VIEW erp_purchasable_items AS SELECT i.* FROM item_list i WHERE item_usage IN ('purchase','resale') AND is_matrix_parent=0 AND (matrix_parent_id IS NULL OR EXISTS(SELECT 1 FROM item_list p WHERE p.id=i.matrix_parent_id AND p.status=1))");
    $db->query("CREATE OR REPLACE VIEW erp_sellable_items AS SELECT i.* FROM item_list i WHERE item_usage IN ('sale','resale') AND is_matrix_parent=0 AND (matrix_parent_id IS NULL OR EXISTS(SELECT 1 FROM item_list p WHERE p.id=i.matrix_parent_id AND p.status=1))");
    $triggers=[];
    foreach(['INSERT','UPDATE']as $event)$triggers['catalog_party_'.strtolower($event)]="BEFORE $event ON transactions FOR EACH ROW BEGIN
        DECLARE buying INT DEFAULT NULL;DECLARE party_kind VARCHAR(20);DECLARE parent_type VARCHAR(50);
        IF NEW.type IN ('purchase','item_receipt','expense') THEN SET buying=1;
        ELSEIF NEW.type='sale' THEN SET buying=0;
        ELSEIF NEW.type IN ('payment','return') THEN
            SELECT type INTO parent_type FROM transactions WHERE id=NEW.parent_id;
            IF parent_type IN ('sale','purchase') THEN SET buying=IF(parent_type='purchase',1,0);
            ELSEIF NEW.type='return' THEN SET buying=IF(LOWER(NEW.entity_type) IN ('supplier','vendor'),1,0); END IF;
        END IF;
        IF buying IS NOT NULL AND EXISTS(SELECT 1 FROM erp_master_usage WHERE record_type=IF(buying=1,'vendor','customer') AND record_id=NEW.entity_id AND item_usage=IF(buying=1,'sale','purchase')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Party usage does not allow this purchase or sale transaction.'; END IF;
    END";
    $triggers['catalog_item_update']="BEFORE UPDATE ON item_list FOR EACH ROW BEGIN
        IF NOT(NEW.unit<=>OLD.unit) AND (EXISTS(SELECT 1 FROM transaction_items WHERE item_id=OLD.id) OR EXISTS(SELECT 1 FROM po_lines WHERE item_id=OLD.id)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Base unit cannot change after an item is used.'; END IF;
        IF NEW.catalog_version=OLD.catalog_version AND (NEW.name<>OLD.name OR NOT(NEW.sku<=>OLD.sku) OR NEW.cost<>OLD.cost OR NEW.selling_price<>OLD.selling_price OR NEW.status<>OLD.status OR NEW.item_type<>OLD.item_type OR NEW.item_usage<>OLD.item_usage OR NEW.unit<>OLD.unit) THEN SET NEW.catalog_version=OLD.catalog_version+1; END IF;
        IF (NEW.item_type<>OLD.item_type OR NEW.is_matrix_parent<>OLD.is_matrix_parent OR NOT(NEW.matrix_parent_id<=>OLD.matrix_parent_id))
            AND (EXISTS(SELECT 1 FROM transaction_items WHERE item_id=OLD.id) OR EXISTS(SELECT 1 FROM po_lines WHERE item_id=OLD.id) OR EXISTS(SELECT 1 FROM item_list WHERE matrix_parent_id=OLD.id)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Item type and matrix structure cannot change after use or variant generation.'; END IF;
        IF OLD.matrix_parent_id IS NOT NULL AND (NEW.item_type<>OLD.item_type OR NEW.item_usage<>OLD.item_usage OR NOT(NEW.variant_key<=>OLD.variant_key) OR NOT(NEW.variant_options<=>OLD.variant_options)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Variant type, usage and options are inherited and immutable.'; END IF;
        IF NEW.item_usage<>OLD.item_usage AND EXISTS(SELECT 1 FROM item_list WHERE matrix_parent_id=OLD.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A matrix family cannot change usage after variants are generated.'; END IF;
    END";
    $triggers['catalog_variant_insert']="BEFORE INSERT ON item_list FOR EACH ROW BEGIN
        IF (NEW.is_matrix_parent=1 OR NEW.matrix_parent_id IS NOT NULL) AND NOT EXISTS(SELECT 1 FROM erp_catalog_settings WHERE id=1 AND matrix_enabled=1) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enable matrix items before creating a matrix family.'; END IF;
        IF NEW.matrix_parent_id IS NOT NULL AND (NEW.is_matrix_parent<>0 OR NEW.variant_key IS NULL OR NEW.variant_options IS NULL OR NOT EXISTS(SELECT 1 FROM item_list WHERE id=NEW.matrix_parent_id AND is_matrix_parent=1 AND matrix_parent_id IS NULL AND item_type=NEW.item_type AND item_usage=NEW.item_usage)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Variant must match its matrix parent type and usage.'; END IF;
    END";
    foreach(['INSERT','UPDATE']as $event){
        $triggers['catalog_line_'.strtolower($event)]="BEFORE $event ON transaction_items FOR EACH ROW BEGIN
            DECLARE kind VARCHAR(20);DECLARE usage_kind VARCHAR(20);DECLARE parent_flag INT;DECLARE document_type VARCHAR(50);
            SELECT item_type,item_usage,is_matrix_parent INTO kind,usage_kind,parent_flag FROM item_list WHERE id=NEW.item_id;
            SELECT type INTO document_type FROM transactions WHERE id=NEW.transaction_id;
            IF parent_flag=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a matrix variant, not its parent template.'; END IF;
            IF EXISTS(SELECT 1 FROM item_list c JOIN item_list p ON p.id=c.matrix_parent_id WHERE c.id=NEW.item_id AND p.status=0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The matrix parent is inactive.'; END IF;
            IF document_type IN ('purchase','item_receipt') AND usage_kind='sale' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This item is for sale only.'; END IF;
            IF document_type='sale' AND usage_kind='purchase' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This item is for purchase only.'; END IF;
            IF document_type IN ('sale','purchase','item_receipt') AND EXISTS(SELECT 1 FROM item_list c LEFT JOIN erp_item_defaults d ON d.item_id=c.id LEFT JOIN erp_units base_unit ON base_unit.code=c.unit JOIN erp_master_usage u
                ON (u.record_type='unit' AND u.record_id=COALESCE(d.unit_id,base_unit.id)) OR (u.record_type='class' AND u.record_id=d.class_id) OR (u.record_type='department' AND u.record_id=d.department_id)
                OR (u.record_type='bin' AND u.record_id=d.bin_id AND COALESCE(d.location_id,1)=COALESCE((SELECT location_id FROM erp_transaction_context WHERE transaction_id=NEW.transaction_id),1))
                WHERE c.id=NEW.item_id AND u.item_usage=IF(document_type='sale','purchase','sale')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Item master defaults do not allow this purchase or sale usage.'; END IF;
            IF document_type IN ('sale','purchase','item_receipt') AND EXISTS(SELECT 1 FROM erp_master_usage WHERE record_type='location'
                AND record_id=COALESCE((SELECT location_id FROM erp_transaction_context WHERE transaction_id=NEW.transaction_id),1) AND item_usage=IF(document_type='sale','purchase','sale')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Location usage does not allow this purchase or sale.'; END IF;
            IF kind IN ('non_inventory','service') THEN
                IF document_type IN ('opening_stock','adjustment') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Non-stock items cannot have stock openings or adjustments.'; END IF;
                SET NEW.cost_price=0,NEW.cost_recorded=1;
                IF document_type='sale' THEN SET NEW.profit=NEW.total_price; END IF;
            END IF;
        END";
    }
    $snapshot="INSERT INTO erp_line_catalog(line_id,item_type,inventory_account_id,income_account_id,expense_account_id,bin_id,class_id,department_id)
        SELECT NEW.id,i.item_type,d.inventory_account_id,d.income_account_id,d.expense_account_id,
            IF(b.active=1 AND b.location_id=COALESCE(c.location_id,1),b.id,NULL),d.class_id,d.department_id
        FROM item_list i LEFT JOIN erp_item_defaults d ON d.item_id=i.id LEFT JOIN erp_bins b ON b.id=d.bin_id
        LEFT JOIN erp_transaction_context c ON c.transaction_id=NEW.transaction_id WHERE i.id=NEW.item_id;";
    $triggers['catalog_line_snapshot']="AFTER INSERT ON transaction_items FOR EACH ROW BEGIN $snapshot END";
    $triggers['catalog_line_resnapshot']="AFTER UPDATE ON transaction_items FOR EACH ROW BEGIN IF NEW.item_id<>OLD.item_id THEN DELETE FROM erp_line_catalog WHERE line_id=NEW.id; $snapshot END IF; END";
    foreach(['UPDATE','DELETE']as $event)$triggers['catalog_snapshot_'.strtolower($event)]="BEFORE $event ON erp_line_catalog FOR EACH ROW BEGIN
        IF EXISTS(SELECT 1 FROM transaction_items i JOIN gl_journals j ON j.source_transaction_id=i.transaction_id AND j.status='posted' WHERE i.id=OLD.line_id)
            OR EXISTS(SELECT 1 FROM po_item_receipt_lines WHERE transaction_line_id=OLD.line_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted item classifications are immutable.'; END IF;
    END";
    foreach($triggers as $name=>$body)if(!$db->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='$name'")->num_rows)$db->query("CREATE TRIGGER `$name` $body");
    // Keep financial quantities for non-stock lines while excluding their physical movement and COGS.
    $view=$db->query('SHOW CREATE VIEW erp_operational_lines')->fetch_assoc()['Create View'];
    $db->query('CREATE OR REPLACE VIEW erp_operational_lines AS SELECT o.line_id,o.transaction_id,o.transaction_date,o.type,o.entity_id,o.item_id,o.location_id,o.quantity,o.unit_price,o.total_price,o.revenue,IF(i.item_type IN (\'inventory\',\'assembly\'),o.cogs,0) AS cogs,o.purchase_value,IF(i.item_type IN (\'inventory\',\'assembly\'),o.movement,0) AS movement FROM ('.substr($view,stripos($view,'select ')).') o JOIN item_list i ON i.id=o.item_id');
    $db->query("INSERT INTO erp_schema_versions(version) VALUES('005_item_masters')");
    echo "Applied item types, item usage, matrix families, units, bins, classes and departments.\n";
}
