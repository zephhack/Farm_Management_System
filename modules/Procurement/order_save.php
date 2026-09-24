<?php
/**
 * Module 14 - Insert a purchase order header (draft, no items yet).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$supplierId   = (int) post('supplier_id', 0);
$quotationId  = post('quotation_id') ? (int) post('quotation_id') : null;
$farmId       = (int) post('farm_id', 0);
$orderDate    = post('order_date');
$expectedDate = post('expected_date') ?: null;

$errors = [];

if ($supplierId <= 0) {
    $errors[] = 'Choose a supplier.';
}
if (!valid_date($orderDate)) {
    $errors[] = 'Enter a valid order date.';
} elseif ($orderDate > date('Y-m-d')) {
    $errors[] = 'The order date cannot be in the future.';
}
if ($expectedDate && !valid_date($expectedDate)) {
    $errors[] = 'Enter a valid expected delivery date.';
}
if ($farmId <= 0) {
    $farmId = (int) db()->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();
}

if ($errors) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect('order_form.php');
}

$poNo = next_code('purchase_orders', 'po_no', 'PO');

$stmt = db()->prepare(
    "INSERT INTO purchase_orders
       (po_no, supplier_id, quotation_id, farm_id, order_date, expected_date, status, requested_by)
     VALUES
       (:po_no, :supplier_id, :quotation_id, :farm_id, :order_date, :expected_date, 'draft', :user_id)"
);

$stmt->execute([
    ':po_no'         => $poNo,
    ':supplier_id'   => $supplierId,
    ':quotation_id'  => $quotationId,
    ':farm_id'       => $farmId,
    ':order_date'    => $orderDate,
    ':expected_date' => $expectedDate,
    ':user_id'       => current_user()['user_id'],
]);

$poId = (int) db()->lastInsertId();

flash('success', "Purchase order $poNo created. Add items below.");
redirect("order_view.php?id=$poId");
