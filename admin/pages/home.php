<?php
if (!$conn->query("SELECT version FROM erp_schema_versions WHERE version='".ERP_V3_SCHEMA_VERSION."'")->num_rows) {
    require __DIR__.'/legacy_dashboard.php';
    return;
}
require __DIR__.'/../modules/enterprise/dashboard/index.php';
