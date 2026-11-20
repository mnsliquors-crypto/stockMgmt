<?php
if(empty($purchase_cycle_available)){http_response_code(503);echo '<div class="alert alert-warning">Purchase cycle setup is pending.</div>';return;}
require_once base_app.'classes/Procurement/CycleService.php';
require_once base_app.'classes/Procurement/OrderService.php';
$cycle=new App\Procurement\CycleService($conn,(int)$_settings->userdata('id'));
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$cycleUrl=base_url.'admin/?page=transactions/';
$cycleAdmin=(int)$_settings->userdata('type')===1;
$canCycle=static fn($scope)=>App\Enterprise\Access::allowed($conn,(int)$_settings->userdata('id'),$scope,true);
$glButton=static function($transaction,$journal)use($cycleUrl,$cycleAdmin){
    if($journal)echo '<a class="btn btn-sm btn-outline-success" href="'.base_url.'admin/?page=finance/accounting&amp;id='.(int)$journal.'">Posted</a>';
    elseif($cycleAdmin)echo '<button type="button" class="btn btn-sm btn-outline-primary pc-post" data-id="'.(int)$transaction.'">Review &amp; Post</button>';
    else echo '<span class="badge badge-secondary">Unposted</span>';
};
?>
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/procurement.css?v=2">
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/purchase-cycle.css?v=1">
<script defer src="<?php echo base_url ?>dist/js/purchase-cycle.js?v=1"></script>
<div id="pc-message" role="alert" aria-live="polite"></div>
<div class="po-print-brand"><img src="<?php echo $escape(validate_image($_settings->info('logo'))) ?>" alt="Company logo"><div><h3><?php echo $escape($_settings->info('name')?:APP_NAME) ?></h3><span><?php echo $escape(APP_NAME) ?></span></div></div>
<div class="modal fade" id="pc-dialog" tabindex="-1" aria-labelledby="pc-dialog-title" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="pc-dialog-title" class="modal-title">Accounting Entry</h5><button class="close" type="button" data-dismiss="modal" aria-label="Close">&times;</button></div><div class="modal-body"></div><div class="modal-footer"><button class="btn btn-sm btn-secondary" type="button" data-dismiss="modal">Cancel</button><button class="btn btn-sm btn-primary" type="button" id="pc-confirm-post">Post Entry</button></div></div></div></div>
