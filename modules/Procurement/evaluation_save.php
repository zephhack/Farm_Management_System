<?php
/**
 * Module 14 - Rate a supplier after a purchase order is received.
 * suppliers.rating is kept correct by the database trigger
 * (trg_evaluation_after_insert), so this script only ever inserts into
 * supplier_evaluations, never touches suppliers.rating directly.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId       = (int) post('po_id', 0);
$delivery   = (int) post('delivery_timeliness', 3);
$quality    = (int) post('quality_score', 3);
$price      = (int) post('price_competitiveness', 3);
$service    = (int) post('service_score', 3);
$comments   = post('comments') ?: null;

foreach (['delivery_timeliness' => $delivery, 'quality_score' => $quality, 'price_competitiveness' => $price, 'service_score' => $service] as $label => $value) {
    if ($value < 1 || $value > 5) {
        flash('error', ucfirst(str_replace('_', ' ', $label)) . ' must be between 1 and 5.');
        redirect("order_view.php?id=$poId");
    }
}

$stmt = db()->prepare('SELECT supplier_id FROM purchase_orders WHERE po_id = :id');
$stmt->execute([':id' => $poId]);
$supplierId = $stmt->fetchColumn();

if (!$supplierId) {
    flash('error', 'That purchase order no longer exists.');
    redirect('orders.php');
}

db()->prepare(
    "INSERT INTO supplier_evaluations
       (supplier_id, po_id, evaluated_on, delivery_timeliness, quality_score,
        price_competitiveness, service_score, comments, evaluated_by)
     VALUES
       (:supplier_id, :po_id, CURDATE(), :delivery, :quality, :price, :service, :comments, :user_id)"
)->execute([
    ':supplier_id' => $supplierId, ':po_id' => $poId, ':delivery' => $delivery, ':quality' => $quality,
    ':price' => $price, ':service' => $service, ':comments' => $comments, ':user_id' => current_user()['user_id'],
]);

flash('success', 'Supplier rated. Their overall rating has been updated.');
redirect("order_view.php?id=$poId");
