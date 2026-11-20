<?php
namespace App\Enterprise;

// Only the platform-owned directory may decide which database a code resolves to.
final class RetailDirectory {
    public function __construct(private \mysqli $controlDb) {}

    public function resolve(string $code): ?array {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $code)) return null;
        $statement = $this->controlDb->prepare('SELECT id,code,name,database_name FROM platform_retailers WHERE code=? AND active=1');
        $statement->execute([$code]);
        $retailer = $statement->get_result()->fetch_assoc();
        if (!$retailer || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $retailer['database_name'])) return null;
        $controlName = $this->controlDb->query('SELECT DATABASE() AS name')->fetch_assoc()['name'] ?? null;
        if (!$controlName || strcasecmp($retailer['database_name'], $controlName) === 0) return null;
        $retailer['id'] = (int)$retailer['id'];
        return $retailer;
    }
}
