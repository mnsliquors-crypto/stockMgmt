<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Passwords.php';
class Login extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;

		parent::__construct();
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function index(){
		echo "<h1>Access Denied</h1> <a href='".base_url."'>Go Back.</a>";
	}
	public function login(){
		return $this->authenticate(false);
	}
	private function authenticate($staff_only){
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($username === '' || $password === '') return json_encode(['status' => 'incorrect']);
		$stmt = $this->conn->prepare("SELECT id, firstname, lastname, username, password, type, avatar FROM `users` WHERE username = ? LIMIT 1");
		$stmt->bind_param("s", $username);
		$stmt->execute();
		$qry = $stmt->get_result();
		$res = $qry->fetch_assoc();
        if($res && in_array((int)$res['type'], [1, 2], true)
            && (!$staff_only || (int)$res['type'] === 2)
            && verify_user_password($password, $res['password'])){
            if (password_needs_rehash($res['password'], PASSWORD_DEFAULT)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $this->queryPrepared('UPDATE users SET password = ? WHERE id = ?', 'si', [$hash, $res['id']]);
            }
            unset($res['password']);
            session_regenerate_id(true);
            unset($_SESSION['userdata']);
			foreach($res as $k => $v){
				$this->settings->set_userdata($k,$v);
			}
			$this->settings->set_userdata('login_type',1);
			$stmt->close();
			return json_encode(array('status'=>'success'));
		}else{
			$stmt->close();
			return json_encode(array('status'=>'incorrect'));
		}
	}
	public function logout(){
		if($this->settings->sess_des()){
			redirect('admin/login.php');
		}
	}
	function login_user(){
		return $this->authenticate(true);
	}
	public function logout_user(){
		if($this->settings->sess_des()){
			redirect('./');
		}
	}
}
// Only run controller actions when this file is accessed directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
	$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
	$auth = new Login();
	switch ($action) {
		case 'login':
			echo $auth->login();
			break;
		case 'login_user':
			echo $auth->login_user();
			break;
		case 'logout':
			echo $auth->logout();
			break;
		case 'logout_user':
			echo $auth->logout_user();
			break;
		default:
			echo $auth->index();
			break;
	}
}

