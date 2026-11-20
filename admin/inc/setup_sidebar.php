<?php
$setupSections = [
    'Users & Access' => [
        ['system/users', 'User Management', 'fas fa-users'],
        ...(!empty($enterprise_available) ? [['enterprise/roles', 'Access Roles', 'fas fa-user-shield']] : []),
        ...(getenv('FEATURE_CONTROLS_PREVIEW') === '1' && (str_ends_with(DB_NAME, '_test') || getenv('FEATURE_CONTROLS_ALLOW_MAIN_PREVIEW') === '1') ? [['enterprise/feature_controls', 'Feature Controls (Preview)', 'fas fa-sliders-h']] : []),
    ],
    'Company' => [
        ['system/settings', 'Company Information', 'fas fa-building'],
        ['transactions/opening_balances', 'Opening Balances', 'fas fa-balance-scale'],
        ['system/settings/ref_codes', 'Reference Codes', 'fas fa-hashtag'],
        ...(!empty($enterprise_available) ? [['enterprise/fiscal_years', 'Fiscal Years', 'fas fa-calendar-alt']] : []),
    ],
    'System' => [
        ...(!empty($enterprise_available) ? [['enterprise/health', 'System Integrity', 'fas fa-heartbeat']] : []),
        ['system/git_sync', 'Git Sync (Backup)', 'fas fa-database'],
    ],
];
$setupCurrent = $page;
if (str_starts_with($page, 'system/users/')) $setupCurrent = 'system/users';
?>
<aside class="setup-sidebar" aria-label="Setup navigation">
    <div class="setup-sidebar-title"><i class="fas fa-cog" aria-hidden="true"></i> Setup</div>
    <?php foreach ($setupSections as $section => $links): ?>
    <details class="setup-nav-group"<?php echo in_array($setupCurrent, array_column($links, 0), true) ? ' open' : '' ?>>
        <summary><?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?><i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
        <div class="setup-nav-links">
            <?php foreach ($links as [$route, $label, $icon]): ?>
            <a href="<?php echo base_url ?>admin/?page=<?php echo htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>"<?php echo $setupCurrent === $route ? ' class="active" aria-current="page"' : '' ?>><i class="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></a>
            <?php endforeach ?>
        </div>
    </details>
    <?php endforeach ?>
</aside>
