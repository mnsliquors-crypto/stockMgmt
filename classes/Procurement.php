<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Procurement/OrderService.php';

use App\Procurement\OrderService;
use App\Procurement\PermissionException;
use App\Procurement\ConflictException;

$action = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
foreach (array_merge($_GET, $_POST) as $key => $value) {
    if (!in_array($key, ['lines','selected_items'], true) && !is_scalar($value)) deny_request(400, 'Invalid procurement request value.');
}
if (!in_array($action, ['order','replenishment','export'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') deny_request(405, 'Use POST for procurement changes.');
try {
    $service = new OrderService($conn, (int)$_settings->userdata('id'));
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    switch ($action) {
        case 'save_order': $data = ['id' => $service->save($_POST)]; break;
        case 'submit': case 'approve': case 'reject': case 'cancel': case 'close':
            $service->transition($id, $action, $_POST['version'] ?? '', (string)($_POST['reason'] ?? ''));
            $data = ['id' => $id]; break;
        case 'receive': $data = ['receipt_id' => $service->receive($_POST), 'id' => (int)$_POST['order_id']]; break;
        case 'save_policy': $service->savePolicy($_POST); $data = []; break;
        case 'reassign': $service->reassign($id, $_POST['version'] ?? '', (int)($_POST['approver_id'] ?? 0), (string)($_POST['reason'] ?? '')); $data = ['id' => $id]; break;
        case 'replenish': $data = ['id' => $service->createReplenishment($_POST)]; break;
        case 'order': $data = $service->order($id); break;
        case 'replenishment': case 'export':
            $data = $service->replenishment(empty($_GET['vendor_id']) ? null : (int)$_GET['vendor_id']);
            if ($action === 'export') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="replenishment_' . date('Y-m-d') . '.csv"');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Item','Supplier','Unit','On hand','Reorder level','On order','Draft / Pending','Projected','Suggested','Unit cost (NPR)']);
                foreach ($data as $row) {
                    $safe = static fn($value) => preg_match('/^\s*[=+@-]/u', (string)$value) ? "'" . $value : $value;
                    fputcsv($output, array_map($safe, [$row['name'],$row['vendor_name'] ?? '',$row['unit'],$row['quantity'],$row['reorder_level'],$row['on_order'],$row['planned'],$row['projected'],$row['suggested'],$row['cost']]));
                }
                fclose($output); exit;
            }
            break;
        default: deny_request(400, 'Unknown procurement action.');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'data' => $data], JSON_THROW_ON_ERROR);
} catch (PermissionException $error) {
    deny_request(403, $error->getMessage());
} catch (ConflictException $error) {
    deny_request(409, $error->getMessage());
} catch (mysqli_sql_exception $error) {
    if ($error->getCode() === 1062) deny_request(409, 'This supplier invoice or request has already been recorded.');
    if ($error->getCode() === 1644) deny_request(422, $error->getMessage());
    error_log('Procurement database error: ' . $error->getMessage());
    deny_request(500, 'The procurement operation could not be completed.');
} catch (InvalidArgumentException|RuntimeException $error) {
    deny_request(422, $error->getMessage());
} catch (Throwable $error) {
    error_log('Procurement error: ' . $error->getMessage());
    deny_request(500, 'The procurement operation could not be completed.');
}
