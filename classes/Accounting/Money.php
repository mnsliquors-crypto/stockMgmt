<?php
namespace App\Accounting;

final class Money {
    public static function decimal($value, $scale = 2) {
        if (is_float($value)) {
            if (!is_finite($value)) throw new \InvalidArgumentException('Invalid amount.');
            $value = number_format($value, 8, '.', '');
        }
        $value = trim((string)$value);
        if (!preg_match('/^-?\d{1,14}(?:\.\d{1,24})?$/D', $value)) {
            throw new \InvalidArgumentException('Enter a valid amount with at most 14 integer digits.');
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        return bcadd($value, str_starts_with($value, '-') ? '-' . $half : $half, $scale);
    }

    public static function add($a, $b) { return bcadd($a, $b, 2); }
    public static function subtract($a, $b) { return bcsub($a, $b, 2); }
    public static function multiply($a, $b) {
        return self::decimal(bcmul(self::decimal($a, 4), self::decimal($b, 4), 8));
    }
    public static function compare($a, $b) { return bccomp($a, $b, 2); }
}
