<?php
namespace App\Enterprise;
require_once __DIR__ . '/../Accounting/Money.php';
use App\Accounting\Money;

class Service {
    protected array $actor;
    public function __construct(protected \mysqli $db, protected int $user) {
        $this->actor = $this->row('SELECT id,username,type FROM users WHERE id=?', [$user]) ?? [];
        if (!in_array((int)($this->actor['type'] ?? 0), [1,2], true)) throw new \RuntimeException('An active user account is required.');
    }
    protected function query(string $sql, array $values = []): \mysqli_result|bool {
        $statement = $this->db->prepare($sql); $statement->execute($values); return $statement->get_result();
    }
    protected function row(string $sql, array $values = []): ?array { return $this->query($sql, $values)->fetch_assoc(); }
    protected function rows(string $sql, array $values = []): array { return $this->query($sql, $values)->fetch_all(MYSQLI_ASSOC); }
    protected function atomic(callable $work) {
        $this->db->begin_transaction();
        try {
            $this->query('SELECT id FROM erp_control WHERE id=1 FOR UPDATE');
            $result = $work(); $this->db->commit(); return $result;
        } catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }
    public function isAdmin(): bool { return (int)$this->actor['type'] === 1; }
    protected function admin(): void { if (!$this->isAdmin()) throw new \DomainException('Administrator access required.'); }
    protected function text($value, int $max = 1000, bool $required = false): string {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Invalid text value.');
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $max) throw new \InvalidArgumentException('Enter a value of at most ' . $max . ' characters.');
        return $value;
    }
    protected function id($value): int {
        if (!is_scalar($value) || !ctype_digit((string)$value) || strlen((string)$value) > 15 || (int)$value < 1) throw new \InvalidArgumentException('Invalid record ID.');
        return (int)$value;
    }
    protected function decimal($value, int $scale = 4, bool $positive = false): string {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Invalid numeric amount.');
        $result = Money::decimal($value, $scale);
        if (bccomp((string)$value, $result, 24) !== 0 || bccomp($result, '0', $scale) < 0 || ($positive && bccomp($result, '0', $scale) === 0)) throw new \InvalidArgumentException('Enter a ' . ($positive ? 'positive' : 'nonnegative') . ' amount with at most ' . $scale . ' decimal places.');
        return $result;
    }
    protected function date($value, bool $time = false): string {
        $value = str_replace('T', ' ', $this->text($value, 19, true));
        if ($time && strlen($value) === 16) $value .= ':00';
        $format = $time ? 'Y-m-d H:i:s' : 'Y-m-d';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$date || $date->format($format) !== $value) throw new \InvalidArgumentException('Enter a valid date' . ($time ? ' and time.' : '.'));
        return $value;
    }
    protected function key($value): string {
        $value = $this->text($value, 80, true);
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $value)) throw new \InvalidArgumentException('Invalid request key. Refresh the page.');
        return $value;
    }
    protected function version(array $row, $version): void {
        if ((string)$row['version'] !== (string)$version) throw new \UnexpectedValueException('This record changed. Refresh before saving.');
    }
    protected function audit(string $action, string $type, int $id, array $details = []): void {
        $details['actor_username'] = $this->actor['username'];
        $this->query('INSERT INTO erp_audit_events(actor_id,action,object_type,object_id,details) VALUES (?,?,?,?,?)', [$this->user, $action, $type, $id, json_encode($details, JSON_THROW_ON_ERROR)]);
    }
    protected function openDate(string $date): void {
        if (!$this->row("SELECT id FROM gl_periods WHERE status='open' AND ? BETWEEN start_date AND end_date", [$date])) throw new \RuntimeException('An open accounting period is required for this date.');
    }
}
