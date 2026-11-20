<?php
if (!$conn->query("SELECT version FROM erp_schema_versions WHERE version='003_friend_features'")->num_rows) {
    require __DIR__.'/legacy_dashboard.php';
    return;
}
require __DIR__.'/../modules/enterprise/dashboard/index.php';
