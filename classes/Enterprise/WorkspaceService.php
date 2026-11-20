<?php
namespace App\Enterprise;
require_once __DIR__ . '/Service.php';

final class WorkspaceService extends Service {
    public const SCOPES=['dashboard','inventory','sales','purchases','payments','expenses','procurement','accounting','promotions','activities','reports','assistant'];

    public function options(): array {
        return ['users'=>$this->rows('SELECT id,username FROM users ORDER BY username'),
            'parties'=>$this->rows("SELECT id,display_name,entity_type FROM entity_list WHERE status=1 AND entity_type IN ('Customer','Supplier') ORDER BY display_name"),
            'items'=>$this->rows('SELECT id,name,unit,cost,selling_price,mrp FROM item_list WHERE status=1 ORDER BY name'),
            'accounts'=>$this->rows('SELECT id,name,type FROM account_list WHERE status=1 ORDER BY name')];
    }
    public function preferences(): array {
        $row=$this->row('SELECT * FROM erp_user_preferences WHERE user_id=?',[$this->user]);
        return ['version'=>(int)($row['version']??0),'settings'=>$row?json_decode($row['preferences'],true):['theme'=>'light','widgets'=>[]]];
    }
    public function savePreferences(array $input): void {
        $this->atomic(function () use ($input) {
            $old=$this->preferences(); $this->version($old,$input['version']??'');
            $theme=$this->text($input['theme']??'light',10);
            $widgets=$input['widgets']??[];
            if (!in_array($theme,['light','dark'],true)||!is_array($widgets)||count($widgets)>30) throw new \InvalidArgumentException('Invalid dashboard preferences.');
            $allowed=['trend','accounts','cash','inventory','items','parties','activities','tax','monthly','payments','categories','hourly','gross_profit','expenses','customers','alerts'];
            foreach ($widgets as $widget) if (!is_string($widget)||!in_array($widget,$allowed,true)) throw new \InvalidArgumentException('Unknown dashboard widget.');
            $settings=json_encode(['theme'=>$theme,'widgets'=>array_values(array_unique($widgets))],JSON_THROW_ON_ERROR);
            $this->query('INSERT INTO erp_user_preferences(user_id,preferences) VALUES (?,?) ON DUPLICATE KEY UPDATE preferences=VALUES(preferences),version=version+1',[$this->user,$settings]);
        });
    }

    public function activities(?string $from=null,?string $to=null): array {
        $where='';$values=[];
        if ($from!==null&&$to!==null) { $where=' WHERE a.starts_at<=? AND COALESCE(a.ends_at,a.starts_at)>=?';$values=[$this->date($to).' 23:59:59',$this->date($from).' 00:00:00']; }
        return $this->rows('SELECT a.*,u.username AS assignee,e.display_name AS party_name FROM erp_activities a
            LEFT JOIN users u ON u.id=a.assigned_to LEFT JOIN entity_list e ON e.id=a.party_id'.$where.' ORDER BY a.starts_at DESC,a.id DESC LIMIT 1000',$values);
    }
    public function activity(int $id): array {
        $row=$this->row('SELECT * FROM erp_activities WHERE id=?',[$id]);
        if (!$row) throw new \RuntimeException('Activity not found.');
        return $row;
    }
    public function saveActivity(array $input): int {
        return $this->atomic(function () use ($input) {
            $id=empty($input['id'])?0:$this->id($input['id']);
            $kind=$this->text($input['kind']??'',10);$status=$this->text($input['status']??'open',20);$priority=$this->text($input['priority']??'normal',10);
            if (!in_array($kind,['task','event','call'],true)||!in_array($status,['open','in_progress','completed','cancelled'],true)||!in_array($priority,['low','normal','high'],true)) throw new \InvalidArgumentException('Invalid activity settings.');
            $title=$this->text($input['title']??'',200,true);$notes=$this->text($input['notes']??'',10000);
            $start=$this->date($input['starts_at']??'',true);$end=empty($input['ends_at'])?null:$this->date($input['ends_at'],true);
            if ($end&&$end<$start) throw new \InvalidArgumentException('End time cannot precede start time.');
            $assignee=empty($input['assigned_to'])?null:$this->id($input['assigned_to']);
            $party=empty($input['party_id'])?null:$this->id($input['party_id']);
            if ($assignee&&!$this->row('SELECT id FROM users WHERE id=?',[$assignee])) throw new \InvalidArgumentException('Assignee not found.');
            if ($party&&!$this->row("SELECT id FROM entity_list WHERE id=? AND entity_type IN ('Customer','Supplier')",[$party])) throw new \InvalidArgumentException('Party not found.');
            $values=[$kind,$title,$start,$end,$status,$priority,$assignee,$party,$notes];
            if ($id) {
                $old=$this->activity($id);$this->version($old,$input['version']??'');
                if (!$this->isAdmin()&&(int)$old['created_by']!==$this->user&&(int)$old['assigned_to']!==$this->user) throw new \DomainException('Only the creator, assignee, or administrator can edit this activity.');
                $this->query('UPDATE erp_activities SET kind=?,title=?,starts_at=?,ends_at=?,status=?,priority=?,assigned_to=?,party_id=?,notes=?,version=version+1 WHERE id=?',array_merge($values,[$id]));
            } else {
                $key=$this->key($input['request_key']??'');
                $existing=$this->row('SELECT id,created_by,kind,title,starts_at,ends_at,status,priority,assigned_to,party_id,notes FROM erp_activities WHERE request_key=?',[$key]);
                if ($existing) {
                    $original=array_values(array_intersect_key($existing,array_flip(['kind','title','starts_at','ends_at','status','priority','assigned_to','party_id','notes'])));
                    if ((int)$existing['created_by']!==$this->user||json_encode(array_map(static fn($v)=>$v===null?null:(string)$v,$original))!==json_encode(array_map(static fn($v)=>$v===null?null:(string)$v,$values))) throw new \UnexpectedValueException('Request key already used for another activity.');
                    return (int)$existing['id'];
                }
                $this->query('INSERT INTO erp_activities(kind,title,starts_at,ends_at,status,priority,assigned_to,party_id,notes,created_by,request_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)',array_merge($values,[$this->user,$key]));
                $id=(int)$this->db->insert_id;
            }
            $this->audit('save_activity','activity',$id,['title'=>$title,'status'=>$status,'assigned_to'=>$assignee]);
            return $id;
        });
    }

    public function roles(): array { $this->admin();return $this->rows('SELECT * FROM erp_access_roles ORDER BY name'); }
    public function assignments(): array { $this->admin();return $this->rows('SELECT u.id,u.username,u.type,r.role_id FROM users u LEFT JOIN erp_user_roles r ON r.user_id=u.id ORDER BY u.username'); }
    public function saveRole(array $input): int {
        $this->admin();
        return $this->atomic(function () use ($input) {
            $id=empty($input['id'])?0:$this->id($input['id']);
            $name=$this->text($input['name']??'',100,true);$description=$this->text($input['description']??'',500);
            $raw=$input['permissions']??[];
            if (!is_array($raw)) throw new \InvalidArgumentException('Invalid permissions.');
            $permissions=[];
            foreach (self::SCOPES as $scope) {
                $value=$raw[$scope]??'none';
                if (!in_array($value,['none','read','write'],true)) throw new \InvalidArgumentException('Invalid permission level.');
                $permissions[$scope]=$value;
            }
            $active=($input['active']??'0')==='1'?1:0;
            if ($id) {
                $old=$this->row('SELECT * FROM erp_access_roles WHERE id=?',[$id]);
                if (!$old) throw new \RuntimeException('Role not found.');
                $this->version($old,$input['version']??'');
                $this->query('UPDATE erp_access_roles SET name=?,description=?,permissions=?,active=?,version=version+1 WHERE id=?',[$name,$description,json_encode($permissions),$active,$id]);
            } else {
                $this->query('INSERT INTO erp_access_roles(name,description,permissions,active) VALUES (?,?,?,?)',[$name,$description,json_encode($permissions),$active]);
                $id=(int)$this->db->insert_id;
            }
            $this->audit('save_role','access_role',$id,['permissions'=>$permissions,'active'=>$active]);
            return $id;
        });
    }
    public function assignRole(int $user,?int $role): void {
        $this->admin();
        $this->atomic(function () use ($user,$role) {
            $target=$this->row('SELECT id,type FROM users WHERE id=?',[$user]);
            if (!$target || (int)$target['type']===1) throw new \InvalidArgumentException('Custom roles can be assigned only to staff accounts.');
            if ($role!==null&&!$this->row('SELECT id FROM erp_access_roles WHERE id=? AND active=1',[$role])) throw new \InvalidArgumentException('Choose an active role.');
            $this->query('DELETE FROM erp_user_roles WHERE user_id=?',[$user]);
            if ($role!==null) $this->query('INSERT INTO erp_user_roles(user_id,role_id) VALUES (?,?)',[$user,$role]);
            $this->audit('assign_role','user',$user,['role_id'=>$role]);
        });
    }

    public function health(): array {
        $this->admin();
        $unbalanced=(int)$this->row("SELECT COUNT(*) AS count FROM (SELECT j.id FROM gl_journals j LEFT JOIN gl_journal_lines l ON l.journal_id=j.id WHERE j.status='posted' GROUP BY j.id HAVING COUNT(l.id)<2 OR COALESCE(SUM(l.debit-l.credit),0)<>0) x")['count'];
        $pending=(int)$this->row("SELECT COUNT(*) AS count FROM transactions t WHERE t.type IN ('sale','purchase','return','payment','expense','adjustment','opening_stock','opening_balance','transfer') AND NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.source_transaction_id=t.id AND j.status='posted')")['count'];
        $missing=(int)$this->row("SELECT COUNT(DISTINCT t.id) AS count FROM transactions t JOIN transaction_items i ON i.transaction_id=t.id WHERE t.type='sale' AND i.cost_recorded=0")['count'];
        $negative=(int)$this->row('SELECT COUNT(*) AS count FROM item_list WHERE quantity<0')['count'];
        $orphans=(int)$this->row('SELECT COUNT(*) AS count FROM transaction_items i LEFT JOIN transactions t ON t.id=i.transaction_id LEFT JOIN item_list p ON p.id=i.item_id WHERE t.id IS NULL OR p.id IS NULL')['count'];
        $checks=[['check'=>'Posted journals','status'=>$unbalanced?'failed':'passed','count'=>$unbalanced,'detail'=>'Unbalanced or incomplete posted journals'],
            ['check'=>'Accounting cutover / posting','status'=>$pending?'warning':'passed','count'=>$pending,'detail'=>'Operational documents not yet posted to the general ledger'],
            ['check'=>'Historical sale costs','status'=>$missing?'warning':'passed','count'=>$missing,'detail'=>'Sales missing an original cost snapshot'],
            ['check'=>'Negative aggregate inventory','status'=>$negative?'warning':'passed','count'=>$negative,'detail'=>'Items with negative operational stock'],
            ['check'=>'Inventory references','status'=>$orphans?'failed':'passed','count'=>$orphans,'detail'=>'Orphan transaction or item references']];
        return ['checked_at'=>date('Y-m-d H:i:s'),'status'=>$unbalanced||$orphans?'failed':($pending||$missing||$negative?'warning':'passed'),'checks'=>$checks];
    }

    public function interpret(string $prompt): array {
        $prompt=$this->text($prompt,2000,true);$lower=mb_strtolower($prompt);
        $intent=preg_match('/\b(bought|purchase|supplier)\b/u',$lower)?'purchase':(preg_match('/\b(sold|sale|invoice)\b/u',$lower)?'sale':(preg_match('/\b(paid|expense)\b/u',$lower)?'expense':'search'));
        $items=[];$parties=[];
        foreach ($this->rows('SELECT id,name,cost,selling_price FROM item_list WHERE status=1 ORDER BY CHAR_LENGTH(name) DESC') as $item) if (mb_stripos($prompt,$item['name'])!==false) $items[]=$item;
        foreach ($this->rows("SELECT id,display_name,entity_type FROM entity_list WHERE status=1 AND entity_type IN ('Supplier','Customer') ORDER BY CHAR_LENGTH(display_name) DESC") as $party) {
            if (mb_stripos($prompt,$party['display_name'])!==false && ($intent!=='sale'||$party['entity_type']==='Customer')&&($intent!=='purchase'||$party['entity_type']==='Supplier')) $parties[]=$party;
        }
        preg_match('/\b(\d+(?:\.\d+)?)\s*(cases?|boxes?|bottles?|pcs|pieces?|units?)\b/i',$prompt,$qty);
        preg_match('/\b(?:for|amount|total|rs\.?|npr)\s*([\d,]+(?:\.\d{1,2})?)/i',$prompt,$amount);
        $quantity=$qty[1]??null;$total=isset($amount[1])?str_replace(',','',$amount[1]):null;
        $routes=['purchase'=>'transactions/procurement&new=1','sale'=>'transactions/sales/manage_sale','expense'=>'transactions/expenses/manage_expense','search'=>'master/items'];
        return ['mode'=>'local_rule_based','intent'=>$intent,'quantity'=>$quantity,'unit'=>$qty[2]??null,'amount'=>$total,'items'=>$items,'parties'=>$parties,'route'=>$routes[$intent],'requires_review'=>true];
    }
}
