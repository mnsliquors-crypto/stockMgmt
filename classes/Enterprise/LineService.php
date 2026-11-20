<?php
namespace App\Enterprise;
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/PromotionService.php';
use App\Accounting\Money;

final class LineService extends Service {
    public function prepare(array $input,string $mode): array {
        $items=$input['item_id']??null;$qty=$input['qty']??null;$prices=$input['price']??null;
        if (!is_array($items)||!$items||!is_array($qty)||!is_array($prices)||count($items)>200) throw new \InvalidArgumentException('Enter complete item lines.');
        $inventory=new InventoryService($this->db,$this->user);$promotions=new PromotionService($this->db,$this->user);
        $location=empty($input['location_id'])?1:$this->id($input['location_id']);
        $units=$input['unit_code']??[];
        if (!is_array($units)) throw new \InvalidArgumentException('Invalid item units.');
        $snapshots=[];$totals=[];$quantities=[];$canonicalPrices=[];$skuQuantities=[];
        foreach ($items as $index=>$rawItem) {
            $item=$this->id($rawItem);$quantity=$this->decimal($qty[$index]??'',4,true);$price=$this->decimal($prices[$index]??'');
            if($mode!=='return')CatalogService::assertUsage($this->db,$item,$mode==='purchase');
            if($mode!=='return')CatalogService::assertItemMasters($this->db,$item,$mode==='purchase',$location);
            $code=$this->text($units[$index]??'base',10);
            $unit=$inventory->unit($item,$code,$mode==='purchase');
            if ($mode==='return'&&$code==='case'&&!empty($input['parent_id'])) {
                $sourceUnits=$this->rows('SELECT DISTINCT d.unit_factor,d.unit_name FROM erp_line_details d JOIN transaction_items i ON i.id=d.line_id WHERE i.transaction_id=? AND i.item_id=? AND d.unit_factor>1',[$this->id($input['parent_id']),$item]);
                if (count($sourceUnits)>1) throw new \InvalidArgumentException('The invoice contains different case sizes. Return this item in base units.');
                if ($sourceUnits) {$unit['factor']=$sourceUnits[0]['unit_factor'];$unit['name']=$sourceUnits[0]['unit_name'];}
            }
            if ($mode==='pos'&&!empty($input['checkout_request_key'])) $price=Money::decimal($unit['price'],4);
            $baseQuantity=Money::decimal(bcmul($quantity,$unit['factor'],8),4);
            if (bccomp($baseQuantity,'0',4)<=0) throw new \InvalidArgumentException('Quantity is below base-unit precision.');
            $basePrice=Money::decimal(bcdiv($price,$unit['factor'],12),4);
            $snapshots[$index]=['entered_quantity'=>$quantity,'entered_price'=>$price,'unit_name'=>$unit['name'],'unit_factor'=>$unit['factor'],'promotion_id'=>null,'normal_price'=>null,'promotion_saving'=>'0.00'];
            $totals[$index]=Money::multiply($quantity,$price);$quantities[$index]=$baseQuantity;$canonicalPrices[$index]=$basePrice;
            $skuQuantities[$item]=bcadd($skuQuantities[$item]??'0',$baseQuantity,4);
        }
        $usePromotions=$mode==='pos'||($mode==='sale'&&($input['apply_promotions']??'0')==='1');
        $quotes=[];
        foreach ($items as $index=>$rawItem) {
            $item=(int)$rawItem;
            if ($usePromotions) {
                $quotes[$item]??=$promotions->quote($item,$skuQuantities[$item],$location);
                $quote=$quotes[$item];
                if ($quote['promotion_id']&&bccomp($quote['price'],$canonicalPrices[$index],4)<0) {
                    $canonicalPrices[$index]=$quote['price'];
                    $snapshots[$index]['entered_price']=Money::decimal(bcmul($quote['price'],$snapshots[$index]['unit_factor'],8),4);
                    $snapshots[$index]['promotion_id']=$quote['promotion_id'];
                    $snapshots[$index]['normal_price']=$quote['normal_price'];
                    $snapshots[$index]['promotion_saving']=Money::multiply(bcsub($quote['normal_price'],$quote['price'],4),$quantities[$index]);
                    $totals[$index]=Money::multiply($quantities[$index],$canonicalPrices[$index]);
                }
            }
        }
        $input['qty']=$quantities;$input['price']=$canonicalPrices;
        return ['input'=>$input,'totals'=>$totals,'snapshots'=>$snapshots];
    }

    public static function snapshot(\mysqli $db,int $line,array $details): void {
        $statement=$db->prepare('INSERT INTO erp_line_details(line_id,entered_quantity,entered_price,unit_name,unit_factor,promotion_id,normal_price,promotion_saving) VALUES (?,?,?,?,?,?,?,?)');
        $statement->execute([$line,$details['entered_quantity'],$details['entered_price'],$details['unit_name'],$details['unit_factor'],$details['promotion_id'],$details['normal_price'],$details['promotion_saving']]);
    }
}
