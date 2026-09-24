<?php
/**
 * Module 12 - Confirm a draft order once its items are set.
 * Confirming does not move stock; dispatch does that. Confirming only
 * locks the item list so a buyer's commitment can't quietly change
 * after they've agreed to it.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$orderId = (int) post('order_id', 0);
$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM sales_orders WHERE order_id = :id FOR UPDATE');
    $stmt->execute([':id' => $orderId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That order no longer exists.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('Only draft orders can be confirmed.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales_order_items WHERE order_id = :id');
    $stmt->execute([':id' => $orderId]);

    if ((int) $stmt->fetchColumn() === 0) {
        throw new RuntimeException('Add at least one produce line before confirming.');
    }

    $pdo->prepare("UPDATE sales_orders SET status = 'confirmed' WHERE order_id = :id")
        ->execute([':id' => $orderId]);

    $pdo->commit();
    flash('success', 'Order confirmed. It can now be dispatched.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$orderId");
