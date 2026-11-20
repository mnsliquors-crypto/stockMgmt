<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../classes/Enterprise/FeatureRegistry.php';
require_once __DIR__.'/../classes/Enterprise/ReportService.php';
$features=App\Enterprise\FeatureRegistry::all();$checks=0;
function registryCheck(bool $condition,string $message): void {
    global $checks;if(!$condition)throw new RuntimeException($message);$checks++;
}
foreach($features as $key=>$feature){
    registryCheck((bool)preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/D',$key),'Invalid key: '.$key);
    registryCheck(!empty($feature['label'])&&in_array($feature['type'],['page','tab','component','chart','bar','action'],true),'Invalid metadata: '.$key);
    registryCheck(count($feature['actions'])>0&&count($feature['actions'])===count(array_unique($feature['actions'])),'Invalid actions: '.$key);
    $seen=[$key=>true];$parent=$feature['parent'];
    while($parent!==null){
        registryCheck(isset($features[$parent])&&!isset($seen[$parent]),'Missing parent or cycle: '.$key);
        registryCheck(in_array('view',$features[$parent]['actions'],true),'Parent has no view action: '.$key);
        $seen[$parent]=true;$parent=$features[$parent]['parent'];
    }
}
foreach(App\Enterprise\ReportService::CATALOG as $key=>$report)registryCheck(isset($features['reports.center.'.$key]),'Missing report: '.$key);
foreach(['app.login','app.logout','app.profile','admin.features'] as $key)registryCheck(!empty($features[$key]['protected']),'Missing protected feature: '.$key);
registryCheck(isset($features['dashboard.legacy.details'])&&is_file(__DIR__.'/../admin/pages/dashboard_details.php'),'Dashboard details inventory missing');
$review=file_get_contents(__DIR__.'/../docs/feature-registry-review.md');
foreach(array_keys($features) as $key)registryCheck(str_contains($review,'`'.$key.'`'),'Review document missing: '.$key);
echo "Passed $checks feature-registry checks for ".count($features)." entries.\n";
