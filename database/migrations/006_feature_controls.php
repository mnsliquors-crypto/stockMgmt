<?php
require_once __DIR__.'/../../classes/Enterprise/FeatureRegistry.php';
function migrate_feature_controls(mysqli $db): void {
    if($db->query("SELECT 1 FROM erp_schema_versions WHERE version='006_feature_controls'")->num_rows)return;
    $db->query("CREATE TABLE IF NOT EXISTS erp_feature_flags(feature_key VARCHAR(150) PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE TABLE IF NOT EXISTS erp_feature_permissions(role_id INT NOT NULL,feature_key VARCHAR(150) NOT NULL,action VARCHAR(40) NOT NULL,allowed TINYINT NOT NULL DEFAULT 0,PRIMARY KEY(role_id,feature_key,action),FOREIGN KEY(role_id) REFERENCES erp_access_roles(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE TABLE IF NOT EXISTS erp_feature_settings(id TINYINT PRIMARY KEY,default_role_id INT NOT NULL,version INT NOT NULL DEFAULT 1,FOREIGN KEY(default_role_id) REFERENCES erp_access_roles(id)) ENGINE=InnoDB");
    if(!$db->query('SELECT 1 FROM erp_feature_settings WHERE id=1')->num_rows){
        $name='Default Staff Access';$suffix=1;$check=$db->prepare('SELECT id FROM erp_access_roles WHERE name=?');
        while(true){$check->execute([$name]);if(!$check->get_result()->num_rows)break;$name='Default Staff Access '.(++$suffix);}
        $permissions=[];foreach(['dashboard','inventory','sales','purchases','payments','expenses','procurement','accounting','promotions','activities','reports','assistant']as $scope)$permissions[$scope]='write';
        $stmt=$db->prepare('INSERT INTO erp_access_roles(name,description,permissions,active) VALUES(?,?,?,1)');$stmt->execute([$name,'Preserves existing default staff access.',json_encode($permissions,JSON_THROW_ON_ERROR)]);
        $role=(int)$db->insert_id;$stmt=$db->prepare('INSERT INTO erp_feature_settings(id,default_role_id) VALUES(1,?)');$stmt->execute([$role]);
    }
    $features=App\Enterprise\FeatureRegistry::all();$flag=$db->prepare('INSERT IGNORE INTO erp_feature_flags(feature_key,enabled) VALUES(?,1)');
    $permission=$db->prepare('INSERT IGNORE INTO erp_feature_permissions(role_id,feature_key,action,allowed) VALUES(?,?,?,1)');
    $roles=array_column($db->query('SELECT id FROM erp_access_roles')->fetch_all(MYSQLI_ASSOC),'id');
    foreach($features as $key=>$feature){
        if($feature['protected'])continue;$flag->execute([$key]);
        foreach($roles as $role)foreach($feature['actions']as $action)$permission->execute([$role,$key,$action]);
    }
    $db->query("INSERT INTO erp_schema_versions(version) VALUES('006_feature_controls')");
    echo "Applied single-business feature controls and preserved existing role access.\n";
}
