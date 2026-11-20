<?php
namespace App\Enterprise;
require_once __DIR__ . '/Service.php';
use App\Accounting\Money;

final class PromotionService extends Service {
    public function promotions(): array {
        return $this->rows("SELECT p.*,CASE WHEN p.status<>'active' THEN p.status WHEN NOW()<p.starts_at THEN 'scheduled'
            WHEN NOW()>p.ends_at THEN 'expired' ELSE 'active' END AS effective_status,
            (SELECT COUNT(*) FROM erp_promotion_items i WHERE i.promotion_id=p.id) AS item_count
            FROM erp_promotions p ORDER BY p.id DESC");
    }
    public function promotion(int $id): array {
        $promotion=$this->row('SELECT * FROM erp_promotions WHERE id=?',[$id]);
        if (!$promotion) throw new \RuntimeException('Promotion not found.');
        $promotion['items']=$this->rows('SELECT p.*,i.name FROM erp_promotion_items p JOIN item_list i ON i.id=p.item_id WHERE p.promotion_id=?',[$id]);
        $promotion['locations']=array_column($this->rows('SELECT location_id FROM erp_promotion_locations WHERE promotion_id=?',[$id]),'location_id');
        return $promotion;
    }
    public function save(array $input): int {
        $this->admin();
        return $this->atomic(function () use ($input) {
            $id=empty($input['id'])?0:$this->id($input['id']);
            $code=strtoupper($this->text($input['code']??'',40,true));
            if (!preg_match('/^[A-Z0-9_-]+$/D',$code)) throw new \InvalidArgumentException('Invalid promotion code.');
            $name=$this->text($input['name']??'',160,true);
            $start=$this->date($input['starts_at']??'',true); $end=$this->date($input['ends_at']??'',true);
            if ($end<$start) throw new \InvalidArgumentException('Promotion end must follow its start.');
            $status=$this->text($input['status']??'draft',10);
            $type=$this->text($input['discount_type']??'',12); $basis=$this->text($input['discount_basis']??'',20);
            if (!in_array($status,['draft','active','inactive'],true) || !in_array($type,['percentage','fixed'],true) || !in_array($basis,['mrp','selling_price'],true)) throw new \InvalidArgumentException('Invalid promotion settings.');
            $discount=$this->decimal($input['discount_value']??'',4,true);
            if ($type==='percentage' && bccomp($discount,'100',4)>0) throw new \InvalidArgumentException('Percentage discounts cannot exceed 100.');
            $min=$this->decimal($input['min_quantity']??'1',4,true);
            $max=($input['max_quantity']??'')===''?null:$this->decimal($input['max_quantity'],4,true);
            if ($max!==null && bccomp($max,$min,4)<0) throw new \InvalidArgumentException('Maximum quantity cannot be below minimum quantity.');
            $priority=filter_var($input['priority']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>9999]]);
            if ($priority===false) throw new \InvalidArgumentException('Priority must be between 0 and 9999.');
            $items=$input['items']??null; $locations=$input['locations']??[];
            if (!is_array($items)||!$items||count($items)>500||!is_array($locations)||count($locations)>100) throw new \InvalidArgumentException('Choose promotion items and valid locations.');
            $clean=[];
            foreach ($items as $item) {
                if (!is_array($item)) throw new \InvalidArgumentException('Invalid promotion item.');
                $itemId=$this->id($item['item_id']??'');
                if (isset($clean[$itemId]) || !$this->row('SELECT id FROM item_list WHERE id=? AND status=1',[$itemId])) throw new \InvalidArgumentException('Choose unique active items.');
                $override=($item['discount_value']??'')===''?null:$this->decimal($item['discount_value']);
                if ($type==='percentage' && $override!==null && bccomp($override,'100',4)>0) throw new \InvalidArgumentException('Percentage overrides cannot exceed 100.');
                $clean[$itemId]=$override;
            }
            $all=($input['all_locations']??'0')==='1'?1:0;
            if (!$all&&!$locations) throw new \InvalidArgumentException('Choose at least one location.');
            $locationIds=[];
            foreach ($locations as $location) {
                $location=$this->id($location);
                if (!$this->row('SELECT id FROM erp_locations WHERE id=? AND active=1',[$location])) throw new \InvalidArgumentException('Choose active locations.');
                $locationIds[$location]=$location;
            }
            $values=[$code,$name,$start,$end,$status,$type,$basis,$discount,$min,$max,$priority,$all];
            if ($id) {
                $old=$this->row('SELECT * FROM erp_promotions WHERE id=? FOR UPDATE',[$id]);
                if (!$old) throw new \RuntimeException('Promotion not found.');
                $this->version($old,$input['version']??'');
                $this->query('UPDATE erp_promotions SET code=?,name=?,starts_at=?,ends_at=?,status=?,discount_type=?,discount_basis=?,discount_value=?,min_quantity=?,max_quantity=?,priority=?,all_locations=?,version=version+1 WHERE id=?',array_merge($values,[$id]));
                $this->query('DELETE FROM erp_promotion_items WHERE promotion_id=?',[$id]);
                $this->query('DELETE FROM erp_promotion_locations WHERE promotion_id=?',[$id]);
            } else {
                $this->query('INSERT INTO erp_promotions(code,name,starts_at,ends_at,status,discount_type,discount_basis,discount_value,min_quantity,max_quantity,priority,all_locations,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',array_merge($values,[$this->user]));
                $id=(int)$this->db->insert_id;
            }
            foreach ($clean as $item=>$override) $this->query('INSERT INTO erp_promotion_items(promotion_id,item_id,discount_value) VALUES (?,?,?)',[$id,$item,$override]);
            if (!$all) foreach ($locationIds as $location) $this->query('INSERT INTO erp_promotion_locations(promotion_id,location_id) VALUES (?,?)',[$id,$location]);
            $this->audit('save_promotion','promotion',$id,['code'=>$code,'status'=>$status,'items'=>array_keys($clean)]);
            return $id;
        });
    }

    public function quote(int $item, string $quantity, int $location=1, ?string $at=null): array {
        $quantity=$this->decimal($quantity,4,true);
        $at=$at===null?date('Y-m-d H:i:s'):$this->date($at,true);
        $product=$this->row('SELECT id,name,selling_price,mrp FROM item_list WHERE id=? AND status=1',[$item]);
        if (!$product || !$this->row('SELECT id FROM erp_locations WHERE id=? AND active=1',[$location])) throw new \InvalidArgumentException('Choose an active item and location.');
        $promotion=$this->row("SELECT p.*,i.discount_value AS item_discount FROM erp_promotions p JOIN erp_promotion_items i ON i.promotion_id=p.id
            WHERE i.item_id=? AND p.status='active' AND ? BETWEEN p.starts_at AND p.ends_at
            AND p.min_quantity<=? AND (p.max_quantity IS NULL OR p.max_quantity>=?)
            AND (p.all_locations=1 OR EXISTS (SELECT 1 FROM erp_promotion_locations l WHERE l.promotion_id=p.id AND l.location_id=?))
            ORDER BY p.priority DESC,p.id DESC LIMIT 1",[$item,$at,$quantity,$quantity,$location]);
        $price=Money::decimal($product['selling_price'],4); $saving='0.00';
        if ($promotion) {
            $base=$promotion['discount_basis']==='mrp'&&bccomp($product['mrp'],'0',4)>0?$product['mrp']:$product['selling_price'];
            $discount=$promotion['item_discount']??$promotion['discount_value'];
            if ($promotion['discount_type']==='percentage') $discount=bcdiv(bcmul($base,$discount,8),'100',8);
            $discounted=Money::decimal(bcsub($base,$discount,8),4);
            if (bccomp($discounted,'0',4)<0) $discounted='0.0000';
            // A promotion cannot raise the normal selling price.
            if (bccomp($discounted,$price,4)<0) $price=$discounted;
            $saving=Money::multiply(bcsub($product['selling_price'],$price,4),$quantity);
        }
        return ['item_id'=>$item,'normal_price'=>$product['selling_price'],'mrp'=>$product['mrp'],'price'=>$price,
            'promotion_id'=>$promotion?(int)$promotion['id']:null,'promotion_name'=>$promotion['name']??null,'saving'=>$saving];
    }
}
