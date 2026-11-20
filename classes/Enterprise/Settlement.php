<?php
namespace App\Enterprise;

final class Settlement {
    public static function sql(\mysqli $db,string $alias): string {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$alias)) throw new \InvalidArgumentException('Invalid SQL alias.');
        return $db->query("SHOW TABLES LIKE 'erp_credit_adjustments'")->num_rows
            ? 'COALESCE((SELECT ca.adjustment FROM erp_credit_adjustments ca WHERE ca.invoice_id='.$alias.'.id),0)' : '0';
    }
    public static function adjustment(\mysqli $db,int $invoice): string {
        if (!$db->query("SHOW TABLES LIKE 'erp_credit_adjustments'")->num_rows) return '0.00';
        $statement=$db->prepare('SELECT adjustment FROM erp_credit_adjustments WHERE invoice_id=?');$statement->execute([$invoice]);
        return $statement->get_result()->fetch_assoc()['adjustment']??'0.00';
    }
}
