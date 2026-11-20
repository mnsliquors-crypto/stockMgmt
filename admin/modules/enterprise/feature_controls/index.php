<?php
if (!isset($conn)) require_once __DIR__.'/../../../../config.php';
require_once base_app.'classes/Enterprise/FeaturePreview.php';
$featureService = App\Enterprise\requireFeaturePreview($conn, (int)$_settings->userdata('id'));
$featureState = $featureService->state();
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/feature-controls.css?v=3">
<section class="feature-controls" data-endpoint="<?php echo $escape(base_url.'classes/FeatureControls.php') ?>">
  <div class="feature-controls-heading"><div><h4>Feature Controls</h4><p class="text-muted mb-0">Single business access settings</p></div></div>
  <div class="alert alert-warning mt-3" role="status"><strong>Preview:</strong> these settings do not yet restrict application pages or API access. Do not use them for security.</div>
  <div id="feature-controls-message" role="alert" aria-live="polite"></div>
  <div class="feature-toolbar">
    <div class="btn-group btn-group-sm" role="group" aria-label="Control view">
      <button type="button" class="btn btn-primary" data-view="features" aria-pressed="true">Features</button>
      <button type="button" class="btn btn-outline-primary" data-view="roles" aria-pressed="false">Role Permissions</button>
    </div>
    <label class="sr-only" for="feature-search">Search features</label><input id="feature-search" type="search" class="form-control form-control-sm" placeholder="Search features">
    <button type="button" class="btn btn-sm btn-outline-primary" id="retail-roles"><i class="fas fa-store mr-1" aria-hidden="true"></i> Create retail roles</button>
    <button type="button" class="btn btn-sm btn-primary" id="feature-save"><i class="fas fa-save mr-1" aria-hidden="true"></i> Save changes</button>
  </div>
  <div id="feature-role-picker" class="feature-role-picker" hidden><label for="feature-role">Role</label><select id="feature-role" class="form-control form-control-sm"><?php foreach ($featureState['roles'] as $role): ?><option value="<?php echo (int)$role['id'] ?>" <?php echo (int)$role['id']===$featureState['default_role_id']?'selected':'' ?>><?php echo $escape($role['name'].($role['active']?'':' (inactive)')) ?></option><?php endforeach ?></select><span class="text-muted small">Module access remains the upper limit for each role.</span></div>
  <div id="feature-rows" class="feature-panels"></div>
  <p id="feature-empty" class="text-muted py-3" hidden>No matching features.</p>
</section>
<script type="application/json" id="feature-controls-state"><?php echo json_encode($featureState, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="<?php echo base_url ?>dist/js/feature-controls.js?v=3" defer></script>
