<?php
/**
 * Module 12 - Add a produce line to a draft sales order.
 * The batch is looked up from produce_batches (Module 15's table) directly,
 * so this works even before Module 15's own UI exists.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$orderId   = (int) post('order_id', 0);
$batchId   = (int) post('batch_id', 0);
$qty       = (float) post('qty', 0);
$unitPrice = (float) post('unit_price', 0);

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
        throw new RuntimeException('Items can only be added while the order is a draft.');
    }
    if ($qty <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($unitPrice <= 0) {
        throw new RuntimeException('Enter a unit price.');
    }

    $stmt = $pdo->prepare(
        'SELECT crop_id, grade, unit_id, current_qty FROM produce_batches WHERE batch_id = :id FOR UPDATE'
    );
    $stmt->execute([':id' => $batchId]);
    $batch = $stmt->fetch();

    if (!$batch) {
        throw new RuntimeException('That produce batch no longer exists.');
    }
    if ($qty > (float) $batch['current_qty']) {
        throw new RuntimeException(
            'Only ' . fmt_qty($batch['current_qty']) . ' is available in that batch.'
        );
    }

    $stmt = $pdo->prepare(
        'INSERT INTO sales_order_items (order_id, batch_id, crop_id, grade, qty, unit_id, unit_price)
         VALUES (:order_id, :batch_id, :crop_id, :grade, :qty, :unit_id, :unit_price)'
    );

    $stmt->execute([
        ':order_id'   => $orderId,
        ':batch_id'   => $batchId,
        ':crop_id'    => $batch['crop_id'],
        ':grade'      => $batch['grade'],
        ':qty'        => $qty,
        ':unit_id'    => $batch['unit_id'],
        ':unit_price' => $unitPrice,
    ]);

    recalc_order_totals($orderId);

    $pdo->commit();
    flash('success', 'Item added.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$orderId");
