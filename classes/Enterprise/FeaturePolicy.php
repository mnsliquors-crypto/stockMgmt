<?php
namespace App\Enterprise;
require_once __DIR__.'/FeatureRegistry.php';
require_once __DIR__.'/Access.php';

final class FeaturePolicy {
    private array $registry;
    private array $flags=[];
    private array $permissions=[];
    private array $scopeCache=[];
    private array $decisions=[];
    private int $type=0;
    private int $role=0;
    public static function available(\mysqli $db): bool {
        static $cache;$cache??=new \WeakMap();
        if(!isset($cache[$db]))$cache[$db]=$db->query("SHOW TABLES LIKE 'erp_feature_settings'")->num_rows>0&&$db->query("SELECT 1 FROM erp_schema_versions WHERE version='006_feature_controls'")->num_rows>0;
        return $cache[$db];
    }
    public function __construct(private \mysqli $db,private int $user){
        $this->registry=FeatureRegistry::all();
        $stmt=$db->prepare('SELECT u.type,r.role_id FROM users u LEFT JOIN erp_user_roles r ON r.user_id=u.id WHERE u.id=?');$stmt->execute([$user]);$row=$stmt->get_result()->fetch_assoc();
        $this->type=(int)($row['type']??0);if(!in_array($this->type,[1,2],true))return;
        if(!self::available($db))return;
        foreach($db->query('SELECT feature_key,enabled FROM erp_feature_flags')->fetch_all(MYSQLI_ASSOC)as $flag)$this->flags[$flag['feature_key']]=(bool)$flag['enabled'];
        if($this->type===2){
            $this->role=(int)($row['role_id']??$db->query('SELECT default_role_id FROM erp_feature_settings WHERE id=1')->fetch_assoc()['default_role_id']??0);
            $stmt=$db->prepare('SELECT active FROM erp_access_roles WHERE id=?');$stmt->execute([$this->role]);if(!(int)($stmt->get_result()->fetch_assoc()['active']??0)){$this->role=0;return;}
            $stmt=$db->prepare('SELECT feature_key,action,allowed FROM erp_feature_permissions WHERE role_id=?');$stmt->execute([$this->role]);
            foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC)as $entry)$this->permissions[$entry['feature_key']][$entry['action']]=(bool)$entry['allowed'];
        }
    }
    public static function seedRole(\mysqli $db,int $role): void {
        if(!self::available($db))return;$statement=$db->prepare('INSERT IGNORE INTO erp_feature_permissions(role_id,feature_key,action,allowed) VALUES(?,?,?,1)');
        foreach(FeatureRegistry::all()as $key=>$feature)if(!$feature['protected'])foreach($feature['actions']as $action)$statement->execute([$role,$key,$action]);
    }
    public function flagsAllow(string $key): bool {
        if(!isset($this->registry[$key]))return false;
        $feature=$this->registry[$key];if($feature['protected'])return true;
        if(!($this->flags[$key]??false))return false;
        return !$feature['parent']||$this->flagsAllow($feature['parent']);
    }
    public function can(string $key,string $action='view'): bool {
        if(isset($this->decisions[$key][$action]))return $this->decisions[$key][$action];
        return $this->decisions[$key][$action]=$this->evaluate($key,$action);
    }
    private function scopeAllowed(string $scope,bool $write): bool {
        $key=$scope.':'.(int)$write;
        return $this->scopeCache[$key]??=Access::allowed($this->db,$this->user,$scope,$write);
    }
    private function evaluate(string $key,string $action): bool {
        $feature=$this->registry[$key]??null;
        if(!$feature||!in_array($action,$feature['actions'],true)||!in_array($this->type,[1,2],true))return false;
        if($feature['protected'])return $key!=='admin.features'||$this->type===1;
        if(!self::available($this->db))return true;
        if(!$this->flagsAllow($key))return false;
        if($this->type===1)return true;
        $scope=$feature['scope'];
        $write=!in_array($action,['view','search','filter','refresh','print','export','download','copy','open_whatsapp'],true);
        if($scope==='admin')return false;
        if($scope==='any'){
            $allowed=false;foreach(['inventory','sales','purchases','accounting']as $candidate)if($this->scopeAllowed($candidate,$write)){$allowed=true;break;}
            if(!$allowed)return false;
        }elseif($scope!=='app'&&!$this->scopeAllowed($scope,$write))return false;
        if(!($this->permissions[$key][$action]??false))return false;
        return !$feature['parent']||$key==='inventory.catalog.record_usage'||$this->can($feature['parent'],'view');
    }
    public function map(): array {
        $map=[];foreach($this->registry as $key=>$feature)foreach($feature['actions']as $action)$map[$key][$action]=$this->can($key,$action);return $map;
    }
    public function require(string $key,string $action='view'): void {if(!$this->can($key,$action))\deny_request(403,'This feature or action is not available to your account.');}
}
