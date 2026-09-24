<?php
/**
 * Module 12 - Remove a line item from a draft sales order.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$itemId  = (int) post('item_id', 0);
$orderId = (int) post('order_id', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT so.status FROM sales_order_items soi
           JOIN sales_orders so ON so.order_id = soi.order_id
          WHERE soi.item_id = :item_id AND soi.order_id = :order_id'
    );
    $stmt->execute([':item_id' => $itemId, ':order_id' => $orderId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That line item was not found.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('Items cannot be removed once the order is confirmed.');
    }

    $pdo->prepare('DELETE FROM sales_order_items WHERE item_id = :id')->execute([':id' => $itemId]);
    recalc_order_totals($orderId);

    $pdo->commit();
    flash('success', 'Item removed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$orderId");
