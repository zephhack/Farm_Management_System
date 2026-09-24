<?php
/**
 * Module 14 - Record a payment to a supplier.
 * Unlike Module 12's customer payments, there's no database trigger
 * keeping purchase_orders.amount_paid in sync, so this script updates it
 * directly inside the same transaction as the payment insert.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'accountant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

csrf_check();

$poId        = (int) post('po_id', 0);
$paymentDate = post('payment_date');
$amount      = (float) post('amount', 0);
$method      = post('method', 'bank_transfer');
$reference   = post('reference') ?: null;

$validMethods = ['cash', 'bank_transfer', 'mobile_money', 'cheque'];

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT supplier_id, total_amount, amount_paid FROM purchase_orders WHERE po_id = :id FOR UPDATE'
    );
    $stmt->execute([':id' => $poId]);
    $po = $stmt->fetch();

    if (!$po) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    if (!valid_date($paymentDate)) {
        throw new RuntimeException('Enter a valid payment date.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    $balance = (float) $po['total_amount'] - (float) $po['amount_paid'];
    if ($amount > $balance + 0.01) {
        throw new RuntimeException('Payment exceeds the outstanding balance of ' . fmt_money($balance) . '.');
    }
    if (!in_array($method, $validMethods, true)) {
        throw new RuntimeException('Choose a valid payment method.');
    }

    $voucherNo = next_code('supplier_payments', 'voucher_no', 'PV');

    $pdo->prepare(
        "INSERT INTO supplier_payments (voucher_no, supplier_id, po_id, payment_date, amount, method, reference, paid_by)
         VALUES (:voucher_no, :supplier_id, :po_id, :payment_date, :amount, :method, :reference, :user_id)"
    )->execute([
        ':voucher_no' => $voucherNo, ':supplier_id' => $po['supplier_id'], ':po_id' => $poId,
        ':payment_date' => $paymentDate, ':amount' => $amount, ':method' => $method,
        ':reference' => $reference, ':user_id' => current_user()['user_id'],
    ]);

    $pdo->prepare('UPDATE purchase_orders SET amount_paid = amount_paid + :amount WHERE po_id = :id')
        ->execute([':amount' => $amount, ':id' => $poId]);

    $pdo->commit();
    flash('success', "Payment $voucherNo of " . fmt_money($amount) . ' recorded.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
}

redirect("order_view.php?id=$poId");
