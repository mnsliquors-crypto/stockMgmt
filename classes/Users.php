<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Passwords.php';

class Users extends DBConnection {
    private $settings;

    public function __construct() {
        global $_settings;
        $this->settings = $_settings;
        parent::__construct();
    }

    public function save_users() {
        $id = (int)($_POST['id'] ?? 0);
        $actor_id = (int)$this->settings->userdata('id');
        $is_admin = (int)$this->settings->userdata('type') === 1;
        if (!$actor_id || (!$is_admin && $id !== $actor_id)) {
            deny_request(403, 'You may only edit your own profile.');
        }

        $existing = null;
        if ($id > 0) {
            $existing = $this->queryPrepared('SELECT * FROM users WHERE id = ?', 'i', [$id])->fetch_assoc();
            if (!$existing) return 2;
        }
        $firstname = trim((string)($_POST['firstname'] ?? ''));
        $middlename = trim((string)($_POST['middlename'] ?? ($existing['middlename'] ?? '')));
        $lastname = trim((string)($_POST['lastname'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $type = $is_admin ? (int)($_POST['type'] ?? ($existing['type'] ?? 2)) : (int)$existing['type'];
        if ($firstname === '' || $lastname === '' || $username === '' || !in_array($type, [1, 2], true)
            || (!$existing && $password === '')) return 2;
        if (isset($_POST['oldpassword']) && (!$existing
            || !verify_user_password((string)$_POST['oldpassword'], $existing['password']))) return 4;

        $duplicates = $this->queryPrepared('SELECT id FROM users WHERE username = ? AND id != ?', 'si', [$username, $id]);
        if ($duplicates->num_rows > 0) return 3;
        // Do not let an administrator remove their own access mid-session.
        if ($id === $actor_id && $type !== (int)$existing['type']) return 2;

        $this->conn->begin_transaction();
        try {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $existing['password'];
            if ($existing) {
                $this->queryPrepared('UPDATE users SET firstname=?, middlename=?, lastname=?, username=?, type=?, password=? WHERE id=?',
                    'ssssisi', [$firstname, $middlename, $lastname, $username, $type, $hash, $id]);
            } else {
                $this->queryPrepared('INSERT INTO users (firstname, middlename, lastname, username, type, password) VALUES (?,?,?,?,?,?)',
                    'ssssis', [$firstname, $middlename, $lastname, $username, $type, $hash]);
                $id = $this->conn->insert_id;
            }
            $meta = $_POST['meta'] ?? null;
            if (is_array($meta)) {
                $this->queryPrepared('DELETE FROM user_meta WHERE user_id=?', 'i', [$id]);
                foreach ($meta as $field => $value) {
                    if (!is_scalar($value)) throw new InvalidArgumentException('Invalid user metadata.');
                    $this->queryPrepared('INSERT INTO user_meta (user_id, meta_field, meta_value) VALUES (?,?,?)',
                        'iss', [$id, $field, (string)$value]);
                }
            }
            $name = trim("$firstname $lastname");
            $this->update_search_index('users', $id, 'user', $name, $username, "$name $username", 0, null,
                "system/users/view&id=$id", "system/users/manage_user&id=$id");
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('User save failed: ' . $e->getMessage());
            return 2;
        }

        if ($id === $actor_id) {
            foreach (['firstname' => $firstname, 'middlename' => $middlename, 'lastname' => $lastname,
                'username' => $username, 'type' => $type] as $key => $value) {
                $_SESSION['userdata'][$key] = $value;
            }
        }
        $this->processAvatarUpload($id);
        $this->settings->set_flashdata('success', 'User details successfully saved.');
        return 1;
    }

    private function processAvatarUpload($id) {
        if (empty($_FILES['img']['tmp_name'])) return;
        $upload = $_FILES['img']['tmp_name'];
        $info = @getimagesize($upload);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || !function_exists('imagecreatetruecolor')) return;
        $source = $info[2] === IMAGETYPE_PNG ? imagecreatefrompng($upload) : imagecreatefromjpeg($upload);
        if (!$source) return;
        $target = imagecreatetruecolor(200, 200);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, 200, 200, $info[0], $info[1]);
        $path = 'uploads/avatar-' . $id . '.png';
        $saved = imagepng($target, base_app . $path);
        imagedestroy($source);
        imagedestroy($target);
        if ($saved) {
            $path .= '?v=' . time();
            $this->queryPrepared('UPDATE users SET avatar=? WHERE id=?', 'si', [$path, $id]);
            if ($id === (int)$this->settings->userdata('id')) $_SESSION['userdata']['avatar'] = $path;
        }
    }

    public function delete_users() {
        if ((int)$this->settings->userdata('type') !== 1) deny_request(403, 'Administrator access required.');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || $id === (int)$this->settings->userdata('id')) {
            return json_encode(['status' => 'failed', 'msg' => 'You cannot delete your own account.']);
        }
        $this->conn->begin_transaction();
        try {
            $this->queryPrepared('DELETE FROM user_meta WHERE user_id=?', 'i', [$id]);
            $this->queryPrepared('DELETE FROM users WHERE id=?', 'i', [$id]);
            $this->delete_from_index('users', $id);
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'Unable to delete this user.']);
        }
        $this->settings->set_flashdata('success', 'User successfully deleted.');
        return json_encode(['status' => 'success']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $users = new Users();
    switch ($_GET['f'] ?? '') {
        case 'save': echo $users->save_users(); break;
        case 'delete': echo $users->delete_users(); break;
        default: deny_request(400, 'Unknown user action.');
    }
}
