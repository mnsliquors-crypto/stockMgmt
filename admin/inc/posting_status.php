<?php
// Source records stay readable after their accounting entry becomes immutable.
if (!empty($_GET['id']) && isset($resolved) && str_contains($resolved,'/modules/transactions/')
    && $conn->query("SHOW TABLES LIKE 'gl_journals'")->num_rows) {
    $posting=$db->queryPrepared("SELECT id,reference FROM gl_journals WHERE source_transaction_id=? AND status='posted'",'i',[(int)$_GET['id']])->fetch_assoc();
    if ($posting) {
        echo '<div class="border border-success rounded p-2 mb-3 d-flex align-items-center justify-content-between"><span class="text-success"><i class="fas fa-lock mr-2"></i>Posted</span><a href="'.base_url.'admin/?page=finance/accounting&amp;id='.$posting['id'].'">'.htmlspecialchars($posting['reference'],ENT_QUOTES,'UTF-8').'</a></div>';
        if (str_starts_with(basename($resolved),'manage')) {
            echo '<script>$(function(){ $(".content form").find("input,select,textarea,button[type=submit]").prop("disabled",true); $(".content button[form]").prop("disabled",true); });</script>';
        }
    }
}
