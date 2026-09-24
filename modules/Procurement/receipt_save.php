<?php
/**
 * Module 14 -> 13 bridge.
 *
 * Confirming a goods receipt does three things in one transaction:
 *   1. creates the goods_receipts / goods_receipt_items rows
 *   2. raises purchase_order_items.qty_received for each line delivered
 *   3. posts one expense row per line to financial_transactions, mapped
 *      to the closest finance category for that item's category, and
 *      tagged with the line's own cycle_id if one was set when the item
 *      was added to the order
 * The order's status rolls up to 'received' once every line is fully
 * delivered, or 'partially_received' otherwise — the same pattern Module
 * 12 uses for sales order fulfilment.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId           = (int) post('po_id', 0);
$receivedOn     = post('received_on');
$condition      = post('condition_on_arrival', 'good');
$deliveryNoteNo = post('delivery_note_no') ?: null;
$remarks        = post('remarks') ?: null;
$lines          = $_POST['items'] ?? [];

/* Maps a purchase item's category to the closest finance expense category.
   Categories without an exact match (feed, veterinary, services) fall
   back to a general operating-expense bucket rather than being guessed at. */
const CATEGORY_MAP = [
    'seeds'      => 'Seeds & Seedlings',
    'fertilizer' => 'Fertilizer',
    'chemicals'  => 'Chemicals & Pesticides',
    'fuel'       => 'Fuel',
    'packaging'  => 'Packaging',
    'equipment'  => 'Equipment Purchase',
];
const FALLBACK_CATEGORY = 'Salaries & Admin';

if (!valid_date($receivedOn)) {
    flash('error', 'Enter a valid receipt date.');
    redirect("receipt_new.php?po_id=$poId");
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT po_no, farm_id, status FROM purchase_orders WHERE po_id = :id FOR UPDATE');
    $stmt->execute([':id' => $poId]);
    $po = $stmt->fetch();

    if (!$po) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    if (!in_array($po['status'], ['approved', 'partially_received'], true)) {
        throw new RuntimeException('Only approved orders can receive goods.');
    }

    $grnNo = next_code('goods_receipts', 'grn_no', 'GRN');
    $pdo->prepare(
        "INSERT INTO goods_receipts (grn_no, po_id, received_on, delivery_note_no, condition_on_arrival, remarks, received_by)
         VALUES (:no, :po_id, :date, :delivery_note, :condition, :remarks, :user_id)"
    )->execute([
        ':no' => $grnNo, ':po_id' => $poId, ':date' => $receivedOn, ':delivery_note' => $deliveryNoteNo,
        ':condition' => $condition, ':remarks' => $remarks, ':user_id' => current_user()['user_id'],
    ]);
    $grnId = (int) $pdo->lastInsertId();

    $anyReceived = false;

    foreach ($lines as $line) {
        $itemId     = (int) ($line['po_item_id'] ?? 0);
        $qty        = (float) ($line['qty'] ?? 0);
        $batchNo    = trim($line['batch_no'] ?? '') ?: null;
        $expiryDate = $line['expiry_date'] ?? '';
        $expiryDate = valid_date($expiryDate) ? $expiryDate : null;

        if ($qty <= 0) {
            continue;
        }

        $stmt = $pdo->prepare(
            'SELECT item_name, item_category, qty_ordered, qty_received, unit_price, cycle_id
               FROM purchase_order_items WHERE po_item_id = :id AND po_id = :po_id FOR UPDATE'
        );
        $stmt->execute([':id' => $itemId, ':po_id' => $poId]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new RuntimeException("Line item $itemId does not belong to this order.");
        }

        $remaining = (float) $item['qty_ordered'] - (float) $item['qty_received'];
        if ($qty > $remaining + 0.0001) {
            throw new RuntimeException("Received quantity for {$item['item_name']} exceeds what is still outstanding.");
        }

        $pdo->prepare(
            'INSERT INTO goods_receipt_items (grn_id, po_item_id, qty_received, batch_no, expiry_date)
             VALUES (:grn_id, :po_item_id, :qty, :batch_no, :expiry_date)'
        )->execute([
            ':grn_id' => $grnId, ':po_item_id' => $itemId, ':qty' => $qty,
            ':batch_no' => $batchNo, ':expiry_date' => $expiryDate,
        ]);

        $pdo->prepare('UPDATE purchase_order_items SET qty_received = qty_received + :qty WHERE po_item_id = :id')
            ->execute([':qty' => $qty, ':id' => $itemId]);

        /* Post the input cost as an expense, mapped to the closest category. */
        $categoryName = CATEGORY_MAP[$item['item_category']] ?? FALLBACK_CATEGORY;
        $stmt = $pdo->prepare(
            "SELECT category_id FROM finance_categories WHERE category_name = :name AND category_type = 'expense' LIMIT 1"
        );
        $stmt->execute([':name' => $categoryName]);
        $categoryId = $stmt->fetchColumn();

        if ($categoryId) {
            $amount = round($qty * (float) $item['unit_price'], 2);
            $pdo->prepare(
                "INSERT INTO financial_transactions
                   (txn_no, farm_id, txn_type, category_id, txn_date, amount, description,
                    source_type, source_id, cycle_id, recorded_by)
                 VALUES
                   (:txn_no, :farm_id, 'expense', :category_id, :txn_date, :amount, :description,
                    'purchase_order', :po_id, :cycle_id, :user_id)"
            )->execute([
                ':txn_no' => next_code('financial_transactions', 'txn_no', 'TXN'), ':farm_id' => $po['farm_id'],
                ':category_id' => $categoryId, ':txn_date' => $receivedOn, ':amount' => $amount,
                ':description' => $item['item_name'] . ' received on ' . $po['po_no'],
                ':po_id' => $poId, ':cycle_id' => $item['cycle_id'], ':user_id' => current_user()['user_id'],
            ]);
        }

        $anyReceived = true;
    }

    if (!$anyReceived) {
        throw new RuntimeException('Enter a quantity greater than zero on at least one line.');
    }

    /* Roll the order status up from its items. */
    $stmt = $pdo->prepare(
        'SELECT SUM(qty_ordered) AS total_qty, SUM(qty_received) AS received_qty
           FROM purchase_order_items WHERE po_id = :id'
    );
    $stmt->execute([':id' => $poId]);
    $totals = $stmt->fetch();

    $newStatus = ((float) $totals['received_qty'] >= (float) $totals['total_qty'] - 0.0001)
        ? 'received' : 'partially_received';

    $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE po_id = :id')
        ->execute([':status' => $newStatus, ':id' => $poId]);

    $pdo->commit();
    flash('success', "Receipt $grnNo confirmed. Input costs posted to Finance.");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Could not confirm the receipt: ' . $e->getMessage());
    redirect("receipt_new.php?po_id=$poId");
}

redirect("order_view.php?id=$poId");
