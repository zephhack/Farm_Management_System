<?php
/**
 * Module 12 - Insert a sales order header (draft, no items yet).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$customerId    = (int) post('customer_id', 0);
$destinationId = post('destination_id') ? (int) post('destination_id') : null;
$contractId    = post('contract_id') ? (int) post('contract_id') : null;
$orderDate     = post('order_date');
$requiredDate  = post('required_date') ?: null;
$transportCost = (float) post('transport_cost', 0);

$errors = [];

if ($customerId <= 0) {
    $errors[] = 'Choose a customer.';
}
if (!valid_date($orderDate)) {
    $errors[] = 'Enter a valid order date.';
} elseif ($orderDate > date('Y-m-d')) {
    $errors[] = 'The order date cannot be in the future.';
}
if ($requiredDate && !valid_date($requiredDate)) {
    $errors[] = 'Enter a valid required-by date.';
}
if ($transportCost < 0) {
    $errors[] = 'Transport cost cannot be negative.';
}

if ($errors) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect('order_form.php');
}

$orderNo = next_code('sales_orders', 'order_no', 'SO');

$stmt = db()->prepare(
    "INSERT INTO sales_orders
       (order_no, customer_id, destination_id, contract_id, order_date, required_date,
        transport_cost, status, created_by)
     VALUES
       (:order_no, :customer_id, :destination_id, :contract_id, :order_date, :required_date,
        :transport_cost, 'draft', :user_id)"
);

$stmt->execute([
    ':order_no'       => $orderNo,
    ':customer_id'    => $customerId,
    ':destination_id' => $destinationId,
    ':contract_id'    => $contractId,
    ':order_date'     => $orderDate,
    ':required_date'  => $requiredDate,
    ':transport_cost' => $transportCost,
    ':user_id'        => current_user()['user_id'],
]);

$orderId = (int) db()->lastInsertId();

flash('success', "Order $orderNo created. Add produce below.");
redirect("order_view.php?id=$orderId");
