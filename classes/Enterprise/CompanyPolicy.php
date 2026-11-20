<?php
namespace App\Enterprise;
require_once __DIR__.'/FeatureRegistry.php';

// Draft evaluator only. The existing application does not yet call this service.
final class CompanyPolicy {
    private array $features;
    private ?array $actor;
    private array $cache=[];
    public function __construct(private \mysqli $db,int $user) {
        $this->features=FeatureRegistry::all();
        $query=$db->prepare('SELECT m.*,c.company_type_id,c.status AS company_status,t.active AS type_active,r.active AS role_active FROM platform_memberships m LEFT JOIN platform_companies c ON c.id=m.company_id LEFT JOIN platform_company_types t ON t.id=c.company_type_id LEFT JOIN platform_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.user_id=?');
        $query->execute([$user]);$this->actor=$query->get_result()->fetch_assoc();
    }
    private function permission(string $table,string $column,int $id,string $feature,string $action): bool {
        $statement=$this->db->prepare("SELECT allowed FROM $table WHERE $column=? AND feature_key=? AND action=?");
        $statement->execute([$id,$feature,$action]);return (bool)($statement->get_result()->fetch_assoc()['allowed']??false);
    }
    public function can(string $feature,string $action='view'): bool {
        if(isset($this->cache[$feature][$action]))return $this->cache[$feature][$action];
        return $this->cache[$feature][$action]=$this->evaluate($feature,$action);
    }
    private function evaluate(string $key,string $action): bool {
        $feature=$this->features[$key]??null;$actor=$this->actor;
        if(!$feature||!in_array($action,$feature['actions'],true)||!$actor||!(int)$actor['active'])return false;
        if((int)$actor['is_super_admin'])return true;
        if($actor['company_status']!=='active'||!(int)$actor['type_active'])return false;
        if(in_array($key,['app.login','app.logout','app.profile'],true))return true;
        if($feature['protected'])return false;
        if(!$this->permission('platform_type_permissions','company_type_id',(int)$actor['company_type_id'],$key,$action))return false;
        if(!$this->permission('platform_company_permissions','company_id',(int)$actor['company_id'],$key,$action))return false;
        if(!(int)$actor['is_company_admin']){
            if(!(int)$actor['role_id']||!(int)$actor['role_active']||!$this->permission('platform_role_permissions','role_id',(int)$actor['role_id'],$key,$action))return false;
        }
        $block=$this->db->prepare('SELECT 1 FROM platform_user_blocks WHERE user_id=? AND company_id=? AND feature_key=? AND action=?');
        $block->execute([(int)$actor['user_id'],(int)$actor['company_id'],$key,$action]);if($block->get_result()->num_rows)return false;
        return !$feature['parent']||$this->can($feature['parent'],'view');
    }
    public function canAccessCompany(int $company): bool {
        return $this->actor && (int)$this->actor['active'] && ((int)$this->actor['is_super_admin'] || ((int)$this->actor['company_id']===$company && $this->actor['company_status']==='active' && (int)$this->actor['type_active']));
    }
}
