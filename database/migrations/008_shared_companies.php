<?php
// Draft migration: intentionally excluded from the live migration runner.
require_once __DIR__.'/../../classes/Enterprise/FeatureRegistry.php';
function migrate_shared_companies(mysqli $db): void {
    $admins=$db->query("SELECT id FROM users WHERE username='admin' AND type=1")->fetch_all(MYSQLI_ASSOC);
    if(count($admins)!==1) throw new RuntimeException('Exactly one existing admin account is required for platform ownership.');
    $db->query("CREATE TABLE IF NOT EXISTS platform_company_types(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,name VARCHAR(100) NOT NULL,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_companies(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,company_type_id INT NOT NULL,code VARCHAR(48) NOT NULL UNIQUE,name VARCHAR(160) NOT NULL,email VARCHAR(255) NOT NULL DEFAULT '',phone VARCHAR(50) NOT NULL DEFAULT '',address TEXT NOT NULL,status ENUM('active','blocked') NOT NULL DEFAULT 'active',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(company_type_id) REFERENCES platform_company_types(id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_features(feature_key VARCHAR(150) NOT NULL PRIMARY KEY,label VARCHAR(200) NOT NULL,type VARCHAR(20) NOT NULL,parent_key VARCHAR(150) NULL,protected TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_type_permissions(company_type_id INT NOT NULL,feature_key VARCHAR(150) NOT NULL,action VARCHAR(40) NOT NULL,allowed TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(company_type_id,feature_key,action),FOREIGN KEY(company_type_id) REFERENCES platform_company_types(id),FOREIGN KEY(feature_key) REFERENCES platform_features(feature_key)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_company_permissions(company_id INT NOT NULL,feature_key VARCHAR(150) NOT NULL,action VARCHAR(40) NOT NULL,allowed TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(company_id,feature_key,action),FOREIGN KEY(company_id) REFERENCES platform_companies(id),FOREIGN KEY(feature_key) REFERENCES platform_features(feature_key)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_roles(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id INT NOT NULL,name VARCHAR(100) NOT NULL,is_default TINYINT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,UNIQUE KEY(company_id,name),UNIQUE KEY(id,company_id),FOREIGN KEY(company_id) REFERENCES platform_companies(id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_role_permissions(role_id INT NOT NULL,feature_key VARCHAR(150) NOT NULL,action VARCHAR(40) NOT NULL,allowed TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(role_id,feature_key,action),FOREIGN KEY(role_id) REFERENCES platform_roles(id),FOREIGN KEY(feature_key) REFERENCES platform_features(feature_key)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_memberships(user_id INT NOT NULL PRIMARY KEY,company_id INT NULL,role_id INT NULL,is_company_admin TINYINT NOT NULL DEFAULT 0,is_super_admin TINYINT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,UNIQUE KEY(user_id,company_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(company_id) REFERENCES platform_companies(id),FOREIGN KEY(role_id,company_id) REFERENCES platform_roles(id,company_id),CHECK ((is_super_admin=1 AND company_id IS NULL AND role_id IS NULL AND is_company_admin=0) OR (is_super_admin=0 AND company_id IS NOT NULL))) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_user_blocks(user_id INT NOT NULL,company_id INT NOT NULL,feature_key VARCHAR(150) NOT NULL,action VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,feature_key,action),FOREIGN KEY(user_id,company_id) REFERENCES platform_memberships(user_id,company_id),FOREIGN KEY(feature_key) REFERENCES platform_features(feature_key)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_audit_events(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,actor_id INT NOT NULL,company_id INT NULL,action VARCHAR(80) NOT NULL,object_type VARCHAR(80) NOT NULL,object_id VARCHAR(80) NOT NULL,details JSON NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(actor_id) REFERENCES users(id),FOREIGN KEY(company_id) REFERENCES platform_companies(id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE IF NOT EXISTS platform_impersonation_sessions(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,super_admin_id INT NOT NULL,target_user_id INT NOT NULL,company_id INT NOT NULL,started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,ended_at TIMESTAMP NULL DEFAULT NULL,FOREIGN KEY(super_admin_id) REFERENCES users(id),FOREIGN KEY(target_user_id,company_id) REFERENCES platform_memberships(user_id,company_id),FOREIGN KEY(company_id) REFERENCES platform_companies(id)) ENGINE=InnoDB");
    $db->query("INSERT IGNORE INTO platform_company_types(code,name,active) VALUES('retailer','Retailer',1)");
    $retail=(int)$db->query("SELECT id FROM platform_company_types WHERE code='retailer'")->fetch_assoc()['id'];
    $feature=$db->prepare('INSERT IGNORE INTO platform_features(feature_key,label,type,parent_key,protected) VALUES(?,?,?,?,?)');
    $permission=$db->prepare('INSERT IGNORE INTO platform_type_permissions(company_type_id,feature_key,action,allowed) VALUES(?,?,?,?)');
    foreach(App\Enterprise\FeatureRegistry::all() as $key=>$entry){
        $feature->execute([$key,$entry['label'],$entry['type'],$entry['parent'],(int)$entry['protected']]);
        foreach($entry['actions'] as $action){
            $platformOnly=str_starts_with($key,'admin.')&&!in_array($key,['admin.users','admin.roles','admin.roles.assign','admin.settings','admin.reference_codes'],true);
            $permission->execute([$retail,$key,$action,$platformOnly?0:1]);
        }
    }
    $super=(int)$admins[0]['id'];
    $statement=$db->prepare('INSERT IGNORE INTO platform_memberships(user_id,is_super_admin) VALUES(?,1)');$statement->execute([$super]);
    $statement=$db->prepare('SELECT is_super_admin,company_id,active FROM platform_memberships WHERE user_id=?');$statement->execute([$super]);$owner=$statement->get_result()->fetch_assoc();
    if(!$owner || !(int)$owner['is_super_admin'] || $owner['company_id']!==null || !(int)$owner['active'])throw new RuntimeException('The existing admin has a conflicting platform membership.');
}
