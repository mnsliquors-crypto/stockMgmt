<?php
namespace App\Enterprise;
require_once __DIR__.'/Service.php';
require_once __DIR__.'/Access.php';

final class CatalogService extends Service {
    protected function atomic(callable $work){
        $result=parent::atomic($work);require_once __DIR__.'/../Cache.php';
        try{(new \Cache())->clear();}catch(\Throwable $e){error_log('Catalog cache refresh: '.$e->getMessage());}return $result;
    }
    public const TYPES=['inventory'=>'Inventory','assembly'=>'Assembly','non_inventory'=>'Non-Inventory','service'=>'Service'];
    public const USAGES=['purchase'=>'For Purchase','sale'=>'For Sale','resale'=>'For Resale'];
    public const MASTERS=['account'=>'Chart of Accounts','unit'=>'Units','bin'=>'Bins','customer'=>'Customers','vendor'=>'Vendors','location'=>'Locations','class'=>'Classes','department'=>'Departments'];
    private const RECORDS=[
        'account'=>['gl_accounts','name','code','active','1=1','accounting'],
        'unit'=>['erp_units','name','code','active','1=1','inventory'],
        'bin'=>['erp_bins','name','code','active','1=1','inventory'],
        'customer'=>['entity_list','display_name','id','status',"entity_type='Customer'",'sales'],
        'vendor'=>['entity_list','display_name','id','status',"entity_type='Supplier'",'purchases'],
        'location'=>['erp_locations','name','code','active','1=1','inventory'],
        'class'=>['erp_dimensions','name','code','active',"kind='class'",'inventory'],
        'department'=>['erp_dimensions','name','code','active',"kind='department'",'inventory']
    ];
    public static function assertRecordUsage(\mysqli $db,string $kind,int $id,bool $purchase): void {
        if(!$id||!self::available($db))return;
        $s=$db->prepare('SELECT item_usage FROM erp_master_usage WHERE record_type=? AND record_id=?');$s->execute([$kind,$id]);$usage=$s->get_result()->fetch_assoc()['item_usage']??'resale';
        if($usage!==($purchase?'purchase':'sale')&&$usage!=='resale')throw new \InvalidArgumentException((self::MASTERS[$kind]??$kind).' record is '.self::USAGES[$usage].' and cannot be used for '.($purchase?'purchasing.':'sales.'));
    }
    public static function assertItemMasters(\mysqli $db,int $item,bool $purchase,?int $location=null): void {
        if(!self::available($db))return;
        $s=$db->prepare('SELECT COALESCE(d.unit_id,u.id) AS unit_id,d.location_id,d.bin_id,d.class_id,d.department_id FROM item_list i LEFT JOIN erp_item_defaults d ON d.item_id=i.id LEFT JOIN erp_units u ON u.code=i.unit WHERE i.id=?');$s->execute([$item]);$d=$s->get_result()->fetch_assoc();if(!$d)return;
        foreach(['unit_id'=>'unit','class_id'=>'class','department_id'=>'department']as $field=>$kind)if($d[$field])self::assertRecordUsage($db,$kind,(int)$d[$field],$purchase);
        if($d['bin_id']&&($location??1)===(int)($d['location_id']??1))self::assertRecordUsage($db,'bin',(int)$d['bin_id'],$purchase);
    }
    public function usages(string $kind): array {
        if(!isset(self::RECORDS[$kind]))throw new \InvalidArgumentException('Unknown master record type.');
        [$table,$name,$code,$active,$where,$scope]=self::RECORDS[$kind];
        if(!Access::allowed($this->db,$this->user,$scope))throw new \DomainException('Your role does not allow this master record view.');
        return $this->rows("SELECT t.id,t.$name AS name,t.$code AS code,t.$active AS active,COALESCE(u.item_usage,'resale') AS item_usage,COALESCE(u.version,0) AS version FROM $table t LEFT JOIN erp_master_usage u ON u.record_type=? AND u.record_id=t.id WHERE $where ORDER BY t.$name LIMIT 1000",[$kind]);
    }
    public function canManageUsage(string $kind): bool {
        return isset(self::RECORDS[$kind])&&Access::allowed($this->db,$this->user,self::RECORDS[$kind][5],true)&&($this->isAdmin()||in_array($kind,['customer','vendor'],true));
    }
    public function saveUsage(array $in): void {
        $kind=$this->text($in['record_type']??'',20,true);
        if(!isset(self::RECORDS[$kind]))throw new \InvalidArgumentException('Unknown master record type.');
        [$table,$name,$code,$active,$where,$scope]=self::RECORDS[$kind];
        if(!Access::allowed($this->db,$this->user,$scope,true))throw new \DomainException('Your role does not allow this master record change.');
        if(!in_array($kind,['customer','vendor'],true))$this->admin();
        $this->atomic(function()use($in,$kind,$table,$where){
            $id=$this->id($in['record_id']??'');$usage=$this->text($in['item_usage']??'',20,true);if(!isset(self::USAGES[$usage]))throw new \InvalidArgumentException('Choose a valid usage.');
            if(!$this->row("SELECT id FROM $table WHERE id=? AND $where FOR UPDATE",[$id]))throw new \InvalidArgumentException('Master record not found.');
            $old=$this->row('SELECT * FROM erp_master_usage WHERE record_type=? AND record_id=? FOR UPDATE',[$kind,$id]);$this->version($old??['version'=>0],$in['version']??'');
            $this->query('INSERT INTO erp_master_usage(record_type,record_id,item_usage) VALUES(?,?,?) ON DUPLICATE KEY UPDATE item_usage=VALUES(item_usage),version=version+1',[$kind,$id,$usage]);
            $this->audit('save_master_usage',$kind,$id,['usage'=>$usage]);
        });
    }
    public static function available(\mysqli $db): bool {
        static $connections;$connections??=new \WeakMap();
        if(!isset($connections[$db]))$connections[$db]=$db->query("SHOW TABLES LIKE 'erp_catalog_settings'")->num_rows>0&&$db->query("SELECT 1 FROM erp_schema_versions WHERE version='005_item_masters'")->num_rows>0;
        return $connections[$db];
    }
    public static function stockSource(\mysqli $db): string {return self::available($db)?'erp_stock_items':'transaction_items';}
    public static function partySource(\mysqli $db,bool $purchase): string {return self::available($db)?($purchase?'erp_purchase_parties':'erp_sale_parties'):'entity_list';}
    public static function transactionPurchase(\mysqli $db,int $id): ?bool {
        $s=$db->prepare('SELECT t.type,t.entity_type,p.type AS parent_type FROM transactions t LEFT JOIN transactions p ON p.id=t.parent_id WHERE t.id=?');$s->execute([$id]);$t=$s->get_result()->fetch_assoc();if(!$t)return null;
        if(in_array($t['type'],['purchase','item_receipt','expense'],true))return true;if($t['type']==='sale')return false;
        if(in_array($t['type'],['return','payment'],true)){if(in_array($t['parent_type'],['sale','purchase'],true))return $t['parent_type']==='purchase';if($t['type']==='return')return in_array(strtolower($t['entity_type']??''),['supplier','vendor'],true);}
        return null;
    }
    public static function itemSource(\mysqli $db,bool $purchase): string {return self::available($db)?($purchase?'erp_purchasable_items':'erp_sellable_items'):'item_list';}
    public static function tracked(\mysqli $db,int $item): bool {
        if(!self::available($db))return true;
        $s=$db->prepare("SELECT item_type,is_matrix_parent FROM item_list WHERE id=?");$s->execute([$item]);$i=$s->get_result()->fetch_assoc();
        return $i&&in_array($i['item_type'],['inventory','assembly'],true)&&!$i['is_matrix_parent'];
    }
    public static function assertUsage(\mysqli $db,int $item,bool $purchase): void {
        if(!self::available($db))return;
        $source=self::itemSource($db,$purchase);$s=$db->prepare("SELECT id FROM $source WHERE id=? AND status=1");$s->execute([$item]);
        if(!$s->get_result()->num_rows)throw new \InvalidArgumentException('Choose an active '.($purchase?'purchasable':'sellable').' item variant.');
    }
    private function permission(bool $write=false): void {if(!Access::allowed($this->db,$this->user,'inventory',$write))throw new \DomainException('Your role does not allow this item setup action.');}
    private function index(int $id): void {
        $i=$this->row('SELECT * FROM item_list WHERE id=?',[$id]);$url='enterprise/catalog&id='.$id;
        $this->query("INSERT INTO search_index(source_table,source_id,type,title,subtitle,search_data,amount,view_url,edit_url) VALUES('item_list',?,'item',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),subtitle=VALUES(subtitle),search_data=VALUES(search_data),amount=VALUES(amount),view_url=VALUES(view_url),edit_url=VALUES(edit_url)",[$id,$i['name'],self::TYPES[$i['item_type']].' / '.self::USAGES[$i['item_usage']],implode(' ',[$i['name'],$i['sku'],$i['description'],$i['item_type'],$i['item_usage']]),$i['selling_price'],$url,$url]);
    }
    public function settings(): array {$this->permission();return $this->row('SELECT * FROM erp_catalog_settings WHERE id=1');}
    public function configure(array $in): void {
        $this->admin();$this->atomic(function()use($in){$old=$this->row('SELECT * FROM erp_catalog_settings WHERE id=1 FOR UPDATE');$this->version($old,$in['version']??'');$on=(string)($in['matrix_enabled']??'0')==='1'?1:0;
            if(!$on&&$this->row('SELECT id FROM item_list WHERE status=1 AND (is_matrix_parent=1 OR matrix_parent_id IS NOT NULL) LIMIT 1'))throw new \RuntimeException('Deactivate matrix families and variants before disabling matrix items.');
            $this->query('UPDATE erp_catalog_settings SET matrix_enabled=?,version=version+1 WHERE id=1',[$on]);$this->audit('configure_catalog','catalog',1,['matrix_enabled'=>$on]);});
    }
    public function lookups(string $kind): array {
        $this->permission();
        return match($kind){
            'units'=>$this->rows('SELECT * FROM erp_units ORDER BY name'),
            'bins'=>$this->rows('SELECT b.*,l.name AS location_name FROM erp_bins b JOIN erp_locations l ON l.id=b.location_id ORDER BY l.name,b.code'),
            'class','department'=>$this->rows('SELECT * FROM erp_dimensions WHERE kind=? ORDER BY name',[$kind]),
            default=>throw new \InvalidArgumentException('Unknown master record type.')
        };
    }
    public function saveLookup(array $in): int {
        $this->permission(true);$this->admin();
        return $this->atomic(function()use($in){
            $kind=$this->text($in['kind']??'',20,true);$this->lookups($kind);$id=empty($in['id'])?0:$this->id($in['id']);
            $table=match($kind){'units'=>'erp_units','bins'=>'erp_bins',default=>'erp_dimensions'};
            $old=$id?$this->row("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]):null;
            if($id&&!$old)throw new \RuntimeException('Master record not found.');if($old){$this->version($old,$in['version']??'');if(isset($old['kind'])&&$old['kind']!==$kind)throw new \InvalidArgumentException('Record type cannot change.');}
            $code=$this->text($in['code']??'',50,true);$name=$this->text($in['name']??'',100,true);$active=(string)($in['active']??'0')==='1'?1:0;
            $fields=['code'=>$code,'name'=>$name,'active'=>$active];
            if($kind==='bins'){
                $location=$this->id($in['location_id']??'');if(!$this->row('SELECT id FROM erp_locations WHERE id=? AND active=1',[$location]))throw new \InvalidArgumentException('Choose an active location.');
                if($old&&(int)$old['location_id']!==$location)throw new \InvalidArgumentException('A bin cannot move to another location. Create another bin.');$fields['location_id']=$location;
            }elseif(in_array($kind,['class','department'],true))$fields['kind']=$kind;
            if($old&&$kind==='units'&&$code!==$old['code']&&($this->row('SELECT item_id FROM erp_item_defaults WHERE unit_id=? LIMIT 1',[$id])||$this->row('SELECT id FROM item_list WHERE unit=? LIMIT 1',[$old['code']])))throw new \RuntimeException('A unit used by items cannot change code.');
            if($id){$this->query("UPDATE $table SET ".implode(',',array_map(static fn($f)=>"$f=?",array_keys($fields))).',version=version+1 WHERE id=?',[...array_values($fields),$id]);}
            else{$this->query("INSERT INTO $table (".implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));$id=(int)$this->db->insert_id;}
            $this->audit('save_catalog_master',$kind,$id,['code'=>$code,'active'=>$active]);return $id;
        });
    }
    public function item(int $id): array {
        $this->permission();$item=$this->row('SELECT i.*,d.unit_id,d.location_id,d.bin_id,d.class_id,d.department_id,d.inventory_account_id,d.income_account_id,d.expense_account_id,a.axes FROM item_list i LEFT JOIN erp_item_defaults d ON d.item_id=i.id LEFT JOIN erp_matrix_axes a ON a.item_id=i.id WHERE i.id=?',[$id]);
        if(!$item)throw new \RuntimeException('Item not found.');
        $item['axes']=$item['axes']?json_decode($item['axes'],true):[];
        $item['variants']=$this->rows('SELECT id,sku,name,item_type,item_usage,status,quantity,cost,selling_price,variant_options,catalog_version FROM item_list WHERE matrix_parent_id=? ORDER BY id',[$id]);return $item;
    }
    public function items(array $filters=[]): array {
        $this->permission();$where=[];$values=[];
        foreach(['item_type'=>self::TYPES,'item_usage'=>self::USAGES]as $key=>$allowed)if(!empty($filters[$key])){if(!isset($allowed[$filters[$key]]))throw new \InvalidArgumentException('Invalid item filter.');$where[]="$key=?";$values[]=$filters[$key];}
        $search=$this->text($filters['q']??'',120);if($search!==''){$where[]='(name LIKE ? OR sku LIKE ?)';array_push($values,'%'.$search.'%','%'.$search.'%');}
        return $this->rows('SELECT id,name,sku,item_type,item_usage,is_matrix_parent,matrix_parent_id,unit,status,quantity,catalog_version FROM item_list'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY name LIMIT 500',$values);
    }
    private function defaults(array $in): array {
        $out=[];
        foreach(['unit_id','location_id','bin_id','class_id','department_id','inventory_account_id','income_account_id','expense_account_id']as $f)$out[$f]=empty($in[$f])?null:$this->id($in[$f]);
        foreach(['unit_id'=>'erp_units','location_id'=>'erp_locations','bin_id'=>'erp_bins']as $f=>$table)if($out[$f]&&!$this->row("SELECT id FROM $table WHERE id=? AND active=1",[$out[$f]]))throw new \InvalidArgumentException('Choose an active '.$f.'.');
        foreach(['class_id'=>'class','department_id'=>'department']as $f=>$kind)if($out[$f]&&!$this->row('SELECT id FROM erp_dimensions WHERE id=? AND kind=? AND active=1',[$out[$f],$kind]))throw new \InvalidArgumentException('Choose an active '.$kind.'.');
        if($out['bin_id']&&!$this->row('SELECT id FROM erp_bins WHERE id=? AND location_id=?',[$out['bin_id'],$out['location_id']??1]))throw new \InvalidArgumentException('The default bin must belong to the default location.');
        foreach(['inventory_account_id'=>'asset','income_account_id'=>'income','expense_account_id'=>'expense']as $f=>$category)if($out[$f]&&!$this->row('SELECT id FROM gl_accounts WHERE id=? AND category=? AND active=1',[$out[$f],$category]))throw new \InvalidArgumentException('Choose an active '.$category.' account.');
        return $out;
    }
    public function saveItem(array $in): int {
        $this->permission(true);
        return $this->atomic(function()use($in){
            $id=empty($in['id'])?0:$this->id($in['id']);$old=$id?$this->item($id):null;if($old)$this->version(['version'=>$old['catalog_version']],$in['catalog_version']??'');
            $type=$this->text($in['item_type']??'inventory',20);$usage=$this->text($in['item_usage']??'resale',20);
            if(!isset(self::TYPES[$type],self::USAGES[$usage]))throw new \InvalidArgumentException('Choose a valid item type and usage.');
            $parent=(string)($in['is_matrix_parent']??'0')==='1'?1:0;
            if($parent&&!$this->settings()['matrix_enabled'])throw new \RuntimeException('Enable matrix items in Features first.');
            if($old&&$old['matrix_parent_id']){
                if((isset($in['item_type'])&&$type!==$old['item_type'])||(isset($in['item_usage'])&&$usage!==$old['item_usage'])||$parent)throw new \InvalidArgumentException('Variant type and usage are inherited from its parent.');
                $type=$old['item_type'];$usage=$old['item_usage'];$parent=0;
            }
            $name=$this->text($in['name']??'',250,true);$sku=$this->text($in['sku']??'',100)?:'ITM-'.strtoupper(bin2hex(random_bytes(6)));
            if(!preg_match('/^[A-Za-z0-9_.-]+$/D',$sku))throw new \InvalidArgumentException('SKU must use letters, numbers, dots, underscores or hyphens.');
            if($this->row('SELECT id FROM item_list WHERE name=? AND id<>?',[$name,$id]))throw new \UnexpectedValueException('Item name already exists.');
            $defaults=$this->defaults($in);
            if(!$defaults['unit_id'])$defaults['unit_id']=$this->row('SELECT id FROM erp_units WHERE code=? AND active=1',[$old['unit']??'Each'])['id']??null;
            if(!$defaults['unit_id'])throw new \InvalidArgumentException('Choose an active base unit.');
            $unit=$this->row('SELECT code FROM erp_units WHERE id=?',[$defaults['unit_id']])['code'];
            $fields=['name'=>$name,'sku'=>$sku,'description'=>$this->text($in['description']??'',2000),'item_type'=>$type,'item_usage'=>$usage,'is_matrix_parent'=>$parent,
                'unit'=>$unit,'cost'=>$this->decimal($in['cost']??'0'),'selling_price'=>$this->decimal($in['selling_price']??'0'),'mrp'=>$this->decimal($in['mrp']??'0'),
                'reorder_level'=>in_array($type,['inventory','assembly'],true)&&!$parent?$this->decimal($in['reorder_level']??'0'): '0.0000',
                'status'=>(string)($in['status']??'0')==='1'?1:0,'tax_type'=>(string)($in['tax_type']??'0')==='1'?1:0,
                'vendor_id'=>empty($in['vendor_id'])?null:$this->id($in['vendor_id']),'category_id'=>empty($in['category_id'])?null:$this->id($in['category_id'])];
            foreach(['vendor_id'=>['entity_list',"entity_type='Supplier' AND status=1"],'category_id'=>['category_list','status=1']]as $f=>[$table,$condition])if($fields[$f]&&!$this->row("SELECT id FROM $table WHERE id=? AND $condition",[$fields[$f]]))throw new \InvalidArgumentException('Choose an active '.$f.'.');
            if($id)$this->query('UPDATE item_list SET '.implode(',',array_map(static fn($k)=>"$k=?",array_keys($fields))).',updated_by=?,catalog_version=catalog_version+1 WHERE id=?',[...array_values($fields),$this->user,$id]);
            else{$fields['opening_cost']=$fields['cost'];$fields['average_cost']=$fields['cost'];$fields['created_by']=$this->user;$this->query('INSERT INTO item_list ('.implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));$id=(int)$this->db->insert_id;}
            $keys=array_keys($defaults);$this->query('INSERT INTO erp_item_defaults(item_id,'.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys)+1,'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(static fn($k)=>"$k=VALUES($k)",$keys)),[$id,...array_values($defaults)]);
            $this->index($id);$this->audit('save_item_catalog','item',$id,['type'=>$type,'usage'=>$usage,'sku'=>$sku]);return $id;
        });
    }
    public function generate(array $in): array {
        $this->permission(true);
        return $this->atomic(function()use($in){
            if(!$this->settings()['matrix_enabled'])throw new \RuntimeException('Matrix items are disabled.');
            $id=$this->id($in['id']??'');$parent=$this->item($id);$this->version(['version'=>$parent['catalog_version']],$in['catalog_version']??'');
            if(!$parent['is_matrix_parent']||!$parent['status'])throw new \InvalidArgumentException('Choose an active matrix parent.');
            $raw=$in['axes']??null;if(!is_array($raw)||!$raw||count($raw)>3)throw new \InvalidArgumentException('Enter one to three matrix axes.');
            $axes=[];$names=[];$combinations=[[]];
            foreach($raw as $axis){
                if(!is_array($axis))throw new \InvalidArgumentException('Invalid matrix axis.');$name=$this->text($axis['name']??'',50,true);$lower=mb_strtolower($name);
                if(isset($names[$lower]))throw new \InvalidArgumentException('Matrix axis names must be unique.');$names[$lower]=true;
                $values=$axis['values']??null;if(!is_array($values)||!$values||count($values)>20)throw new \InvalidArgumentException('Each axis needs one to twenty values.');$clean=[];$seen=[];
                foreach($values as $value){$value=$this->text($value,50,true);$key=mb_strtolower($value);if(isset($seen[$key]))throw new \InvalidArgumentException('Matrix values must be unique.');$seen[$key]=true;$clean[]=$value;}
                $axes[$name]=$clean;if(count($combinations)*count($clean)>100)throw new \InvalidArgumentException('A matrix family supports at most 100 combinations.');
                $next=[];foreach($combinations as $c)foreach($clean as $v)$next[]=$c+[$name=>$v];$combinations=$next;
            }
            foreach($parent['axes']as $name=>$values)if(!isset($axes[$name])||array_diff($values,$axes[$name]))throw new \InvalidArgumentException('Existing matrix axes and values cannot be removed or renamed.');
            if($parent['axes']&&array_diff(array_keys($axes),array_keys($parent['axes'])))throw new \InvalidArgumentException('Existing matrix families cannot add another axis.');
            $created=[];
            foreach($combinations as $options){ksort($options);$json=json_encode($options,JSON_THROW_ON_ERROR);$key=hash('sha256',$json);
                if($this->row('SELECT id FROM item_list WHERE matrix_parent_id=? AND variant_key=?',[$id,$key]))continue;
                $name=$this->text($parent['name'].' / '.implode(' / ',$options),250,true);$sku=substr($parent['sku']?:'ITEM-'.$id,0,80).'-'.strtoupper(substr($key,0,12));
                $this->query('INSERT INTO item_list(name,sku,item_type,item_usage,matrix_parent_id,variant_key,variant_options,description,unit,cost,opening_cost,average_cost,selling_price,mrp,tax_type,category_id,vendor_id,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)',[$name,$sku,$parent['item_type'],$parent['item_usage'],$id,$key,$json,$parent['description'],$parent['unit'],$parent['cost'],$parent['cost'],$parent['cost'],$parent['selling_price'],$parent['mrp'],$parent['tax_type'],$parent['category_id'],$parent['vendor_id'],$this->user]);
                $child=(int)$this->db->insert_id;$created[]=$child;
                $this->query('UPDATE item_list SET image_path=? WHERE id=?',[$parent['image_path'],$child]);$this->index($child);
                $this->query('INSERT INTO erp_item_defaults SELECT ?,unit_id,location_id,bin_id,class_id,department_id,inventory_account_id,income_account_id,expense_account_id FROM erp_item_defaults WHERE item_id=?',[$child,$id]);
            }
            $this->query('INSERT INTO erp_matrix_axes(item_id,axes) VALUES(?,?) ON DUPLICATE KEY UPDATE axes=VALUES(axes)',[$id,json_encode($axes,JSON_THROW_ON_ERROR)]);
            $this->query('UPDATE item_list SET catalog_version=catalog_version+1 WHERE id=?',[$id]);$this->audit('generate_matrix_variants','item',$id,['created'=>$created,'axes'=>$axes]);return ['id'=>$id,'created'=>$created];
        });
    }
}
