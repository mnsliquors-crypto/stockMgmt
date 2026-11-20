<?php
if (!isset($conn)) require_once __DIR__.'/../../../config.php';
if (!$conn->query("SELECT version FROM erp_schema_versions WHERE version='003_friend_features'")->num_rows) {
    http_response_code(503);echo '<div class="alert alert-info">Workspace upgrade is pending.</div>';return;
}
require_once base_app.'classes/Enterprise/WorkspaceService.php';
require_once base_app.'classes/Enterprise/InventoryService.php';
require_once base_app.'classes/Enterprise/PromotionService.php';
require_once base_app.'classes/Enterprise/ReportService.php';
$suiteUser=(int)$_settings->userdata('id');
$suiteWorkspace=new App\Enterprise\WorkspaceService($conn,$suiteUser);
$suiteInventory=new App\Enterprise\InventoryService($conn,$suiteUser);
$suiteAdmin=$suiteWorkspace->isAdmin();
$suiteScope=match($suiteModule){'credits'=>'payments','receipts'=>'sales',default=>$suiteModule};
$suiteCanWrite=App\Enterprise\Access::allowed($conn,$suiteUser,$suiteScope,true);
$suiteEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$suiteNumber=static fn($value)=>str_contains((string)$value,'.')?(rtrim(rtrim((string)$value,'0'),'.')?:'0'):(string)$value;
$suiteUrl=base_url.'admin/?page=enterprise/'.$suiteModule;
$suiteLocations=$suiteInventory->locations(true);
$suiteOptions=$suiteWorkspace->options();
$suiteSelect=static function (array $rows,string $key,string $text,$selected=null) use ($suiteEscape) {
    $html='';foreach ($rows as $row) $html.='<option value="'.$suiteEscape($row[$key]).'"'.((string)$selected===(string)$row[$key]?' selected':'').'>'.$suiteEscape($row[$text]).'</option>';return $html;
};
$suiteTable=static function (array $rows) use ($suiteEscape) {
    if (!$rows) {echo '<p class="text-muted py-3">No records for this selection.</p>';return;}
    echo '<div class="table-responsive"><table class="table table-sm table-striped suite-data-table"><thead><tr>';
    foreach (array_keys($rows[0]) as $column) echo '<th>'.$suiteEscape(str_replace('_',' ',$column)).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {echo '<tr>';foreach ($row as $value) echo '<td'.(is_numeric($value)?' class="text-right text-nowrap"':'').'>'.$suiteEscape($value??'').'</td>';echo '</tr>';}
    echo '</tbody></table></div>';
};
$suitePreferences=$suiteWorkspace->preferences();
?>
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/enterprise.css?v=1">
<script src="<?php echo base_url ?>dist/js/enterprise.js?v=1" defer></script>
<div class="suite-workspace py-3" data-module="<?php echo $suiteEscape($suiteModule) ?>" data-url="<?php echo $suiteEscape($suiteUrl) ?>" data-can-write="<?php echo $suiteCanWrite?'1':'0' ?>" data-theme="<?php echo $suiteEscape($suitePreferences['settings']['theme']??'light') ?>">
<div id="suite-message" role="alert" aria-live="polite"></div>
