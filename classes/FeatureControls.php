<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Enterprise/FeaturePreview.php';
$service = App\Enterprise\requireFeaturePreview($conn, (int)$_settings->userdata('id'));
$action = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($action, ['save_flags', 'save_role_permissions', 'create_retail_roles'], true) && $method !== 'POST') deny_request(405, 'Use POST for changes.');
try {
    $data = match ($action) {
        'state' => $service->state(),
        'role' => $service->role((int)($_GET['role_id'] ?? 0)),
        'create_retail_roles' => (function () use ($service) { $result = $service->createRetailRoles(); return ['result'=>$result,'state'=>$service->state()]; })(),
        'save_flags' => (function () use ($service) { $service->saveFlags($_POST); return $service->state(); })(),
        'save_role_permissions' => (function () use ($service) { $service->saveRolePermissions($_POST); return $service->role((int)($_POST['role_id'] ?? 0)); })(),
        default => throw new InvalidArgumentException('Unknown feature-control action.')
    };
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'success', 'data'=>$data], JSON_THROW_ON_ERROR);
} catch (DomainException $error) { deny_request(403, $error->getMessage());
} catch (UnexpectedValueException $error) { deny_request(409, $error->getMessage());
} catch (InvalidArgumentException|RuntimeException $error) { deny_request(422, $error->getMessage());
} catch (Throwable $error) { error_log('Feature controls: '.$error->getMessage()); deny_request(500, 'The feature controls request could not be completed.'); }
