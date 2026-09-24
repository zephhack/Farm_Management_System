<?php
/**
 * Module 12 - Cancel an order. Only before anything has physically moved
 * or been billed, since that's the point at which cancelling would need
 * to reverse stock and money instead of simply stopping.
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
    if (!in_array($status, ['draft', 'confirmed'], true)) {
        throw new RuntimeException('Orders that have been dispatched or invoiced cannot be cancelled here.');
    }

    $pdo->prepare("UPDATE sales_orders SET status = 'cancelled' WHERE order_id = :id")
        ->execute([':id' => $orderId]);

    $pdo->commit();
    flash('success', 'Order cancelled.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$orderId");
