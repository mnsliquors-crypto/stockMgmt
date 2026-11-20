<?php
const APP_NAME = 'Karyasetu Enterprise Platform';
const APP_SHORT_NAME = 'Karyasetu';
const APP_COPYRIGHT_HOLDER = 'Karyasetu Tech. Nepal';
const APP_LOGO = 'dist/img/karyasetu-logo.jpg';
const APP_RELEASE = 'V3 Update';
// Preserve the deployed schema ledger key when release labels change.
const ERP_V3_SCHEMA_VERSION = '003_friend_features';

$dev_data = array('id'=>'-1','firstname'=>'Developer','lastname'=>'','username'=>'dev_oretnom','password'=>'5da283a2d990e8d8512cf967df5bc0d0','last_login'=>'','date_updated'=>'','date_added'=>'');
if(!defined('base_url')) define('base_url', rtrim(getenv('APP_BASE_URL') ?: 'http://localhost/sms/', '/') . '/');
if(!defined('base_app')) define('base_app', str_replace('\\','/',__DIR__).'/' );
if(!defined('dev_data')) define('dev_data',$dev_data);
if(!defined('DB_SERVER')) define('DB_SERVER', getenv('DB_HOST') ?: 'localhost');
if(!defined('DB_USERNAME')) define('DB_USERNAME', getenv('DB_USER') ?: 'root');
if(!defined('DB_PASSWORD')) define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
if(!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'sms_db');
if(!defined('DB_PORT')) define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
?>
