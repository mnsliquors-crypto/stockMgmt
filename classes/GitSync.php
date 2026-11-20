<?php
require_once __DIR__ . '/../config.php';

class GitSync extends DBConnection {
    private $settings;

    public function __construct() {
        global $_settings;
        $this->settings = $_settings;
        parent::__construct();
    }

    // Array commands bypass shell parsing, including for configured executable paths.
    protected function run_command($arguments, $input = null, $output = null, $environment = []) {
        $stderr = tmpfile();
        if ($stderr === false) return ['return' => 1, 'output' => 'Cannot create process log.'];
        $descriptors = [
            0 => $input === null ? ['pipe', 'r'] : ['file', $input, 'r'],
            1 => $output === null ? ['pipe', 'w'] : ['file', $output, 'w'],
            2 => $stderr,
        ];
        $process = proc_open($arguments, $descriptors, $pipes, base_app, array_merge(getenv(), $environment));
        if (!is_resource($process)) {
            fclose($stderr);
            return ['return' => 1, 'output' => 'Unable to start process.'];
        }
        if (isset($pipes[0])) fclose($pipes[0]);
        $text = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        if (isset($pipes[1])) fclose($pipes[1]);
        $status = proc_close($process);
        rewind($stderr);
        $text .= stream_get_contents($stderr);
        fclose($stderr);
        return ['return' => $status, 'output' => trim($text)];
    }

    private function mysql_binary($name) {
        $directory = $this->settings->info('mysql_bin');
        if (!$directory) return PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\' . $name . '.exe' : $name;
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private function mysql_arguments($binary) {
        return [$this->mysql_binary($binary), '--host=' . DB_SERVER, '--port=' . DB_PORT,
            '--user=' . DB_USERNAME, DB_NAME];
    }

    public function push_to_git() {
        $sql_file = base_app . 'database/sms_db.sql';
        $temporary = tempnam(dirname($sql_file), '.dump-');
        if ($temporary === false) return json_encode(['status' => 'failed', 'msg' => 'Cannot create backup file.']);
        $dump = $this->run_command($this->mysql_arguments('mysqldump'), null, $temporary, ['MYSQL_PWD' => DB_PASSWORD]);
        if ($dump['return'] !== 0 || !rename($temporary, $sql_file)) {
            if (is_file($temporary)) unlink($temporary);
            return json_encode(['status' => 'failed', 'msg' => 'Database export failed; the previous backup was preserved.']);
        }

        $git = $this->settings->info('git_path') ?: 'git';
        $results = [];
        $add = $this->run_command([$git, 'add', '.']);
        $results[] = $add;
        if ($add['return'] !== 0) return $this->failed($results);
        $diff = $this->run_command([$git, 'diff', '--cached', '--quiet']);
        if ($diff['return'] === 1) {
            $commit = $this->run_command([$git, 'commit', '-m', 'Auto-sync: ' . date('Y-m-d H:i:s')]);
            $results[] = $commit;
            if ($commit['return'] !== 0) return $this->failed($results);
        } elseif ($diff['return'] !== 0) {
            $results[] = $diff;
            return $this->failed($results);
        }
        $push = $this->run_command([$git, 'push', 'origin', 'main']);
        $results[] = $push;
        if ($push['return'] !== 0) return $this->failed($results);
        return json_encode(['status' => 'success', 'msg' => 'Push complete.', 'details' => $results]);
    }

    private function failed($results) {
        return json_encode(['status' => 'failed', 'msg' => 'Git sync failed.', 'details' => $results]);
    }

    public function pull_updates() {
        $git = $this->settings->info('git_path') ?: 'git';
        $result = $this->run_command([$git, 'pull', '--ff-only', 'origin', 'main']);
        return json_encode(['status' => $result['return'] === 0 ? 'success' : 'failed',
            'msg' => $result['output'], 'return' => $result['return']]);
    }

    public function restore_db() {
        $sql_file = base_app . 'database/sms_db.sql';
        if (!is_file($sql_file)) return json_encode(['status' => 'failed', 'msg' => 'Backup file not found.']);
        $result = $this->run_command($this->mysql_arguments('mysql'), $sql_file, null, ['MYSQL_PWD' => DB_PASSWORD]);
        return json_encode(['status' => $result['return'] === 0 ? 'success' : 'failed',
            'msg' => $result['return'] === 0 ? 'Database restored. Run database/migrate.php before using the app.' : 'Database restoration failed.']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $git_sync = new GitSync();
    switch ($_GET['f'] ?? '') {
        case 'push': echo $git_sync->push_to_git(); break;
        case 'pull': echo $git_sync->pull_updates(); break;
        case 'restore': echo $git_sync->restore_db(); break;
        default: deny_request(400, 'Unknown Git sync action.');
    }
}
