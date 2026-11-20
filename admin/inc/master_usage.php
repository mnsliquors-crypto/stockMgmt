<?php
if(empty($item_catalog_available)||empty($_GET['id']))return;
$usageKind=match(true){str_starts_with($page,'master/customers/')=>'customer',str_starts_with($page,'master/vendors/')=>'vendor',default=>null};
if(!$usageKind)return;
$usageCatalog=new App\Enterprise\CatalogService($conn,(int)$_settings->userdata('id'));
$usageRecord=null;foreach($usageCatalog->usages($usageKind)as $entry)if((int)$entry['id']===(int)$_GET['id']){$usageRecord=$entry;break;}
if(!$usageRecord)return;
$usageEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<section class="border-top mt-3 pt-3"><h5 class="h6">Record Usage</h5><form class="catalog-form d-flex align-items-center" data-action="save_usage" data-stay="1"><input type="hidden" name="record_type" value="<?php echo $usageKind ?>"><input type="hidden" name="record_id" value="<?php echo (int)$usageRecord['id'] ?>"><input type="hidden" name="version" value="<?php echo (int)$usageRecord['version'] ?>"><select name="item_usage" class="form-control form-control-sm mr-2" style="max-width:220px" data-no-select2 aria-label="Record usage" <?php echo $usageCatalog->canManageUsage($usageKind)?'':'disabled' ?>><?php foreach(App\Enterprise\CatalogService::USAGES as $key=>$label): ?><option value="<?php echo $key ?>" <?php echo $usageRecord['item_usage']===$key?'selected':'' ?>><?php echo $usageEscape($label) ?></option><?php endforeach ?></select><?php if($usageCatalog->canManageUsage($usageKind)): ?><button class="btn btn-sm btn-outline-primary" type="submit" title="Save usage" aria-label="Save usage"><i class="fas fa-save"></i></button><?php endif ?></form><div id="catalog-message" role="alert" aria-live="polite"></div></section>
<script src="<?php echo base_url ?>dist/js/catalog.js?v=2"></script>
