<?php
require_once('../../../../config.php');

// DataTables server-side processing for Purchases
$draw = (int)($_POST['draw'] ?? 0);
$row = max(0, (int)($_POST['start'] ?? 0));
$rowperpage = min(250, max(1, (int)($_POST['length'] ?? 25)));
$columnIndex = (int)($_POST['order'][0]['column'] ?? 0);
$columnSortOrder = strtolower((string)($_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$searchValue = is_string($_POST['search']['value'] ?? null) ? mb_substr($_POST['search']['value'], 0, 120) : '';

// Column mapping for sorting
$columns = array(
    0 => 'p.id',
    1 => 'p.date_created',
    2 => 'p.reference_code',
    3 => 's.display_name',
    4 => 'p.total_amount',
    5 => 'p.tax',
    6 => 'p.total_amount', // grand total logic handled in sql
    7 => 'total_paid',
    8 => 'outstanding',
    9 => 'status'
);

$searchQuery = " ";
if($searchValue != ''){
   $searchQuery = " AND (p.reference_code LIKE ? OR s.display_name LIKE ?) ";
   $searchParams = ["%$searchValue%", "%$searchValue%"];
} else {
    $searchParams = [];
}

// Total records
$totalRecords = $conn->query("SELECT COUNT(*) FROM transactions WHERE type='purchase'")->fetch_array()[0];

// Total records with filter
$stmt = $conn->prepare("SELECT COUNT(*) FROM transactions p INNER JOIN entity_list s ON p.entity_id = s.id AND s.entity_type = 'Supplier' WHERE p.type='purchase' $searchQuery");
if($searchValue != ''){
    $stmt->bind_param("ss", ...$searchParams);
}
$stmt->execute();
$totalRecordwithFilter = $stmt->get_result()->fetch_array()[0];

// Order by
$credit_adjustment=App\Enterprise\Settlement::sql($conn,'p');
$orderBy = "p.date_created $columnSortOrder, p.id $columnSortOrder";
if(isset($columns[$columnIndex])) {
    $col = $columns[$columnIndex];
    if($col == 'total_paid') $orderBy = "COALESCE(pay.total_paid, 0) + COALESCE(ret.total_returned, 0) $columnSortOrder";
    else if($col == 'outstanding') $orderBy = "(p.total_amount + COALESCE(p.tax,0)) - (COALESCE(pay.total_paid, 0) + COALESCE(ret.total_returned, 0)) + $credit_adjustment $columnSortOrder";
    else $orderBy = "$col $columnSortOrder";
}

// Fetch records
$lockedSql = $conn->query("SHOW TABLES LIKE 'gl_journals'")->num_rows ? "EXISTS (SELECT 1 FROM gl_journals g WHERE g.source_transaction_id=p.id AND g.status='posted')" : '0';
if ($conn->query("SHOW TABLES LIKE 'po_receipts'")->num_rows) $lockedSql .= ' OR EXISTS (SELECT 1 FROM po_receipts r WHERE r.transaction_id=p.id)';
$sql = "SELECT p.*, ($lockedSql) AS purchase_locked, s.display_name as vendor, COALESCE(pay.total_paid, 0) as total_paid, COALESCE(ret.total_returned, 0) - $credit_adjustment as total_returned
        FROM `transactions` p 
        INNER JOIN entity_list s ON p.entity_id = s.id AND s.entity_type = 'Supplier'
        LEFT JOIN (SELECT parent_id, SUM(total_amount) as total_paid FROM transactions WHERE type = 'payment' GROUP BY parent_id) pay ON p.id = pay.parent_id 
        LEFT JOIN (SELECT parent_id, SUM(total_amount + tax) as total_returned FROM transactions WHERE type = 'return' GROUP BY parent_id) ret ON p.id = ret.parent_id
        WHERE p.type = 'purchase' $searchQuery 
        ORDER BY $orderBy 
        LIMIT ?, ?";

$stmt = $conn->prepare($sql);
if($searchValue != ''){
    $params = array_merge($searchParams, [$row, $rowperpage]);
    $stmt->bind_param("ssii", ...$params);
} else {
    $stmt->bind_param("ii", $row, $rowperpage);
}
$stmt->execute();
$records = $stmt->get_result();

$data = array();
$i = $row + 1;
while ($row_data = $records->fetch_assoc()) {
    $total_paid = floatval($row_data['total_paid']);
    $total_returned = floatval($row_data['total_returned']);
    $total_amount = floatval($row_data['total_amount']);
    $vat_amount = floatval($row_data['tax'] ?? 0);
    $total_with_vat = $total_amount + $vat_amount;
    $outstanding = $total_with_vat - $total_paid - $total_returned;
    
    if($outstanding <= 0.01):
        $status = "Paid in Full";
        $badge_class = "badge-success";
        $is_fully_paid = true;
    elseif(($total_paid + $total_returned) > 0):
        $status = "Partially Paid";
        $badge_class = "badge-warning";
        $is_fully_paid = false;
    else:
        $status = "Outstanding";
        $badge_class = "badge-danger";
        $is_fully_paid = false;
    endif;

    $action = '<button type="button" class="btn btn-flat btn-default btn-sm dropdown-toggle dropdown-icon" data-toggle="dropdown">Action <span class="sr-only">Toggle Dropdown</span></button>
               <div class="dropdown-menu" role="menu">
                   <a class="dropdown-item view_data" href="javascript:void(0)" data-id="'.$row_data['id'].'"><span class="fa fa-eye text-dark"></span> View</a>
                   <div class="dropdown-divider"></div>';
    if (!$row_data['purchase_locked']) $action .= '<a class="dropdown-item" href="'.base_url.'admin?page=transactions/purchases/manage_purchase&id='.$row_data['id'].'"><span class="fa fa-edit text-primary"></span> Edit</a><div class="dropdown-divider"></div>';
    
    if(!$is_fully_paid) {
        $action .= '<a class="dropdown-item record_payment" href="javascript:void(0)" data-id="'.$row_data['id'].'" data-party-id="'.$row_data['entity_id'].'" data-type="2"><span class="fa fa-money-bill text-success"></span> Record Payment</a>
                    <div class="dropdown-divider"></div>';
    }

    $action .= '<a class="dropdown-item" href="'.base_url.'admin?page=transactions/returns/manage_return&from_purchase_id='.$row_data['id'].'"><span class="fa fa-undo text-secondary"></span> Return Items</a>';
    if (!$row_data['purchase_locked']) $action .= '<div class="dropdown-divider"></div><a class="dropdown-item delete_data" href="javascript:void(0)" data-id="'.$row_data['id'].'"><span class="fa fa-trash text-danger"></span> Delete</a>';
    $action .= '</div>';

    $data[] = array(
        "index" => $i++,
        "date" => date("d-m-Y", strtotime($row_data['transaction_date'])),
        "code" => htmlspecialchars($row_data['reference_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        "vendor" => htmlspecialchars($row_data['vendor'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        "sub_total" => '<span class="badge badge-light border">'.number_format($total_amount, 2).'</span>',
        "vat" => '<span class="badge badge-info">'.number_format($vat_amount, 2).'</span>',
        "total" => '<span class="badge badge-primary shadow-sm">'.number_format($total_with_vat, 2).'</span>',
        "paid" => '<span class="badge badge-success">'.number_format($total_paid + $total_returned, 2).'</span>',
        "outstanding" => '<span class="badge badge-danger">'.number_format(max(0, $outstanding), 2).'</span>',
        "status" => '<span class="badge '.$badge_class.'">'.$status.'</span>',
        "action" => $action
    );
}

$response = array(
  "draw" => intval($draw),
  "iTotalRecords" => $totalRecords,
  "iTotalDisplayRecords" => $totalRecordwithFilter,
  "aaData" => $data
);

echo json_encode($response);
