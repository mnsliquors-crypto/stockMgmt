<?php
ob_start();
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kathmandu');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

require_once('initialize.php');
require_once(__DIR__ . '/classes/Security.php');
enforce_request_security();
require_once('classes/DBConnection.php');
require_once('classes/SystemSettings.php');
$db = new DBConnection;
$conn = $db->conn;
$enterprise_available=$conn->query("SHOW TABLES LIKE 'erp_schema_versions'")->num_rows
    && $conn->query("SELECT version FROM erp_schema_versions WHERE version='".ERP_V3_SCHEMA_VERSION."'")->num_rows>0;
$purchase_cycle_available=$enterprise_available&&$conn->query("SELECT version FROM erp_schema_versions WHERE version='004_purchase_cycle'")->num_rows>0;
$item_catalog_available=$enterprise_available&&$conn->query("SELECT version FROM erp_schema_versions WHERE version='005_item_masters'")->num_rows>0;
require_once __DIR__.'/classes/Enterprise/CatalogService.php';
$inventory_stock_source=$item_catalog_available?'erp_stock_items':'transaction_items';
$stock_item_source=$item_catalog_available?'erp_inventory_items':'item_list';
$purchasable_item_source=$item_catalog_available?'erp_purchasable_items':'item_list';
$sellable_item_source=$item_catalog_available?'erp_sellable_items':'item_list';
$purchasable_party_source=$item_catalog_available?'erp_purchase_parties':'entity_list';
$sellable_party_source=$item_catalog_available?'erp_sale_parties':'entity_list';
require_once __DIR__ . '/classes/Enterprise/Access.php';
require_once __DIR__ . '/classes/Enterprise/Settlement.php';
App\Enterprise\Access::enforce($conn);

function redirect($url=''){
	if(!empty($url)) {
        header('Location: ' . base_url . $url);
        exit;
    }
}
function validate_image($file){
	if(!empty($file)){
			// exit;
        $ex = explode('?',$file);
        $file = $ex[0];
        $param =  isset($ex[1]) ? '?'.$ex[1]  : '';
		if(is_file(base_app.$file)){
			return base_url.$file.$param;
		}else{
			return base_url.'dist/img/no-image-available.png';
		}
	}else{
		return base_url.'dist/img/no-image-available.png';
	}
}
function isMobileDevice(){
    $aMobileUA = array(
        '/iphone/i' => 'iPhone', 
        '/ipod/i' => 'iPod', 
        '/ipad/i' => 'iPad', 
        '/android/i' => 'Android', 
        '/blackberry/i' => 'BlackBerry', 
        '/webos/i' => 'Mobile'
    );

    //Return true if Mobile User Agent is detected
    foreach($aMobileUA as $sMobileKey => $sMobileOS){
        if(preg_match($sMobileKey, $_SERVER['HTTP_USER_AGENT'] ?? '')){
            return true;
        }
    }
    //Otherwise return false..  
    return false;
}
// Keep the response buffered so page routing can set redirects and error statuses.
?>
