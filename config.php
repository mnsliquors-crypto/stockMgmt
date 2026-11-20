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
    && $conn->query("SELECT version FROM erp_schema_versions WHERE version='003_friend_features'")->num_rows>0;
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
