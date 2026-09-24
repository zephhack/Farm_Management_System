<?php
/**
 * Module 12 -> 13 bridge.
 *
 * Raising an invoice does three things in one transaction:
 *   1. creates the invoice, copying the order's totals
 *   2. posts one income row per line item to financial_transactions,
 *      each tagged with the batch's own crop cycle (via its harvest) -
 *      so v_cycle_profitability adds this income to the right crop,
 *      not just to the farm as a whole
 *   3. updates the linked contract's delivered_qty, if any
 * The order is then locked at 'invoiced' so it can't be billed twice.
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

    $stmt = $pdo->prepare(
        "SELECT so.*, cu.payment_terms_days
           FROM sales_orders so
           JOIN customers cu ON cu.customer_id = so.customer_id
          WHERE so.order_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new RuntimeException('That order no longer exists.');
    }
    if (!in_array($order['status'], ['confirmed', 'partially_delivered', 'delivered'], true)) {
        throw new RuntimeException('This order cannot be invoiced in its current state.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = :id');
    $stmt->execute([':id' => $orderId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException('This order already has an invoice.');
    }

    if ((float) $order['total_amount'] <= 0) {
        throw new RuntimeException('Add produce to the order before invoicing.');
    }

    /* ---------- 1. Create the invoice ---------------------------------- */

    $invoiceNo = next_code('invoices', 'invoice_no', 'INV');
    $dueDate   = date('Y-m-d', strtotime($order['order_date'] . ' + ' . (int) $order['payment_terms_days'] . ' days'));

    $stmt = $pdo->prepare(
        "INSERT INTO invoices
           (invoice_no, order_id, customer_id, invoice_date, due_date,
            subtotal, tax_amount, discount, total_amount, created_by)
         VALUES
           (:no, :order_id, :customer_id, CURDATE(), :due_date,
            :subtotal, :tax, :discount, :total, :user_id)"
    );

    $stmt->execute([
        ':no'         => $invoiceNo,
        ':order_id'   => $orderId,
        ':customer_id' => $order['customer_id'],
        ':due_date'   => $dueDate,
        ':subtotal'   => $order['subtotal'],
        ':tax'        => $order['tax_amount'],
        ':discount'   => $order['discount'],
        ':total'      => $order['total_amount'],
        ':user_id'    => current_user()['user_id'],
    ]);

    $invoiceId = (int) $pdo->lastInsertId();

    /* ---------- 2. Post income per line, attributed to its crop cycle --- */

    $stmt = $pdo->prepare(
        "SELECT soi.line_total, soi.batch_id, c.crop_name, h.cycle_id,
                f.farm_id
           FROM sales_order_items soi
           JOIN crops c ON c.crop_id = soi.crop_id
      LEFT JOIN produce_batches pb ON pb.batch_id = soi.batch_id
      LEFT JOIN harvests h ON h.harvest_id = pb.harvest_id
      LEFT JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
      LEFT JOIN fields f ON f.field_id = cc.field_id
          WHERE soi.order_id = :id"
    );
    $stmt->execute([':id' => $orderId]);
    $lines = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT category_id FROM finance_categories
          WHERE category_name = 'Crop Sales' AND category_type = 'income' LIMIT 1"
    );
    $stmt->execute();
    $incomeCategoryId = $stmt->fetchColumn();

    if (!$incomeCategoryId) {
        throw new RuntimeException('The "Crop Sales" income category is missing from finance_categories.');
    }

    /* Fall back to the farm from the customer's order if a batch has no
       traceable cycle (e.g. produce bought in rather than harvested). */
    $farmId = $pdo->query('SELECT farm_id FROM farms ORDER BY farm_id LIMIT 1')->fetchColumn();

    foreach ($lines as $line) {
        $txnNo = next_code('financial_transactions', 'txn_no', 'TXN');

        $stmt = $pdo->prepare(
            "INSERT INTO financial_transactions
               (txn_no, farm_id, txn_type, category_id, txn_date, amount,
                description, source_type, source_id, cycle_id, batch_id, recorded_by)
             VALUES
               (:txn_no, :farm_id, 'income', :category_id, CURDATE(), :amount,
                :description, 'invoice', :invoice_id, :cycle_id, :batch_id, :user_id)"
        );

        $stmt->execute([
            ':txn_no'      => $txnNo,
            ':farm_id'     => $line['farm_id'] ?: $farmId,
            ':category_id' => $incomeCategoryId,
            ':amount'      => $line['line_total'],
            ':description' => $line['crop_name'] . ' sale, invoice ' . $invoiceNo,
            ':invoice_id'  => $invoiceId,
            ':cycle_id'    => $line['cycle_id'],
            ':batch_id'    => $line['batch_id'],
            ':user_id'     => current_user()['user_id'],
        ]);
    }

    /* ---------- 3. Update contract delivery, if this order is under one -- */

    if ($order['contract_id']) {
        $totalQty = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM sales_order_items WHERE order_id = :id');
        $totalQty->execute([':id' => $orderId]);

        $pdo->prepare(
            'UPDATE contracts SET delivered_qty = delivered_qty + :qty WHERE contract_id = :id'
        )->execute([':qty' => $totalQty->fetchColumn(), ':id' => $order['contract_id']]);

        $pdo->prepare(
            "UPDATE contracts SET status = 'fulfilled'
              WHERE contract_id = :id AND delivered_qty >= contracted_qty"
        )->execute([':id' => $order['contract_id']]);
    }

    /* ---------- 4. Lock the order --------------------------------------- */

    $pdo->prepare("UPDATE sales_orders SET status = 'invoiced' WHERE order_id = :id")
        ->execute([':id' => $orderId]);

    $pdo->commit();
    flash('success', "Invoice $invoiceNo raised for " . fmt_money($order['total_amount']) . '.');
    redirect("invoice_view.php?id=$invoiceId");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Could not generate the invoice: ' . $e->getMessage());
    redirect("order_view.php?id=$orderId");
}
