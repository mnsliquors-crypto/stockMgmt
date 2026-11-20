<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$is_login = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'login.php';
if(empty($_SESSION['userdata']['id']) && !$is_login){
	redirect('admin/login.php');
}
if(!empty($_SESSION['userdata']['id']) && $is_login){
	redirect('admin/index.php');
}
if(!empty($_SESSION['userdata']['id']) && ($_SESSION['userdata']['login_type'] ?? null) != 1){
    deny_request(403, 'Access denied.');
}
