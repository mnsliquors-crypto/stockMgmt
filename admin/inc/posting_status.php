<?php
// Source records stay readable after their accounting entry becomes immutable.
if (!empty($_GET['id']) && isset($resolved) && str_contains($resolved,'/modules/transactions/')
    && !str_contains($resolved,'/procurement/')
    && !str_contains($resolved,'/item_receipts/') && !str_contains($resolved,'/vendor_bills/')
    && $conn->query("SHOW TABLES LIKE 'gl_journals'")->num_rows) {
    $posting=$db->queryPrepared("SELECT id,reference FROM gl_journals WHERE source_transaction_id=? AND status='posted'",'i',[(int)$_GET['id']])->fetch_assoc();
    $goods_receipt = null;
    if(!empty($purchase_cycle_available))$goods_receipt=$db->queryPrepared('SELECT b.reference,b.order_id,o.reference AS order_reference FROM po_vendor_bills b JOIN po_orders o ON o.id=b.order_id WHERE b.transaction_id=?','i',[(int)$_GET['id']])->fetch_assoc();
    if ($conn->query("SHOW TABLES LIKE 'po_receipts'")->num_rows) {
        $goods_receipt = $goods_receipt ?: $db->queryPrepared('SELECT r.reference,r.order_id,o.reference AS order_reference FROM po_receipts r JOIN po_orders o ON o.id=r.order_id WHERE r.transaction_id=?', 'i', [(int)$_GET['id']])->fetch_assoc();
    }
    if ($goods_receipt) {
        echo '<div class="border rounded p-2 mb-3 d-flex align-items-center justify-content-between flex-wrap"><span><i class="fas fa-lock mr-2"></i>Goods Receipt '.htmlspecialchars($goods_receipt['reference'],ENT_QUOTES,'UTF-8').'</span><a href="'.base_url.'admin/?page=transactions/procurement&amp;id='.$goods_receipt['order_id'].'">'.htmlspecialchars($goods_receipt['order_reference'],ENT_QUOTES,'UTF-8').'</a></div>';
    }
    if ($posting) {
        echo '<div class="border border-success rounded p-2 mb-3 d-flex align-items-center justify-content-between"><span class="text-success"><i class="fas fa-lock mr-2"></i>Posted</span><a href="'.base_url.'admin/?page=finance/accounting&amp;id='.$posting['id'].'">'.htmlspecialchars($posting['reference'],ENT_QUOTES,'UTF-8').'</a></div>';
    }
    if (($posting || $goods_receipt) && str_starts_with(basename($resolved),'manage')) {
        echo '<script>$(function(){ $(".content form").find("input,select,textarea,button[type=submit]").prop("disabled",true); $(".content button[form]").prop("disabled",true); });</script>';
    }
}
