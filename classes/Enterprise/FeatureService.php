<?php
namespace App\Enterprise;
require_once __DIR__.'/Service.php';
require_once __DIR__.'/FeaturePolicy.php';
require_once __DIR__.'/RetailRoleTemplates.php';
require_once __DIR__.'/WorkspaceService.php';

final class FeatureService extends Service {
    public function createRetailRoles(): array {
        $this->admin();
        return $this->atomic(function () {
            $templates=RetailRoleTemplates::all();$registry=FeatureRegistry::all();$policy=new FeaturePolicy($this->db,$this->user);
            $created=[];$skipped=[];$unavailable=[];
            foreach($templates as $name=>$template){
                if($this->row('SELECT id FROM erp_access_roles WHERE name=?',[$name])){$skipped[]=$name;continue;}
                $permissions=RetailRoleTemplates::permissions($template);
                foreach($permissions as $key=>$actions)foreach($actions as $action=>$allowed)if($allowed&&!$policy->can($key,$action)){
                    unset($permissions[$key][$action]);$unavailable[$key]=$registry[$key]['label'];
                }
                $scopes=array_fill_keys(WorkspaceService::SCOPES,'none');
                foreach($template['scopes'] as $scope=>$level){if(!array_key_exists($scope,$scopes))throw new \LogicException('Unknown retail scope.');$scopes[$scope]=$level;}
                $this->query('INSERT INTO erp_access_roles(name,description,permissions,active) VALUES(?,?,?,1)',[$name,$template['description'],json_encode($scopes,JSON_THROW_ON_ERROR)]);
                $id=(int)$this->db->insert_id;
                $statement=$this->db->prepare('INSERT INTO erp_feature_permissions(role_id,feature_key,action,allowed) VALUES(?,?,?,?)');
                foreach($registry as $key=>$feature)if(!$feature['protected'])foreach($feature['actions'] as $action)$statement->execute([$id,$key,$action,empty($permissions[$key][$action])?0:1]);
                $this->audit('create_retail_role','access_role',$id,['name'=>$name,'scopes'=>$scopes,'permissions'=>$permissions]);
                $created[]=$name;
            }
            return ['created'=>$created,'skipped'=>$skipped,'unavailable'=>array_values($unavailable)];
        });
    }
    public function state(): array {
        $this->admin();$registry=FeatureRegistry::all();$flags=[];
        foreach($this->rows('SELECT feature_key,enabled FROM erp_feature_flags')as $row)$flags[$row['feature_key']]=(bool)$row['enabled'];
        $settings=$this->row('SELECT * FROM erp_feature_settings WHERE id=1');
        return ['features'=>$registry,'flags'=>$flags,'version'=>(int)$settings['version'],'default_role_id'=>(int)$settings['default_role_id'],
            'roles'=>$this->rows('SELECT id,name,active,version FROM erp_access_roles ORDER BY name')];
    }
    public function role(int $id): array {
        $this->admin();$role=$this->row('SELECT id,name,active,version FROM erp_access_roles WHERE id=?',[$id]);if(!$role)throw new \RuntimeException('Role not found.');
        $permissions=[];foreach($this->rows('SELECT feature_key,action,allowed FROM erp_feature_permissions WHERE role_id=?',[$id])as $row)$permissions[$row['feature_key']][$row['action']]=(bool)$row['allowed'];
        return ['role'=>$role,'permissions'=>$permissions];
    }
    public function saveFlags(array $input): void {
        $this->admin();$registry=FeatureRegistry::all();$changes=$input['changes']??null;
        if(!is_array($changes)||!$changes||count($changes)>count($registry))throw new \InvalidArgumentException('Choose feature changes.');
        $this->atomic(function()use($input,$registry,$changes){
            $settings=$this->row('SELECT * FROM erp_feature_settings WHERE id=1 FOR UPDATE');$this->version($settings,$input['version']??'');$before=[];$after=[];
            foreach($changes as $key=>$raw){
                if(!is_string($key)||!isset($registry[$key])||$registry[$key]['protected']||!is_scalar($raw)||!in_array((string)$raw,['0','1'],true))throw new \InvalidArgumentException('Invalid or protected feature.');
                $old=$this->row('SELECT enabled FROM erp_feature_flags WHERE feature_key=? FOR UPDATE',[$key]);if(!$old)throw new \RuntimeException('Feature is not registered in this installation.');
                $new=(int)$raw;if((int)$old['enabled']===$new)continue;
                $this->query('UPDATE erp_feature_flags SET enabled=? WHERE feature_key=?',[$new,$key]);$before[$key]=(int)$old['enabled'];$after[$key]=$new;
            }
            if($before){$this->query('UPDATE erp_feature_settings SET version=version+1 WHERE id=1');$this->audit('change_feature_flags','feature_settings',1,['before'=>$before,'after'=>$after]);}
        });
    }
    public function saveRolePermissions(array $input): void {
        $this->admin();$registry=FeatureRegistry::all();$changes=$input['changes']??null;
        if(!is_array($changes)||!$changes||count($changes)>1000)throw new \InvalidArgumentException('Choose permission changes.');
        $this->atomic(function()use($input,$registry,$changes){
            $id=$this->id($input['role_id']??'');$role=$this->row('SELECT * FROM erp_access_roles WHERE id=? FOR UPDATE',[$id]);if(!$role)throw new \RuntimeException('Role not found.');$this->version($role,$input['version']??'');
            $policy=new FeaturePolicy($this->db,$this->user);$before=[];$after=[];
            foreach($changes as $entry){
                if(!is_array($entry)||!is_string($entry['feature']??null)||!is_string($entry['action']??null)||!is_scalar($entry['allowed']??null))throw new \InvalidArgumentException('Invalid permission change.');
                $key=$entry['feature'];$action=$entry['action'];$raw=(string)$entry['allowed'];
                if(!isset($registry[$key])||$registry[$key]['protected']||!in_array($action,$registry[$key]['actions'],true)||!in_array($raw,['0','1'],true))throw new \InvalidArgumentException('Invalid or protected permission.');
                $old=$this->row('SELECT allowed FROM erp_feature_permissions WHERE role_id=? AND feature_key=? AND action=? FOR UPDATE',[$id,$key,$action]);$oldValue=(int)($old['allowed']??0);$new=(int)$raw;
                if($new&&!$oldValue&&!$policy->flagsAllow($key))throw new \DomainException('An unavailable feature cannot be granted. Enable it first.');
                if($oldValue===$new&&$old)continue;
                $this->query('INSERT INTO erp_feature_permissions(role_id,feature_key,action,allowed) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)',[$id,$key,$action,$new]);
                $label=$key.':'.$action;$before[$label]=$oldValue;$after[$label]=$new;
            }
            if($before){$this->query('UPDATE erp_access_roles SET version=version+1 WHERE id=?',[$id]);$this->audit('change_feature_permissions','access_role',$id,['before'=>$before,'after'=>$after]);}
        });
    }
}
