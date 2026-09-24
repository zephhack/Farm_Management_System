<?php
/**
 * Module 14 - Remove a line item from a draft purchase order.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$itemId = (int) post('item_id', 0);
$poId   = (int) post('po_id', 0);

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT po.status FROM purchase_order_items poi
           JOIN purchase_orders po ON po.po_id = poi.po_id
          WHERE poi.po_item_id = :item_id AND poi.po_id = :po_id'
    );
    $stmt->execute([':item_id' => $itemId, ':po_id' => $poId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That line item was not found.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('Items cannot be removed once the order is submitted.');
    }

    $pdo->prepare('DELETE FROM purchase_order_items WHERE po_item_id = :id')->execute([':id' => $itemId]);
    recalc_po_totals($poId);

    $pdo->commit();
    flash('success', 'Item removed.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$poId");
