<?php
namespace App\Enterprise;
require_once __DIR__.'/Service.php';
require_once __DIR__.'/FeatureRegistry.php';

// Draft platform operations; not exposed to the running application.
final class CompanyAdministration extends Service {
    private function superAdmin(): void {
        if(!$this->row('SELECT user_id FROM platform_memberships WHERE user_id=? AND is_super_admin=1 AND active=1 AND company_id IS NULL',[$this->user]))throw new \DomainException('Super Admin access required.');
    }
    private function platformAudit(string $action,int $company,array $details): void {
        $this->query('INSERT INTO platform_audit_events(actor_id,company_id,action,object_type,object_id,details) VALUES(?,?,?,?,?,?)',[$this->user,$company,$action,'company',(string)$company,json_encode($details,JSON_THROW_ON_ERROR)]);
    }
    public function companies(): array {
        $this->superAdmin();return $this->rows('SELECT c.*,t.name AS type_name FROM platform_companies c JOIN platform_company_types t ON t.id=c.company_type_id ORDER BY c.name,c.id');
    }
    public function create(array $input): int {
        $this->superAdmin();
        return $this->atomic(function()use($input){
            $code=strtolower($this->text($input['code']??'',48,true));if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D',$code))throw new \InvalidArgumentException('Use a business code containing letters, numbers, hyphens or underscores.');
            $name=$this->text($input['name']??'',160,true);$email=$this->text($input['email']??'',255);$phone=$this->text($input['phone']??'',50);$address=$this->text($input['address']??'',1000);
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Enter a valid email address.');
            $type=$this->id($input['company_type_id']??'');if(!$this->row('SELECT id FROM platform_company_types WHERE id=? AND active=1',[$type]))throw new \InvalidArgumentException('Choose an active company type.');
            $this->query('INSERT INTO platform_companies(company_type_id,code,name,email,phone,address) VALUES(?,?,?,?,?,?)',[$type,$code,$name,$email,$phone,$address]);$company=(int)$this->db->insert_id;
            $this->query('INSERT INTO platform_company_permissions(company_id,feature_key,action,allowed) SELECT ?,feature_key,action,allowed FROM platform_type_permissions WHERE company_type_id=?',[$company,$type]);
            $this->platformAudit('create_company',$company,['code'=>$code,'name'=>$name,'company_type_id'=>$type]);return $company;
        });
    }
    public function setStatus(int $company,string $status): void {
        $this->superAdmin();if(!in_array($status,['active','blocked'],true))throw new \InvalidArgumentException('Invalid company status.');
        $this->atomic(function()use($company,$status){$old=$this->row('SELECT status FROM platform_companies WHERE id=? FOR UPDATE',[$company]);if(!$old)throw new \RuntimeException('Company not found.');if($old['status']===$status)return;
            $this->query('UPDATE platform_companies SET status=? WHERE id=?',[$status,$company]);$this->platformAudit('change_company_status',$company,['before'=>$old['status'],'after'=>$status]);});
    }
    public function setPermission(int $company,string $feature,string $action,bool $allowed): void {
        $this->superAdmin();$registry=FeatureRegistry::all();$entry=$registry[$feature]??null;
        if(!$entry||$entry['protected']||!in_array($action,$entry['actions'],true))throw new \InvalidArgumentException('Invalid or protected feature.');
        $this->atomic(function()use($company,$feature,$action,$allowed){
            $row=$this->row('SELECT company_type_id FROM platform_companies WHERE id=? FOR UPDATE',[$company]);if(!$row)throw new \RuntimeException('Company not found.');
            $type=$this->row('SELECT allowed FROM platform_type_permissions WHERE company_type_id=? AND feature_key=? AND action=?',[$row['company_type_id'],$feature,$action]);
            if($allowed&&!(int)($type['allowed']??0))throw new \DomainException('The company type does not allow this permission.');
            $old=$this->row('SELECT allowed FROM platform_company_permissions WHERE company_id=? AND feature_key=? AND action=? FOR UPDATE',[$company,$feature,$action]);
            $before=(int)($old['allowed']??0);if($old&&$before===(int)$allowed)return;
            $this->query('INSERT INTO platform_company_permissions(company_id,feature_key,action,allowed) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)',[$company,$feature,$action,(int)$allowed]);
            $this->platformAudit('change_company_permission',$company,['feature'=>$feature,'action'=>$action,'before'=>$before,'after'=>(int)$allowed]);
        });
    }
}
