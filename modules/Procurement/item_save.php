<?php
/**
 * Module 14 - Add a line to a draft purchase order.
 * cycle_id is optional per line, so input costs can be attributed to a
 * specific crop cycle (feeding Module 13's cost-per-hectare) or left
 * general (farm overhead, equipment, etc).
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId       = (int) post('po_id', 0);
$itemName   = post('item_name');
$category   = post('item_category', 'other');
$qty        = (float) post('qty_ordered', 0);
$unitId     = (int) post('unit_id', 0);
$unitPrice  = (float) post('unit_price', 0);
$cycleId    = post('cycle_id') ? (int) post('cycle_id') : null;

$validCategories = ['seeds', 'fertilizer', 'chemicals', 'feed', 'veterinary', 'fuel', 'equipment', 'packaging', 'services', 'other'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE po_id = :id FOR UPDATE');
    $stmt->execute([':id' => $poId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    if ($status !== 'draft') {
        throw new RuntimeException('Items can only be added while the order is a draft.');
    }
    if ($itemName === '' || $itemName === null) {
        throw new RuntimeException('Enter the item name.');
    }
    if (!in_array($category, $validCategories, true)) {
        throw new RuntimeException('Choose a valid category.');
    }
    if ($qty <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($unitId <= 0) {
        throw new RuntimeException('Choose a unit.');
    }
    if ($unitPrice <= 0) {
        throw new RuntimeException('Enter a unit price.');
    }

    $pdo->prepare(
        "INSERT INTO purchase_order_items
           (po_id, item_name, item_category, qty_ordered, unit_id, unit_price, cycle_id)
         VALUES
           (:po_id, :name, :category, :qty, :unit_id, :price, :cycle_id)"
    )->execute([
        ':po_id' => $poId, ':name' => $itemName, ':category' => $category,
        ':qty' => $qty, ':unit_id' => $unitId, ':price' => $unitPrice, ':cycle_id' => $cycleId,
    ]);

    recalc_po_totals($poId);

    $pdo->commit();
    flash('success', 'Item added.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$poId");
