<?php
namespace App\Enterprise;
require_once __DIR__.'/Service.php';

final class FiscalService extends Service {
    public function years(): array { $this->admin();return $this->rows('SELECT * FROM erp_fiscal_years ORDER BY start_date DESC'); }
    public function create(array $input): int {
        $this->admin();
        return $this->atomic(function () use ($input) {
            $name=$this->text($input['name']??'',100,true);$start=$this->date($input['start_date']??'');$end=$this->date($input['end_date']??'');
            if ($end<$start) throw new \InvalidArgumentException('Fiscal year end cannot precede its start.');
            if ($this->row('SELECT id FROM erp_fiscal_years WHERE start_date<=? AND end_date>=?',[$end,$start])) throw new \RuntimeException('Fiscal years cannot overlap.');
            $this->query('INSERT INTO erp_fiscal_years(name,start_date,end_date) VALUES (?,?,?)',[$name,$start,$end]);
            $id=(int)$this->db->insert_id;$this->audit('create_fiscal_year','fiscal_year',$id,['name'=>$name,'start'=>$start,'end'=>$end]);return $id;
        });
    }
    public function status(int $id,$version,bool $close,string $reason): void {
        $this->admin();
        $this->atomic(function () use ($id,$version,$close,$reason) {
            $year=$this->row('SELECT * FROM erp_fiscal_years WHERE id=? FOR UPDATE',[$id]);
            if (!$year) throw new \RuntimeException('Fiscal year not found.');
            $this->version($year,$version);$reason=$this->text($reason,800,true);
            if (($year['status']==='closed')===$close) throw new \RuntimeException('The fiscal year already has this status.');
            if ($close) {
                $periods=$this->rows('SELECT * FROM gl_periods WHERE start_date<=? AND end_date>=? ORDER BY start_date FOR UPDATE',[$year['end_date'],$year['start_date']]);
                $next=$year['start_date'];
                foreach ($periods as $period) {
                    if ($period['start_date']!==$next||$period['end_date']>$year['end_date']) throw new \RuntimeException('Accounting periods must cover the fiscal year exactly, without gaps or crossing its boundaries.');
                    $next=(new \DateTimeImmutable($period['end_date']))->modify('+1 day')->format('Y-m-d');
                }
                if ($next!==(new \DateTimeImmutable($year['end_date']))->modify('+1 day')->format('Y-m-d')) throw new \RuntimeException('Create accounting periods covering the complete fiscal year before closing.');
                if ($this->row("SELECT id FROM gl_journals WHERE status='draft' AND entry_date BETWEEN ? AND ? LIMIT 1",[$year['start_date'],$year['end_date']])) throw new \RuntimeException('Draft journals remain in this fiscal year.');
                if ($this->row("SELECT t.id FROM transactions t LEFT JOIN gl_journals j ON j.source_transaction_id=t.id AND j.status='posted' WHERE t.transaction_date BETWEEN ? AND ? AND j.id IS NULL LIMIT 1",[$year['start_date'],$year['end_date']])) throw new \RuntimeException('Unposted documents remain in this fiscal year.');
                foreach ($periods as $period) {
                    $this->query('INSERT INTO erp_fiscal_year_periods(fiscal_year_id,period_id,was_closed) VALUES (?,?,?)',[$id,$period['id'],$period['status']==='closed'?1:0]);
                    if ($period['status']==='open') $this->query("UPDATE gl_periods SET status='closed',closed_at=NOW(),closed_by=? WHERE id=?",[$this->user,$period['id']]);
                }
            } else {
                $this->query("UPDATE gl_periods p JOIN erp_fiscal_year_periods y ON y.period_id=p.id SET p.status='open',p.closed_at=NULL,p.closed_by=NULL WHERE y.fiscal_year_id=? AND y.was_closed=0",[$id]);
                $this->query('DELETE FROM erp_fiscal_year_periods WHERE fiscal_year_id=?',[$id]);
            }
            $this->query('UPDATE erp_fiscal_years SET status=?,closed_by=?,closed_at=?,version=version+1 WHERE id=?',[$close?'closed':'open',$close?$this->user:null,$close?date('Y-m-d H:i:s'):null,$id]);
            $this->audit($close?'close_fiscal_year':'reopen_fiscal_year','fiscal_year',$id,['reason'=>$reason,'start'=>$year['start_date'],'end'=>$year['end_date']]);
        });
    }
}
